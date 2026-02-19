<?php

namespace App\Filament\Player\Widgets;

use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Services\ScoringService;
use Filament\Widgets\Widget;

class PlayerScoreboard extends Widget
{
    protected string $view = 'filament.player.widgets.player-scoreboard';

    protected int|string|array $columnSpan = 'full';

    public function getScoreboardData(): array
    {
        $season = Season::query()
            ->whereIn('status', [SeasonStatus::Active, SeasonStatus::Draft])
            ->latest()
            ->first();

        if (! $season) {
            return [];
        }

        return app(ScoringService::class)->getScoreboard($season)->toArray();
    }
}
