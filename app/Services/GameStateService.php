<?php

namespace App\Services;

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Enums\PickType;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Notifications\Notification;

class GameStateService
{
    public function __construct(
        protected ScoringService $scoringService,
    ) {}

    public function endEpisode(Episode $episode, array $eliminatedModelIds = []): void
    {
        $episode->update([
            'status' => EpisodeStatus::Ended,
            'ended_at' => now(),
        ]);

        $season = $episode->season;

        foreach ($eliminatedModelIds as $modelId) {
            $topModel = TopModel::find($modelId);
            if ($topModel && ! $topModel->is_eliminated) {
                $topModel->update([
                    'is_eliminated' => true,
                    'eliminated_in_episode_id' => $episode->id,
                ]);

                PlayerModel::query()
                    ->where('top_model_id', $topModel->id)
                    ->where('season_id', $season->id)
                    ->active()
                    ->update(['dropped_at' => now()]);

                GameEvent::create([
                    'season_id' => $season->id,
                    'episode_id' => $episode->id,
                    'type' => GameEventType::Elimination,
                    'payload' => [
                        'top_model_id' => $topModel->id,
                        'top_model_name' => $topModel->name,
                    ],
                ]);
            }
        }

        $actions = $this->getRequiredPostEpisodeActions($season, $episode);
        foreach ($actions as $action) {
            $notification = match ($action['action']) {
                'free_agent_pick' => Notification::make()
                    ->title('Pick a free agent')
                    ->body('A model was eliminated. Head to Post-Episode Actions to pick a replacement.'),
                'mandatory_drop' => Notification::make()
                    ->title('You must drop a model')
                    ->body('No free agents available. Head to Post-Episode Actions to drop a model.'),
                'player_eliminated' => Notification::make()
                    ->title('You have been eliminated')
                    ->body('No models remaining and no free agents available.'),
                default => null,
            };

            if ($notification) {
                $notification->sendToDatabase($action['user']);
            }
        }
    }

    public function pickFreeAgent(User $user, Season $season, TopModel $topModel, ?Episode $episode = null): PlayerModel
    {
        if ($topModel->is_eliminated) {
            throw new \InvalidArgumentException('Cannot pick an eliminated model.');
        }

        $alreadyOwned = PlayerModel::query()
            ->where('top_model_id', $topModel->id)
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if ($alreadyOwned) {
            throw new \InvalidArgumentException('This model is already owned by a player.');
        }

        $playerModel = PlayerModel::create([
            'user_id' => $user->id,
            'top_model_id' => $topModel->id,
            'season_id' => $season->id,
            'picked_at' => now(),
            'pick_type' => PickType::FreeAgent,
        ]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'type' => GameEventType::FreeAgentPick,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'top_model_id' => $topModel->id,
                'top_model_name' => $topModel->name,
            ],
        ]);

        return $playerModel;
    }

    public function dropModel(User $user, Season $season, TopModel $topModel, bool $isMandatory = false, ?Episode $episode = null): void
    {
        $playerModel = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('top_model_id', $topModel->id)
            ->where('season_id', $season->id)
            ->active()
            ->firstOrFail();

        $playerModel->update(['dropped_at' => now()]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'type' => $isMandatory ? GameEventType::MandatoryDrop : GameEventType::ModelDrop,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'top_model_id' => $topModel->id,
                'top_model_name' => $topModel->name,
            ],
        ]);
    }

    public function swapModel(User $user, Season $season, TopModel $dropModel, TopModel $pickModel, ?Episode $episode = null): PlayerModel
    {
        if ($pickModel->is_eliminated) {
            throw new \InvalidArgumentException('Cannot pick an eliminated model.');
        }

        $alreadyOwned = PlayerModel::query()
            ->where('top_model_id', $pickModel->id)
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if ($alreadyOwned) {
            throw new \InvalidArgumentException('This model is already owned by a player.');
        }

        $this->dropModel($user, $season, $dropModel, episode: $episode);

        $playerModel = PlayerModel::create([
            'user_id' => $user->id,
            'top_model_id' => $pickModel->id,
            'season_id' => $season->id,
            'picked_at' => now(),
            'pick_type' => PickType::Swap,
        ]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'type' => GameEventType::ModelSwap,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'dropped_model_id' => $dropModel->id,
                'dropped_model_name' => $dropModel->name,
                'picked_model_id' => $pickModel->id,
                'picked_model_name' => $pickModel->name,
            ],
        ]);

        return $playerModel;
    }

    public function eliminatePlayer(User $user, Season $season, ?Episode $episode = null): void
    {
        $season->players()->updateExistingPivot($user->id, ['is_eliminated' => true]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'type' => GameEventType::PlayerEliminated,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
            ],
        ]);
    }

    /**
     * Get players who need to perform a post-episode action, ordered by least points first.
     *
     * Phase flow: elimination → free_agent_pick → mandatory_drop → optional_swap → player_eliminated
     *
     * @return array<int, array{user: User, action: string, reason: string}>
     */
    public function getRequiredPostEpisodeActions(Season $season, Episode $episode): array
    {
        $actions = [];
        $seasonService = app(SeasonService::class);
        $activePlayers = $season->players()->wherePivot('is_eliminated', false)->get();

        $eliminatedModels = TopModel::query()
            ->where('season_id', $season->id)
            ->where('eliminated_in_episode_id', $episode->id)
            ->pluck('id');

        $episodeEvents = GameEvent::query()
            ->where('season_id', $season->id)
            ->where('episode_id', $episode->id)
            ->get();

        $freeAgents = $seasonService->getFreeAgents($season);

        // Phase 1: Players who had models eliminated need free_agent_pick or mandatory_drop
        foreach ($activePlayers as $player) {
            if ($this->playerHasEvent($episodeEvents, $player, GameEventType::PlayerEliminated)) {
                continue;
            }

            $hadEliminated = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $season->id)
                ->whereIn('top_model_id', $eliminatedModels)
                ->exists();

            if (! $hadEliminated) {
                continue;
            }

            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $season->id)
                ->active()
                ->count();

            if ($this->playerHasEvent($episodeEvents, $player, GameEventType::FreeAgentPick)
                || $this->playerHasEvent($episodeEvents, $player, GameEventType::MandatoryDrop)) {
                continue;
            }

            if ($freeAgents->isNotEmpty()) {
                $actions[] = [
                    'user' => $player,
                    'action' => 'free_agent_pick',
                    'reason' => 'Model eliminated — pick a free agent.',
                ];

                continue;
            }

            // No free agents available
            if ($activeModelCount === 0) {
                $actions[] = [
                    'user' => $player,
                    'action' => 'player_eliminated',
                    'reason' => 'No models remaining and no free agents available.',
                ];

                continue;
            }

            $actions[] = [
                'user' => $player,
                'action' => 'mandatory_drop',
                'reason' => 'No free agents — you must drop a model.',
            ];
        }

        // Phase 2: After all mandatory drops complete, optional swaps for all players
        $pendingDrops = collect($actions)->where('action', 'mandatory_drop')->isNotEmpty();
        $pendingPicks = collect($actions)->where('action', 'free_agent_pick')->isNotEmpty();

        if (! $pendingDrops && ! $pendingPicks) {
            $currentFreeAgents = $seasonService->getFreeAgents($season);

            if ($currentFreeAgents->isNotEmpty()) {
                foreach ($activePlayers as $player) {
                    if ($this->playerHasEvent($episodeEvents, $player, GameEventType::PlayerEliminated)) {
                        continue;
                    }

                    if ($this->playerHasEvent($episodeEvents, $player, GameEventType::ModelSwap)
                        || $this->playerHasEvent($episodeEvents, $player, GameEventType::SwapSkipped)) {
                        continue;
                    }

                    $hasActiveModels = PlayerModel::query()
                        ->where('user_id', $player->id)
                        ->where('season_id', $season->id)
                        ->active()
                        ->exists();

                    if ($hasActiveModels) {
                        $actions[] = [
                            'user' => $player,
                            'action' => 'optional_swap',
                            'reason' => 'You may swap one model with a free agent.',
                        ];
                    }
                }
            }
        }

        usort($actions, function ($a, $b) use ($season) {
            return $this->scoringService->getPlayerPoints($a['user'], $season)
                <=> $this->scoringService->getPlayerPoints($b['user'], $season);
        });

        return $actions;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GameEvent>  $events
     */
    private function playerHasEvent(\Illuminate\Support\Collection $events, User $player, GameEventType $type): bool
    {
        return $events->contains(function (GameEvent $event) use ($player, $type) {
            return $event->type === $type
                && ($event->payload['user_id'] ?? null) === $player->id;
        });
    }
}
