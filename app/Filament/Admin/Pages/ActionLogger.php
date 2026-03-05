<?php

namespace App\Filament\Admin\Pages;

use App\Enums\EpisodeStatus;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\Season;
use App\Models\TopModel;
use App\Services\ScoringService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ActionLogger extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'Action Logger';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.action-logger';

    public ?int $selectedSeasonId = null;

    public ?int $selectedEpisodeId = null;

    public ?int $selectedTopModelId = null;

    /** @var array{action_id: int, top_model_id: int, episode_id: int}|null */
    public ?array $lastAction = null;

    public function mount(): void
    {
        $season = Season::query()->latest()->first();
        $this->selectedSeasonId = $season?->id;

        if ($season) {
            $episode = $season->episodes()->where('status', EpisodeStatus::Active)->first();
            $this->selectedEpisodeId = $episode?->id;
        }
    }

    public function getEpisodesProperty(): Collection
    {
        if (! $this->selectedSeasonId) {
            return collect();
        }

        return Episode::query()
            ->where('season_id', $this->selectedSeasonId)
            ->orderBy('number')
            ->get();
    }

    public function getTopModelsProperty(): Collection
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

    public function getActionsProperty(): Collection
    {
        if (! $this->selectedSeasonId) {
            return collect();
        }

        return Action::query()
            ->where('season_id', $this->selectedSeasonId)
            ->orderBy('name')
            ->get();
    }

    public function getTrackedActionLogsProperty(): Collection
    {
        if (! $this->selectedSeasonId) {
            return collect();
        }

        $query = ActionLog::query()
            ->whereHas('action', function ($actionQuery): void {
                $actionQuery->where('season_id', $this->selectedSeasonId);
            })
            ->with(['action', 'topModel', 'episode'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($this->selectedEpisodeId) {
            $query->where('episode_id', $this->selectedEpisodeId);
        }

        return $query->get();
    }

    public function selectModel(int $topModelId): void
    {
        $this->selectedTopModelId = $topModelId;
    }

    public function logAction(int $actionId): void
    {
        if (! $this->selectedTopModelId || ! $this->selectedEpisodeId) {
            Notification::make()
                ->title('Select a model and episode first')
                ->warning()
                ->send();

            return;
        }

        $action = Action::find($actionId);
        $topModel = TopModel::find($this->selectedTopModelId);
        $episode = Episode::find($this->selectedEpisodeId);

        app(ScoringService::class)->logAction($action, $topModel, $episode);

        $this->lastAction = [
            'action_id' => $actionId,
            'top_model_id' => $this->selectedTopModelId,
            'episode_id' => $this->selectedEpisodeId,
        ];

        Notification::make()
            ->title("{$action->name} logged for {$topModel->name}")
            ->success()
            ->send();

        $this->selectedTopModelId = null;
    }

    public function undoLastAction(): void
    {
        if (! $this->lastAction) {
            Notification::make()
                ->title('Nothing to undo')
                ->warning()
                ->send();

            return;
        }

        $action = Action::find($this->lastAction['action_id']);
        $topModel = TopModel::find($this->lastAction['top_model_id']);
        $episode = Episode::find($this->lastAction['episode_id']);

        app(ScoringService::class)->undoAction($action, $topModel, $episode);

        Notification::make()
            ->title("Undid {$action->name} for {$topModel->name}")
            ->info()
            ->send();

        $this->lastAction = null;
    }

    public function adjustTrackedActionCount(int $actionLogId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $actionLog = ActionLog::query()
            ->with(['action', 'topModel'])
            ->find($actionLogId);

        if (! $actionLog || ! $actionLog->action) {
            Notification::make()
                ->title('Action log not found')
                ->warning()
                ->send();

            return;
        }

        if ((int) $actionLog->action->season_id !== (int) $this->selectedSeasonId) {
            Notification::make()
                ->title('Action log does not belong to selected season')
                ->warning()
                ->send();

            return;
        }

        if ($this->selectedEpisodeId && $actionLog->episode_id !== $this->selectedEpisodeId) {
            Notification::make()
                ->title('Action log does not belong to selected episode')
                ->warning()
                ->send();

            return;
        }

        $updatedCount = $actionLog->count + $delta;

        if ($updatedCount <= 0) {
            $actionName = $actionLog->action->name;
            $modelName = $actionLog->topModel?->name ?? 'model';
            $actionLog->delete();

            Notification::make()
                ->title("Removed {$actionName} for {$modelName}")
                ->success()
                ->send();

            return;
        }

        $actionLog->update(['count' => $updatedCount]);

        $actionName = $actionLog->action->name;
        $modelName = $actionLog->topModel?->name ?? 'model';

        Notification::make()
            ->title("Updated {$actionName} for {$modelName} to {$updatedCount}")
            ->success()
            ->send();
    }
}
