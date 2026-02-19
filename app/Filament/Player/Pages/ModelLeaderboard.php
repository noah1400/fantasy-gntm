<?php

namespace App\Filament\Player\Pages;

use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Services\ScoringService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ModelLeaderboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $navigationLabel = 'Model Leaderboard';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.player.pages.model-leaderboard';

    public function getLeaderboardData(): Collection
    {
        $season = Season::query()
            ->whereIn('status', [SeasonStatus::Active, SeasonStatus::Draft])
            ->latest()
            ->first();

        if (! $season) {
            return collect();
        }

        $scoringService = app(ScoringService::class);
        $leaderboard = $scoringService->getModelLeaderboard($season);

        $ownedModelIds = $season->playerModels()
            ->active()
            ->pluck('top_model_id', 'user_id');

        $playerModelMap = $season->playerModels()
            ->active()
            ->with('user')
            ->get()
            ->groupBy('top_model_id')
            ->map(fn ($pms) => $pms->first()->user);

        return $leaderboard->map(function ($entry) use ($playerModelMap) {
            $entry['owner'] = $playerModelMap->get($entry['top_model']->id);

            return $entry;
        });
    }
}
