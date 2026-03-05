<?php

namespace App\Filament\Player\Pages;

use App\Models\ActionLog;
use App\Models\TopModel;
use App\Services\ScoringService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ModelDetail extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'models/{topModel}';

    protected string $view = 'filament.player.pages.model-detail';

    public TopModel $modelRecord;

    public function mount(string $topModel): void
    {
        $this->modelRecord = TopModel::query()->where('slug', $topModel)->firstOrFail();
    }

    public function getTitle(): string
    {
        return $this->modelRecord->name;
    }

    public function getTotalPoints(): float
    {
        return app(ScoringService::class)->getModelPoints($this->modelRecord);
    }

    public function getEpisodeBreakdown(): Collection
    {
        $season = $this->modelRecord->season;

        return $season->episodes()
            ->orderBy('number')
            ->get()
            ->map(function ($episode) {
                $logs = ActionLog::query()
                    ->where('top_model_id', $this->modelRecord->id)
                    ->where('episode_id', $episode->id)
                    ->with('action')
                    ->get();

                $points = $logs->sum(fn ($log) => $log->count * (float) $log->action->multiplier);

                return [
                    'episode' => $episode,
                    'logs' => $logs,
                    'points' => $points,
                ];
            });
    }
}
