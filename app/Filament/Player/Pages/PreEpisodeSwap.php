<?php

namespace App\Filament\Player\Pages;

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Enums\SeasonStatus;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Services\GameStateService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class PreEpisodeSwap extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Pre-Episode Swap';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.player.pages.pre-episode-swap';

    public ?int $selectedPickModelId = null;

    public ?int $selectedDropModelId = null;

    public static function canAccess(): bool
    {
        $season = Season::query()->where('status', SeasonStatus::Active)->latest()->first();
        if (! $season) {
            return false;
        }

        $hasActiveEpisode = $season->episodes()->where('status', EpisodeStatus::Active)->exists();
        if ($hasActiveEpisode) {
            return false;
        }

        $upcomingEpisode = $season->episodes()->where('status', EpisodeStatus::Upcoming)->first();
        if (! $upcomingEpisode) {
            return false;
        }

        $lastEndedEpisode = $season->episodes()->where('status', EpisodeStatus::Ended)->latest('id')->first();
        if (! $lastEndedEpisode) {
            return false;
        }

        // Block while post-episode actions are still pending
        $pendingActions = app(GameStateService::class)->getRequiredPostEpisodeActions($season, $lastEndedEpisode);
        if (! empty($pendingActions)) {
            return false;
        }

        $freeAgents = app(SeasonService::class)->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return false;
        }

        $isEliminated = $season->players()
            ->where('user_id', auth()->id())
            ->wherePivot('is_eliminated', true)
            ->exists();

        if ($isEliminated) {
            return false;
        }

        $hasActiveModels = PlayerModel::query()
            ->where('user_id', auth()->id())
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if (! $hasActiveModels) {
            return false;
        }

        $hasAlreadySwapped = GameEvent::query()
            ->where('season_id', $season->id)
            ->where('episode_id', $upcomingEpisode->id)
            ->where('type', GameEventType::PreEpisodeSwap)
            ->whereJsonContains('payload->user_id', auth()->id())
            ->exists();

        return ! $hasAlreadySwapped;
    }

    public function getSeasonProperty(): ?Season
    {
        return Season::query()->where('status', SeasonStatus::Active)->latest()->first();
    }

    public function getUpcomingEpisodeProperty(): ?Episode
    {
        return $this->season?->episodes()->where('status', EpisodeStatus::Upcoming)->first();
    }

    public function getLastEndedEpisodeProperty(): ?Episode
    {
        return $this->season?->episodes()->where('status', EpisodeStatus::Ended)->latest('id')->first();
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

    public function getHasAlreadySwappedProperty(): bool
    {
        if (! $this->season || ! $this->upcomingEpisode) {
            return false;
        }

        return GameEvent::query()
            ->where('season_id', $this->season->id)
            ->where('episode_id', $this->upcomingEpisode->id)
            ->where('type', GameEventType::PreEpisodeSwap)
            ->whereJsonContains('payload->user_id', auth()->id())
            ->exists();
    }

    public function swapModel(): void
    {
        if (! $this->season || ! $this->upcomingEpisode || ! $this->lastEndedEpisode) {
            return;
        }

        if (! $this->selectedDropModelId || ! $this->selectedPickModelId) {
            Notification::make()->title('Select both models for the swap.')->warning()->send();

            return;
        }

        $dropModel = TopModel::find($this->selectedDropModelId);
        $pickModel = TopModel::find($this->selectedPickModelId);

        try {
            app(GameStateService::class)->preEpisodeSwap(
                auth()->user(),
                $this->season,
                $dropModel,
                $pickModel,
                $this->upcomingEpisode,
                $this->lastEndedEpisode,
            );

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
}
