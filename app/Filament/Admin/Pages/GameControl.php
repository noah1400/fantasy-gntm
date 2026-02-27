<?php

namespace App\Filament\Admin\Pages;

use App\Enums\EpisodeStatus;
use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Enums\SeasonStatus;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Services\PhaseService;
use App\Services\ScoringService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class GameControl extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'Game Control';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.admin.pages.game-control';

    public ?int $selectedSeasonId = null;

    // Add Phase form
    public ?string $newPhaseType = null;

    public ?int $newPhaseTargetModelCount = 1;

    public ?int $newPhaseEligibleBelow = 2;

    // Quick Action forms
    public ?int $forceAssignUserId = null;

    public ?int $forceAssignModelId = null;

    public ?int $eliminatePlayerId = null;

    public function mount(): void
    {
        $season = Season::query()->where('status', SeasonStatus::Active)->latest()->first();
        $this->selectedSeasonId = $season?->id;
    }

    public function getSeasonProperty(): ?Season
    {
        return $this->selectedSeasonId ? Season::find($this->selectedSeasonId) : null;
    }

    public function getPlayerStatusProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        $scoringService = app(ScoringService::class);

        return $this->season->players()->wherePivot('is_eliminated', false)->get()->map(fn ($player) => [
            'user' => $player,
            'active_models' => PlayerModel::where('user_id', $player->id)->where('season_id', $this->season->id)->active()->with('topModel')->get(),
            'points' => $scoringService->getPlayerPoints($player, $this->season),
        ])->sortBy('points');
    }

    public function getFreeAgentsProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return app(SeasonService::class)->getFreeAgents($this->season);
    }

    public function getPhaseQueueProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return $this->season->gamePhases()
            ->whereIn('status', [GamePhaseStatus::Active, GamePhaseStatus::Pending])
            ->orderBy('position')
            ->get();
    }

    public function getCompletedPhasesProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return $this->season->gamePhases()
            ->whereIn('status', [GamePhaseStatus::Completed, GamePhaseStatus::Cancelled])
            ->latest('completed_at')
            ->limit(10)
            ->get();
    }

    public function addPhase(): void
    {
        if (! $this->season || ! $this->newPhaseType) {
            return;
        }

        $type = GamePhaseType::from($this->newPhaseType);
        $config = match ($type) {
            GamePhaseType::MandatoryDrop => ['target_model_count' => $this->newPhaseTargetModelCount],
            GamePhaseType::PickRound => ['eligible_below' => $this->newPhaseEligibleBelow],
            default => [],
        };

        $episode = $this->season->episodes()->where('status', EpisodeStatus::Ended)->latest('id')->first();

        app(PhaseService::class)->createPhase($this->season, $type, $config, $episode);

        Notification::make()->title("Phase added: {$type->getLabel()}")->success()->send();
        $this->newPhaseType = null;
    }

    public function closePhase(int $phaseId): void
    {
        $phase = GamePhase::findOrFail($phaseId);
        app(PhaseService::class)->closePhase($phase);
        Notification::make()->title('Phase closed.')->success()->send();
    }

    public function cancelPhase(int $phaseId): void
    {
        $phase = GamePhase::findOrFail($phaseId);
        app(PhaseService::class)->cancelPhase($phase);
        Notification::make()->title('Phase cancelled.')->success()->send();
    }

    public function forceAssign(): void
    {
        if (! $this->season || ! $this->forceAssignUserId || ! $this->forceAssignModelId) {
            Notification::make()->title('Select a player and model.')->warning()->send();

            return;
        }

        app(PhaseService::class)->createPhase(
            $this->season,
            GamePhaseType::ForceAssign,
            ['user_id' => $this->forceAssignUserId, 'top_model_id' => $this->forceAssignModelId],
        );

        Notification::make()->title('Model assigned.')->success()->send();
        $this->forceAssignUserId = null;
        $this->forceAssignModelId = null;
    }

    public function eliminatePlayer(): void
    {
        if (! $this->season || ! $this->eliminatePlayerId) {
            Notification::make()->title('Select a player.')->warning()->send();

            return;
        }

        app(PhaseService::class)->createPhase(
            $this->season,
            GamePhaseType::EliminatePlayer,
            ['user_id' => $this->eliminatePlayerId],
        );

        Notification::make()->title('Player eliminated.')->success()->send();
        $this->eliminatePlayerId = null;
    }
}
