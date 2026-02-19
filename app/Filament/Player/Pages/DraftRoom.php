<?php

namespace App\Filament\Player\Pages;

use App\Enums\SeasonStatus;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\DraftService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class DraftRoom extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?string $navigationLabel = 'Draft Room';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.player.pages.draft-room';

    public static function canAccess(): bool
    {
        return Season::query()->where('status', SeasonStatus::Draft)->exists();
    }

    public function getSeasonProperty(): ?Season
    {
        return Season::query()->where('status', SeasonStatus::Draft)->latest()->first();
    }

    public function getDraftServiceProperty(): DraftService
    {
        return app(DraftService::class);
    }

    public function getCurrentDrafterProperty(): ?User
    {
        return $this->season ? $this->draftService->getCurrentDrafter($this->season) : null;
    }

    public function getAvailableModelsProperty(): Collection
    {
        return $this->season ? $this->draftService->getAvailableModels($this->season) : collect();
    }

    public function getIsMyTurnProperty(): bool
    {
        return $this->currentDrafter?->id === auth()->id();
    }

    public function getIsDraftCompleteProperty(): bool
    {
        return $this->season ? $this->draftService->isDraftComplete($this->season) : false;
    }

    public function pickModel(int $topModelId): void
    {
        if (! $this->season || ! $this->isMyTurn) {
            Notification::make()->title('It is not your turn to pick.')->danger()->send();

            return;
        }

        $topModel = TopModel::find($topModelId);

        try {
            $this->draftService->pickModel($this->season, auth()->user(), $topModel);
            Notification::make()
                ->title("You picked {$topModel->name}!")
                ->success()
                ->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
