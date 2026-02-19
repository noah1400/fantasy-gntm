<?php

namespace App\Filament\Admin\Pages;

use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\DraftService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class DraftManager extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'Draft Manager';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.draft-manager';

    public ?int $selectedSeasonId = null;

    /** @var list<int> */
    public array $draftOrderUserIds = [];

    public function mount(): void
    {
        $season = Season::query()->latest()->first();
        $this->selectedSeasonId = $season?->id;
        $this->loadDraftOrder();
    }

    public function updatedSelectedSeasonId(): void
    {
        $this->loadDraftOrder();
    }

    protected function loadDraftOrder(): void
    {
        if (! $this->season) {
            $this->draftOrderUserIds = [];

            return;
        }

        $existing = $this->season->draftOrders()->orderBy('position')->pluck('user_id')->toArray();

        if (! empty($existing)) {
            $this->draftOrderUserIds = $existing;
        } else {
            $this->draftOrderUserIds = $this->season->players()->pluck('users.id')->toArray();
        }
    }

    public function getSeasonProperty(): ?Season
    {
        return $this->selectedSeasonId ? Season::with(['players', 'draftOrders.user', 'draftPicks.topModel', 'draftPicks.user'])->find($this->selectedSeasonId) : null;
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

    public function getIsDraftCompleteProperty(): bool
    {
        return $this->season ? $this->draftService->isDraftComplete($this->season) : false;
    }

    public function getDraftOrderPlayersProperty(): Collection
    {
        if (empty($this->draftOrderUserIds)) {
            return collect();
        }

        $users = User::whereIn('id', $this->draftOrderUserIds)->get()->keyBy('id');

        return collect($this->draftOrderUserIds)->map(fn (int $id) => $users->get($id))->filter();
    }

    public function randomizeDraftOrder(): void
    {
        shuffle($this->draftOrderUserIds);
        $this->saveDraftOrder();
    }

    public function moveDraftOrderUp(int $index): void
    {
        if ($index <= 0 || $index >= count($this->draftOrderUserIds)) {
            return;
        }

        [$this->draftOrderUserIds[$index - 1], $this->draftOrderUserIds[$index]] =
            [$this->draftOrderUserIds[$index], $this->draftOrderUserIds[$index - 1]];

        $this->saveDraftOrder();
    }

    public function moveDraftOrderDown(int $index): void
    {
        if ($index < 0 || $index >= count($this->draftOrderUserIds) - 1) {
            return;
        }

        [$this->draftOrderUserIds[$index], $this->draftOrderUserIds[$index + 1]] =
            [$this->draftOrderUserIds[$index + 1], $this->draftOrderUserIds[$index]];

        $this->saveDraftOrder();
    }

    public function saveDraftOrder(): void
    {
        if (! $this->season) {
            return;
        }

        $this->draftService->setDraftOrder($this->season, $this->draftOrderUserIds);

        Notification::make()->title('Draft order saved.')->success()->send();
    }

    public function startDraft(): void
    {
        if (! $this->season) {
            return;
        }

        try {
            app(SeasonService::class)->startDraft($this->season);
            Notification::make()->title('Draft started!')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function forcePick(int $topModelId): void
    {
        if (! $this->season || ! $this->currentDrafter) {
            return;
        }

        $topModel = TopModel::find($topModelId);

        try {
            $this->draftService->pickModel($this->season, $this->currentDrafter, $topModel);
            Notification::make()
                ->title("{$this->currentDrafter->name} picked {$topModel->name}")
                ->success()
                ->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function activateSeason(): void
    {
        if (! $this->season) {
            return;
        }

        try {
            app(SeasonService::class)->activateSeason($this->season);
            Notification::make()->title('Season activated!')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
