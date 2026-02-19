<?php

namespace App\Filament\Player\Pages;

use App\Enums\GameEventType;
use App\Enums\SeasonStatus;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\Season;
use App\Models\TopModel;
use App\Services\GameStateService;
use App\Services\ScoringService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class PostEpisode extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $navigationLabel = 'Post-Episode Actions';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.player.pages.post-episode';

    public ?int $selectedPickModelId = null;

    public ?int $selectedDropModelId = null;

    public static function canAccess(): bool
    {
        $season = Season::query()->where('status', SeasonStatus::Active)->latest()->first();
        if (! $season) {
            return false;
        }

        $episode = $season->episodes()->latest('id')->first();
        if (! $episode) {
            return false;
        }

        $actions = app(GameStateService::class)->getRequiredPostEpisodeActions(
            $season,
            $episode
        );

        return collect($actions)->contains(fn ($a) => $a['user']->id === auth()->id());
    }

    public function getSeasonProperty(): ?Season
    {
        return Season::query()->where('status', SeasonStatus::Active)->latest()->first();
    }

    public function getEpisodeProperty(): ?Episode
    {
        return $this->season?->episodes()->latest('id')->first();
    }

    public function getMyActionProperty(): ?array
    {
        if (! $this->season || ! $this->episode) {
            return null;
        }

        $actions = app(GameStateService::class)->getRequiredPostEpisodeActions($this->season, $this->episode);

        return collect($actions)->first(fn ($a) => $a['user']->id === auth()->id());
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
        if (! $this->season) {
            return;
        }

        $topModel = TopModel::find($topModelId);

        try {
            app(GameStateService::class)->pickFreeAgent(auth()->user(), $this->season, $topModel, $this->episode);
            Notification::make()
                ->title("You picked {$topModel->name} as a free agent!")
                ->success()
                ->send();
            $this->notifySwapPhasePlayers();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function swapModel(): void
    {
        if (! $this->season || ! $this->selectedDropModelId || ! $this->selectedPickModelId) {
            Notification::make()->title('Select both models for the swap.')->warning()->send();

            return;
        }

        $dropModel = TopModel::find($this->selectedDropModelId);
        $pickModel = TopModel::find($this->selectedPickModelId);

        try {
            app(GameStateService::class)->swapModel(auth()->user(), $this->season, $dropModel, $pickModel, $this->episode);
            Notification::make()
                ->title("Swapped {$dropModel->name} for {$pickModel->name}!")
                ->success()
                ->send();
            $this->selectedDropModelId = null;
            $this->selectedPickModelId = null;
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function mandatoryDrop(int $topModelId): void
    {
        if (! $this->season) {
            return;
        }

        $topModel = TopModel::find($topModelId);

        try {
            app(GameStateService::class)->dropModel(auth()->user(), $this->season, $topModel, isMandatory: true, episode: $this->episode);
            Notification::make()
                ->title("Dropped {$topModel->name}.")
                ->success()
                ->send();
            $this->notifySwapPhasePlayers();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function skipSwap(): void
    {
        if (! $this->season) {
            return;
        }

        $episode = $this->season->episodes()->latest('id')->first();

        GameEvent::create([
            'season_id' => $this->season->id,
            'episode_id' => $episode->id,
            'type' => GameEventType::SwapSkipped,
            'payload' => [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
            ],
        ]);

        Notification::make()
            ->title('Swap skipped.')
            ->success()
            ->send();
    }

    public function getMyPointsProperty(): float
    {
        if (! $this->season) {
            return 0;
        }

        return app(ScoringService::class)->getPlayerPoints(auth()->user(), $this->season);
    }

    protected function notifySwapPhasePlayers(): void
    {
        if (! $this->season || ! $this->episode) {
            return;
        }

        $actions = app(GameStateService::class)->getRequiredPostEpisodeActions($this->season, $this->episode);
        $onlySwaps = collect($actions)->every(fn ($a) => $a['action'] === 'optional_swap');

        if ($onlySwaps && count($actions) > 0) {
            foreach ($actions as $action) {
                Notification::make()
                    ->title('You may swap a model')
                    ->body('Free agents are available. Head to Post-Episode Actions if you\'d like to swap.')
                    ->sendToDatabase($action['user']);
            }
        }
    }
}
