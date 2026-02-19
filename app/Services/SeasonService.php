<?php

namespace App\Services;

use App\Enums\SeasonStatus;
use App\Models\Season;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class SeasonService
{
    public function startDraft(Season $season): void
    {
        if ($season->status !== SeasonStatus::Setup) {
            throw new \InvalidArgumentException('Season must be in setup status to start draft.');
        }

        if ($season->players()->count() === 0) {
            throw new \InvalidArgumentException('Season must have players to start draft.');
        }

        if ($season->draftOrders()->count() === 0) {
            throw new \InvalidArgumentException('Draft order must be set before starting draft.');
        }

        $season->update(['status' => SeasonStatus::Draft]);

        $firstDrafter = app(DraftService::class)->getCurrentDrafter($season);
        if ($firstDrafter) {
            Notification::make()
                ->title('The draft has started!')
                ->body('You have the first pick. Head to the Draft Room.')
                ->sendToDatabase($firstDrafter);
        }
    }

    public function activateSeason(Season $season): void
    {
        if ($season->status !== SeasonStatus::Draft) {
            throw new \InvalidArgumentException('Season must be in draft status to activate.');
        }

        $season->update(['status' => SeasonStatus::Active]);
    }

    public function completeSeason(Season $season): void
    {
        if ($season->status !== SeasonStatus::Active) {
            throw new \InvalidArgumentException('Season must be active to complete.');
        }

        $season->update(['status' => SeasonStatus::Completed]);
    }

    public function getFreeAgents(Season $season): Collection
    {
        $ownedModelIds = $season->playerModels()
            ->active()
            ->pluck('top_model_id');

        return $season->topModels()
            ->where('is_eliminated', false)
            ->whereNotIn('id', $ownedModelIds)
            ->orderBy('name')
            ->get();
    }

    public function getActivePlayersForSeason(Season $season): Collection
    {
        return $season->players()
            ->wherePivot('is_eliminated', false)
            ->get();
    }
}
