<?php

namespace App\Filament\Player\Pages;

use App\Enums\SeasonStatus;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\Season;
use App\Models\TopModel;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ModelLeaderboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $navigationLabel = 'Model Leaderboard';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.player.pages.model-leaderboard';

    public ?int $selectedSeasonId = null;

    public ?int $selectedEpisodeId = null;

    public ?int $selectedTopModelId = null;

    public function mount(): void
    {
        $this->selectedSeasonId = Season::query()
            ->whereIn('status', [SeasonStatus::Active, SeasonStatus::Draft])
            ->latest('id')
            ->value('id');

        if (! $this->selectedSeasonId) {
            $this->selectedSeasonId = Season::query()->latest('id')->value('id');
        }
    }

    public function updatedSelectedSeasonId(?int $seasonId): void
    {
        $this->selectedEpisodeId = null;
        $this->selectedTopModelId = null;
    }

    public function getSeasonsProperty(): Collection
    {
        return Season::query()
            ->latest('year')
            ->latest('id')
            ->get();
    }

    public function getSelectedSeasonProperty(): ?Season
    {
        if (! $this->selectedSeasonId) {
            return null;
        }

        return Season::query()->find($this->selectedSeasonId);
    }

    public function getEpisodesProperty(): Collection
    {
        if (! $this->selectedSeason) {
            return collect();
        }

        return $this->selectedSeason->episodes()
            ->orderBy('number')
            ->get();
    }

    public function getTopModelsProperty(): Collection
    {
        if (! $this->selectedSeason) {
            return collect();
        }

        return $this->selectedSeason->topModels()
            ->orderBy('name')
            ->get();
    }

    public function getSelectedEpisodeProperty(): ?Episode
    {
        if (! $this->selectedSeason || ! $this->selectedEpisodeId) {
            return null;
        }

        return $this->selectedSeason->episodes()
            ->where('id', $this->selectedEpisodeId)
            ->first();
    }

    public function getSelectedTopModelProperty(): ?TopModel
    {
        if (! $this->selectedSeason || ! $this->selectedTopModelId) {
            return null;
        }

        return $this->selectedSeason->topModels()
            ->where('id', $this->selectedTopModelId)
            ->first();
    }

    public function getLeaderboardData(): Collection
    {
        if (! $this->selectedSeason) {
            return collect();
        }

        $seasonId = $this->selectedSeason->id;

        $pointsByModel = ActionLog::query()
            ->selectRaw('action_logs.top_model_id, COALESCE(SUM(action_logs.count * actions.multiplier), 0) as points_total')
            ->join('actions', 'action_logs.action_id', '=', 'actions.id')
            ->where('actions.season_id', $seasonId)
            ->groupBy('action_logs.top_model_id')
            ->pluck('points_total', 'action_logs.top_model_id');

        $lastEpisodeId = ActionLog::query()
            ->join('actions', 'action_logs.action_id', '=', 'actions.id')
            ->where('actions.season_id', $seasonId)
            ->max('action_logs.episode_id');

        if (! $lastEpisodeId) {
            $lastEpisodeId = $this->selectedSeason->episodes()->latest('id')->value('id');
        }

        $lastEpisodePointsByModel = collect();

        if ($lastEpisodeId) {
            $lastEpisodePointsByModel = ActionLog::query()
                ->selectRaw('action_logs.top_model_id, COALESCE(SUM(action_logs.count * actions.multiplier), 0) as points_total')
                ->join('actions', 'action_logs.action_id', '=', 'actions.id')
                ->where('actions.season_id', $seasonId)
                ->where('action_logs.episode_id', $lastEpisodeId)
                ->groupBy('action_logs.top_model_id')
                ->pluck('points_total', 'action_logs.top_model_id');
        }

        $ownerByModelId = $this->selectedSeason->playerModels()
            ->active()
            ->with('user')
            ->get()
            ->groupBy('top_model_id')
            ->map(fn (Collection $playerModels) => $playerModels->first()?->user);

        return $this->selectedSeason->topModels()
            ->orderBy('name')
            ->get()
            ->map(function (TopModel $topModel) use ($pointsByModel, $lastEpisodePointsByModel, $ownerByModelId): array {
                return [
                    'top_model' => $topModel,
                    'owner' => $ownerByModelId->get($topModel->id),
                    'points' => (float) ($pointsByModel->get($topModel->id) ?? 0),
                    'last_episode_points' => (float) ($lastEpisodePointsByModel->get($topModel->id) ?? 0),
                ];
            })
            ->sortByDesc('points')
            ->values();
    }

    public function getEpisodeModelPointsProperty(): Collection
    {
        if (! $this->selectedSeason || ! $this->selectedEpisode || $this->selectedTopModel) {
            return collect();
        }

        $seasonId = $this->selectedSeason->id;
        $episodeId = $this->selectedEpisode->id;

        $totalsByModel = ActionLog::query()
            ->selectRaw('action_logs.top_model_id, COALESCE(SUM(action_logs.count * actions.multiplier), 0) as points_total')
            ->selectRaw('COALESCE(SUM(action_logs.count), 0) as action_count_total')
            ->join('actions', 'action_logs.action_id', '=', 'actions.id')
            ->where('actions.season_id', $seasonId)
            ->where('action_logs.episode_id', $episodeId)
            ->groupBy('action_logs.top_model_id')
            ->get()
            ->keyBy('top_model_id');

        return $this->selectedSeason->topModels()
            ->orderBy('name')
            ->get()
            ->map(function (TopModel $topModel) use ($totalsByModel): array {
                $totals = $totalsByModel->get($topModel->id);

                return [
                    'top_model' => $topModel,
                    'points' => (float) ($totals?->points_total ?? 0),
                    'action_count' => (int) ($totals?->action_count_total ?? 0),
                ];
            })
            ->sortByDesc('points')
            ->values();
    }

    public function getModelEpisodePointsProperty(): Collection
    {
        if (! $this->selectedSeason || ! $this->selectedTopModel || $this->selectedEpisode) {
            return collect();
        }

        $seasonId = $this->selectedSeason->id;
        $topModelId = $this->selectedTopModel->id;

        $totalsByEpisode = ActionLog::query()
            ->selectRaw('action_logs.episode_id, COALESCE(SUM(action_logs.count * actions.multiplier), 0) as points_total')
            ->selectRaw('COALESCE(SUM(action_logs.count), 0) as action_count_total')
            ->join('actions', 'action_logs.action_id', '=', 'actions.id')
            ->where('actions.season_id', $seasonId)
            ->where('action_logs.top_model_id', $topModelId)
            ->groupBy('action_logs.episode_id')
            ->get()
            ->keyBy('episode_id');

        return $this->selectedSeason->episodes()
            ->orderBy('number')
            ->get()
            ->map(function (Episode $episode) use ($totalsByEpisode): array {
                $totals = $totalsByEpisode->get($episode->id);

                return [
                    'episode' => $episode,
                    'points' => (float) ($totals?->points_total ?? 0),
                    'action_count' => (int) ($totals?->action_count_total ?? 0),
                ];
            });
    }

    public function getModelEpisodeActionBreakdownProperty(): Collection
    {
        if (! $this->selectedSeason || ! $this->selectedTopModel || ! $this->selectedEpisode) {
            return collect();
        }

        return ActionLog::query()
            ->where('top_model_id', $this->selectedTopModel->id)
            ->where('episode_id', $this->selectedEpisode->id)
            ->whereHas('action', function ($query): void {
                $query->where('season_id', $this->selectedSeason->id);
            })
            ->with('action')
            ->orderByDesc('count')
            ->get();
    }

    public function getModelEpisodeActionBreakdownTotalPointsProperty(): float
    {
        return (float) $this->modelEpisodeActionBreakdown
            ->sum(fn (ActionLog $log): float => $log->count * (float) $log->action->multiplier);
    }
}
