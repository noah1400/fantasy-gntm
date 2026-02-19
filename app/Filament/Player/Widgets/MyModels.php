<?php

namespace App\Filament\Player\Widgets;

use App\Enums\SeasonStatus;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Services\ScoringService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class MyModels extends Widget
{
    protected string $view = 'filament.player.widgets.my-models';

    protected int|string|array $columnSpan = 'full';

    public function getMyModelsData(): Collection
    {
        $season = Season::query()
            ->whereIn('status', [SeasonStatus::Active, SeasonStatus::Draft])
            ->latest()
            ->first();

        if (! $season) {
            return collect();
        }

        $scoringService = app(ScoringService::class);

        return PlayerModel::query()
            ->where('user_id', auth()->id())
            ->where('season_id', $season->id)
            ->active()
            ->with('topModel')
            ->get()
            ->map(fn (PlayerModel $pm) => [
                'top_model' => $pm->topModel,
                'points' => $scoringService->getModelPoints($pm->topModel),
                'pick_type' => $pm->pick_type,
            ]);
    }
}
