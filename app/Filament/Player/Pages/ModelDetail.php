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

    public TopModel $topModel;

    public function mount(string $topModel): void
    {
        $this->topModel = TopModel::query()->where('slug', $topModel)->firstOrFail();
    }

    public function getTitle(): string
    {
        return $this->topModel->name;
    }

    public function getTotalPoints(): float
    {
        return app(ScoringService::class)->getModelPoints($this->topModel);
    }

    public function getEpisodeBreakdown(): Collection
    {
        $season = $this->topModel->season;

        return $season->episodes()
            ->orderBy('number')
            ->get()
            ->map(function ($episode) {
                $logs = ActionLog::query()
                    ->where('top_model_id', $this->topModel->id)
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
