<?php

namespace App\Services;

use App\Enums\GameEventType;
use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Notifications\Notification;

class PhaseService
{
    public function __construct(
        protected ScoringService $scoringService,
        protected SeasonService $seasonService,
        protected GameStateService $gameStateService,
    ) {}

    public function createPhase(Season $season, GamePhaseType $type, array $config = [], ?Episode $episode = null): GamePhase
    {
        $maxPosition = $season->gamePhases()
            ->whereIn('status', [GamePhaseStatus::Pending, GamePhaseStatus::Active])
            ->max('position') ?? -1;

        $phase = GamePhase::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'type' => $type,
            'config' => $config,
            'position' => $maxPosition + 1,
            'status' => GamePhaseStatus::Pending,
        ]);

        if ($type->isInstant()) {
            $this->executeInstantPhase($phase);

            return $phase;
        }

        // Auto-activate if no active phase and there are active players
        $hasActivePlayers = $season->players()->wherePivot('is_eliminated', false)->exists();

        if ($hasActivePlayers && ! $this->getActivePhase($season)) {
            $this->activatePhase($phase);
        }

        return $phase;
    }

    public function getActivePhase(Season $season): ?GamePhase
    {
        return $season->gamePhases()->active()->first();
    }

    /**
     * Determine what action a player should take right now.
     *
     * @return array{action: string, phase: GamePhase, reason: string}|null
     */
    public function getPlayerAction(Season $season, User $user): ?array
    {
        $phase = $this->getActivePhase($season);
        if (! $phase) {
            return null;
        }

        $isEliminated = $season->players()
            ->where('user_id', $user->id)
            ->wherePivot('is_eliminated', true)
            ->exists();

        if ($isEliminated) {
            return null;
        }

        return match ($phase->type) {
            GamePhaseType::MandatoryDrop => $this->getMandatoryDropAction($phase, $season, $user),
            GamePhaseType::PickRound => $this->getPickRoundAction($phase, $season, $user),
            GamePhaseType::OptionalSwap => $this->getOptionalSwapAction($phase, $season, $user),
            GamePhaseType::TradingPhase => $this->getTradingPhaseAction($phase, $season, $user),
            default => null,
        };
    }

    public function checkPhaseCompletion(GamePhase $phase): void
    {
        if ($phase->status !== GamePhaseStatus::Active) {
            return;
        }

        $isComplete = match ($phase->type) {
            GamePhaseType::MandatoryDrop => $this->isMandatoryDropComplete($phase),
            GamePhaseType::PickRound => $this->isPickRoundComplete($phase),
            GamePhaseType::OptionalSwap => $this->isOptionalSwapComplete($phase),
            GamePhaseType::TradingPhase => false, // Admin closes manually
            default => true,
        };

        if ($isComplete) {
            $this->completePhase($phase);
        }
    }

    public function closePhase(GamePhase $phase): void
    {
        $this->completePhase($phase);
    }

    public function cancelPhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Cancelled,
        ]);
    }

    public function advanceQueue(Season $season): void
    {
        $next = $season->gamePhases()
            ->pending()
            ->orderBy('position')
            ->first();

        if ($next) {
            $this->activatePhase($next);
        }
    }

    public function reorderPhases(Season $season, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            GamePhase::where('id', $id)
                ->where('season_id', $season->id)
                ->where('status', GamePhaseStatus::Pending)
                ->update(['position' => $index]);
        }
    }

    // --- Private helpers ---

    private function activatePhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Active,
            'started_at' => now(),
        ]);

        // Check completion on activation for all phase types to handle vacuous cases
        // (e.g. PickRound where nobody is eligible, or OptionalSwap with no free agents)
        $this->checkPhaseCompletion($phase);

        if ($phase->fresh()->status === GamePhaseStatus::Active) {
            $this->notifyAffectedPlayers($phase);
        }
    }

    private function completePhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->advanceQueue($phase->season);
    }

    private function executeInstantPhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Active,
            'started_at' => now(),
        ]);

        match ($phase->type) {
            GamePhaseType::ForceAssign => $this->executeForceAssign($phase),
            GamePhaseType::EliminatePlayer => $this->executeEliminatePlayer($phase),
            GamePhaseType::SkipPlayer => $this->executeSkipPlayer($phase),
            GamePhaseType::Redistribute => $this->executeRedistribute($phase),
            default => null,
        };

        $this->completePhase($phase);
    }

    private function executeForceAssign(GamePhase $phase): void
    {
        $user = User::findOrFail($phase->config['user_id']);
        $topModel = TopModel::findOrFail($phase->config['top_model_id']);

        $this->gameStateService->pickFreeAgent(
            $user,
            $phase->season,
            $topModel,
            $phase->episode,
            $phase,
        );
    }

    private function executeEliminatePlayer(GamePhase $phase): void
    {
        $user = User::findOrFail($phase->config['user_id']);
        $this->gameStateService->eliminatePlayer($user, $phase->season, $phase->episode, $phase);
    }

    private function executeSkipPlayer(GamePhase $phase): void
    {
        $user = User::findOrFail($phase->config['user_id']);

        // Find the currently active non-instant phase to link the skip event to,
        // so completion checks on that phase see the skip
        $activePhase = $phase->season->gamePhases()
            ->where('status', GamePhaseStatus::Active)
            ->where('id', '!=', $phase->id)
            ->first();

        GameEvent::create([
            'season_id' => $phase->season_id,
            'episode_id' => $phase->episode_id,
            'game_phase_id' => $activePhase?->id ?? $phase->id,
            'type' => GameEventType::SwapSkipped,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'skipped_by_admin' => true,
            ],
        ]);

        // Re-check completion on the active phase since a player was skipped
        if ($activePhase) {
            $this->checkPhaseCompletion($activePhase);
        }
    }

    private function executeRedistribute(GamePhase $phase): void
    {
        // TODO: Implement redistribution strategies
    }

    // --- Action resolvers per phase type ---

    private function getMandatoryDropAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $targetCount = $phase->config['target_model_count'] ?? 1;

        $activeModelCount = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->count();

        if ($activeModelCount <= $targetCount) {
            return null;
        }

        return [
            'action' => 'mandatory_drop',
            'phase' => $phase,
            'reason' => "You must drop to {$targetCount} model(s).",
        ];
    }

    private function getPickRoundAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $eligibleBelow = $phase->config['eligible_below'] ?? 2;

        $activeModelCount = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->count();

        if ($activeModelCount >= $eligibleBelow) {
            return null;
        }

        // Check if this player already picked in this phase
        $alreadyPicked = GameEvent::query()
            ->where('game_phase_id', $phase->id)
            ->where('type', GameEventType::FreeAgentPick)
            ->whereJsonContains('payload->user_id', $user->id)
            ->exists();

        if ($alreadyPicked) {
            return null;
        }

        $freeAgents = $this->seasonService->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return null;
        }

        // Turn-based: check if it's this player's turn (lowest points first among eligible)
        $currentTurnUser = $this->getCurrentTurnPlayer($phase, $season);

        if (! $currentTurnUser || $currentTurnUser->id !== $user->id) {
            return [
                'action' => 'waiting',
                'phase' => $phase,
                'reason' => 'Waiting for other players to pick.',
            ];
        }

        return [
            'action' => 'free_agent_pick',
            'phase' => $phase,
            'reason' => 'Pick a free agent.',
        ];
    }

    private function getOptionalSwapAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $hasActiveModels = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if (! $hasActiveModels) {
            return null;
        }

        $alreadyActed = GameEvent::query()
            ->where('game_phase_id', $phase->id)
            ->whereIn('type', [GameEventType::ModelSwap, GameEventType::SwapSkipped])
            ->whereJsonContains('payload->user_id', $user->id)
            ->exists();

        if ($alreadyActed) {
            return null;
        }

        $freeAgents = $this->seasonService->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return null;
        }

        // Turn-based: check if it's this player's turn
        $currentTurnUser = $this->getCurrentTurnPlayerForSwap($phase, $season);

        if (! $currentTurnUser || $currentTurnUser->id !== $user->id) {
            return [
                'action' => 'waiting',
                'phase' => $phase,
                'reason' => 'Waiting for other players to swap.',
            ];
        }

        return [
            'action' => 'optional_swap',
            'phase' => $phase,
            'reason' => 'You may swap one model with a free agent.',
        ];
    }

    private function getTradingPhaseAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $hasActiveModels = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if (! $hasActiveModels) {
            return null;
        }

        $freeAgents = $this->seasonService->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return null;
        }

        return [
            'action' => 'trading_swap',
            'phase' => $phase,
            'reason' => 'Swap any of your models for a free agent.',
        ];
    }

    // --- Turn order helpers ---

    private function getCurrentTurnPlayer(GamePhase $phase, Season $season): ?User
    {
        $activePlayers = $season->players()->wherePivot('is_eliminated', false)->get();
        $eligibleBelow = $phase->config['eligible_below'] ?? 2;

        $candidates = [];
        foreach ($activePlayers as $player) {
            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $season->id)
                ->active()
                ->count();

            if ($activeModelCount >= $eligibleBelow) {
                continue;
            }

            $alreadyPicked = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->where('type', GameEventType::FreeAgentPick)
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if ($alreadyPicked) {
                continue;
            }

            $candidates[] = $player;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $this->scoringService->getPlayerPoints($a, $season)
            <=> $this->scoringService->getPlayerPoints($b, $season));

        return $candidates[0];
    }

    private function getCurrentTurnPlayerForSwap(GamePhase $phase, Season $season): ?User
    {
        $activePlayers = $season->players()->wherePivot('is_eliminated', false)->get();

        $candidates = [];
        foreach ($activePlayers as $player) {
            $hasActiveModels = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $season->id)
                ->active()
                ->exists();

            if (! $hasActiveModels) {
                continue;
            }

            $alreadyActed = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->whereIn('type', [GameEventType::ModelSwap, GameEventType::SwapSkipped])
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if ($alreadyActed) {
                continue;
            }

            $candidates[] = $player;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $this->scoringService->getPlayerPoints($a, $season)
            <=> $this->scoringService->getPlayerPoints($b, $season));

        return $candidates[0];
    }

    // --- Completion checks ---

    private function isMandatoryDropComplete(GamePhase $phase): bool
    {
        $targetCount = $phase->config['target_model_count'] ?? 1;
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();

        foreach ($activePlayers as $player) {
            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $phase->season_id)
                ->active()
                ->count();

            if ($activeModelCount > $targetCount) {
                return false;
            }
        }

        return true;
    }

    private function isPickRoundComplete(GamePhase $phase): bool
    {
        $eligibleBelow = $phase->config['eligible_below'] ?? 2;
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();
        $freeAgents = $this->seasonService->getFreeAgents($phase->season);

        if ($freeAgents->isEmpty()) {
            return true;
        }

        foreach ($activePlayers as $player) {
            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $phase->season_id)
                ->active()
                ->count();

            if ($activeModelCount >= $eligibleBelow) {
                continue;
            }

            $alreadyPicked = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->where('type', GameEventType::FreeAgentPick)
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if (! $alreadyPicked) {
                return false;
            }
        }

        // All eligible players have picked (or nobody was eligible)
        return true;
    }

    private function isOptionalSwapComplete(GamePhase $phase): bool
    {
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();
        $freeAgents = $this->seasonService->getFreeAgents($phase->season);

        if ($freeAgents->isEmpty()) {
            return true;
        }

        foreach ($activePlayers as $player) {
            $hasActiveModels = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $phase->season_id)
                ->active()
                ->exists();

            if (! $hasActiveModels) {
                continue;
            }

            $alreadyActed = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->whereIn('type', [GameEventType::ModelSwap, GameEventType::SwapSkipped])
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if (! $alreadyActed) {
                return false;
            }
        }

        return true;
    }

    // --- Notifications ---

    private function notifyAffectedPlayers(GamePhase $phase): void
    {
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();

        foreach ($activePlayers as $player) {
            $action = $this->getPlayerAction($phase->season, $player);
            if (! $action || $action['action'] === 'waiting') {
                continue;
            }

            $title = match ($action['action']) {
                'mandatory_drop' => 'You must drop a model',
                'free_agent_pick' => 'Pick a free agent',
                'optional_swap' => 'You may swap a model',
                'trading_swap' => 'Trading phase is open',
                default => 'Action required',
            };

            Notification::make()
                ->title($title)
                ->body($action['reason'])
                ->sendToDatabase($player);
        }
    }
}
