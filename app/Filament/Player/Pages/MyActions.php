<?php

namespace App\Filament\Player\Pages;

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Enums\SeasonStatus;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\Season;
use App\Models\TopModel;
use App\Services\GameStateService;
use App\Services\PhaseService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class MyActions extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'My Actions';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.player.pages.my-actions';

    public ?int $selectedPickModelId = null;

    public ?int $selectedDropModelId = null;

    public static function canAccess(): bool
    {
        $season = Season::query()->where('status', SeasonStatus::Active)->latest()->first();
        if (! $season) {
            return false;
        }

        $action = app(PhaseService::class)->getPlayerAction($season, auth()->user());

        return $action !== null;
    }

    public function getSeasonProperty(): ?Season
    {
        return Season::query()->where('status', SeasonStatus::Active)->latest()->first();
    }

    public function getMyActionProperty(): ?array
    {
        if (! $this->season) {
            return null;
        }

        return app(PhaseService::class)->getPlayerAction($this->season, auth()->user());
    }

    public function getFreeAgentsProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return app(SeasonService::class)->getFreeAgents($this->season);
    }

    public function getMyActiveModelsProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return auth()->user()->playerModels()
            ->forSeason($this->season)
            ->active()
            ->with('topModel')
            ->get();
    }

    public function pickFreeAgent(int $topModelId): void
    {
        if (! $this->season || ! $this->myAction) {
            return;
        }

        $topModel = TopModel::find($topModelId);
        $episode = $this->getLatestEndedEpisode();

        try {
            app(GameStateService::class)->pickFreeAgent(
                auth()->user(), $this->season, $topModel, $episode, $this->myAction['phase']
            );

            app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

            Notification::make()->title("You picked {$topModel->name}!")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function mandatoryDrop(int $topModelId): void
    {
        if (! $this->season || ! $this->myAction) {
            return;
        }

        $topModel = TopModel::find($topModelId);
        $episode = $this->getLatestEndedEpisode();

        try {
            app(GameStateService::class)->dropModel(
                auth()->user(), $this->season, $topModel, isMandatory: true, episode: $episode, phase: $this->myAction['phase']
            );

            app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

            Notification::make()->title("Dropped {$topModel->name}.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function swapModel(): void
    {
        if (! $this->season || ! $this->selectedDropModelId || ! $this->selectedPickModelId || ! $this->myAction) {
            Notification::make()->title('Select both models for the swap.')->warning()->send();

            return;
        }

        $dropModel = TopModel::find($this->selectedDropModelId);
        $pickModel = TopModel::find($this->selectedPickModelId);
        $episode = $this->getLatestEndedEpisode();

        try {
            app(GameStateService::class)->swapModel(
                auth()->user(), $this->season, $dropModel, $pickModel, $episode, $this->myAction['phase']
            );

            app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

            Notification::make()->title("Swapped {$dropModel->name} for {$pickModel->name}!")->success()->send();
            $this->selectedDropModelId = null;
            $this->selectedPickModelId = null;
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function skipSwap(): void
    {
        if (! $this->season || ! $this->myAction) {
            return;
        }

        GameEvent::create([
            'season_id' => $this->season->id,
            'episode_id' => $this->myAction['phase']->episode_id,
            'game_phase_id' => $this->myAction['phase']->id,
            'type' => GameEventType::SwapSkipped,
            'payload' => [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
            ],
        ]);

        app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

        Notification::make()->title('Swap skipped.')->success()->send();
    }

    private function getLatestEndedEpisode(): ?Episode
    {
        return $this->season?->episodes()
            ->where('status', EpisodeStatus::Ended)
            ->latest('ended_at')
            ->first();
    }
}
