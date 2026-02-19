<?php

namespace App\Filament\Player\Widgets;

use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Services\DraftService;
use Filament\Widgets\Widget;

class DraftStatus extends Widget
{
    protected string $view = 'filament.player.widgets.draft-status';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '5s';

    public static function canView(): bool
    {
        return Season::query()->where('status', SeasonStatus::Draft)->exists();
    }

    public function getDraftData(): array
    {
        $season = Season::query()->where('status', SeasonStatus::Draft)->latest()->first();

        if (! $season) {
            return ['active' => false];
        }

        $draftService = app(DraftService::class);

        return [
            'active' => true,
            'season' => $season,
            'currentDrafter' => $draftService->getCurrentDrafter($season),
            'isMyTurn' => $draftService->getCurrentDrafter($season)?->id === auth()->id(),
            'pickNumber' => $draftService->getCurrentPickNumber($season),
            'isDraftComplete' => $draftService->isDraftComplete($season),
        ];
    }
}
