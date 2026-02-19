<?php

namespace App\Filament\Admin\Pages;

use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\Season;
use App\Models\TopModel;
use App\Services\GameStateService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class EndEpisode extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedStopCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'End Episode';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.end-episode';

    public ?int $selectedSeasonId = null;

    public ?int $selectedEpisodeId = null;

    /** @var list<int> */
    public array $eliminatedModelIds = [];

    public function mount(): void
    {
        $season = Season::query()->latest()->first();
        $this->selectedSeasonId = $season?->id;

        if ($season) {
            $episode = $season->episodes()->where('status', EpisodeStatus::Active)->first();
            $this->selectedEpisodeId = $episode?->id;
        }
    }

    public function getActiveEpisodesProperty(): Collection
    {
        if (! $this->selectedSeasonId) {
            return collect();
        }

        return Episode::query()
            ->where('season_id', $this->selectedSeasonId)
            ->whereIn('status', [EpisodeStatus::Active, EpisodeStatus::Upcoming])
            ->orderBy('number')
            ->get();
    }

    public function getActiveModelsProperty(): Collection
    {
        if (! $this->selectedSeasonId) {
            return collect();
        }

        return TopModel::query()
            ->where('season_id', $this->selectedSeasonId)
            ->where('is_eliminated', false)
            ->orderBy('name')
            ->get();
    }

    public function toggleElimination(int $modelId): void
    {
        if (in_array($modelId, $this->eliminatedModelIds)) {
            $this->eliminatedModelIds = array_values(array_diff($this->eliminatedModelIds, [$modelId]));
        } else {
            $this->eliminatedModelIds[] = $modelId;
        }
    }

    public function confirmEndEpisode(): void
    {
        if (! $this->selectedEpisodeId) {
            Notification::make()->title('Select an episode first')->warning()->send();

            return;
        }

        $episode = Episode::find($this->selectedEpisodeId);

        app(GameStateService::class)->endEpisode($episode, $this->eliminatedModelIds);

        Notification::make()
            ->title("Episode {$episode->number} ended")
            ->body(count($this->eliminatedModelIds).' model(s) eliminated')
            ->success()
            ->send();

        $this->eliminatedModelIds = [];
        $this->selectedEpisodeId = null;
    }
}
