<?php

namespace App\Services;

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Enums\PickType;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;

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
                    ->update(['dropped_after_episode_id' => $episode->id]);

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
    }

    public function pickFreeAgent(User $user, Season $season, TopModel $topModel, ?Episode $episode = null, ?GamePhase $phase = null): PlayerModel
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
            'picked_in_episode_id' => $episode?->id,
            'pick_type' => PickType::FreeAgent,
        ]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'game_phase_id' => $phase?->id,
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

    public function dropModel(User $user, Season $season, TopModel $topModel, bool $isMandatory = false, ?Episode $episode = null, ?GamePhase $phase = null): void
    {
        $playerModel = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('top_model_id', $topModel->id)
            ->where('season_id', $season->id)
            ->active()
            ->firstOrFail();

        $playerModel->update(['dropped_after_episode_id' => $episode?->id]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'game_phase_id' => $phase?->id,
            'type' => $isMandatory ? GameEventType::MandatoryDrop : GameEventType::ModelDrop,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'top_model_id' => $topModel->id,
                'top_model_name' => $topModel->name,
            ],
        ]);
    }

    public function swapModel(User $user, Season $season, TopModel $dropModel, TopModel $pickModel, ?Episode $episode = null, ?GamePhase $phase = null): PlayerModel
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

        $this->dropModel($user, $season, $dropModel, episode: $episode, phase: $phase);

        $playerModel = PlayerModel::create([
            'user_id' => $user->id,
            'top_model_id' => $pickModel->id,
            'season_id' => $season->id,
            'picked_in_episode_id' => $episode?->id,
            'pick_type' => PickType::Swap,
        ]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'game_phase_id' => $phase?->id,
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

    public function eliminatePlayer(User $user, Season $season, ?Episode $episode = null, ?GamePhase $phase = null): void
    {
        $season->players()->updateExistingPivot($user->id, ['is_eliminated' => true]);

        GameEvent::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'game_phase_id' => $phase?->id,
            'type' => GameEventType::PlayerEliminated,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
            ],
        ]);
    }
}
