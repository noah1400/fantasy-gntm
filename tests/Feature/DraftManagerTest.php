<?php

use App\Filament\Admin\Pages\DraftManager;
use App\Models\DraftOrder;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->season = Season::factory()->create(['status' => \App\Enums\SeasonStatus::Setup]);
    $this->players = User::factory(3)->create();
    $this->season->players()->attach($this->players->pluck('id'));
});

it('loads players from season into draft order', function () {
    Livewire::test(DraftManager::class)
        ->assertSet('draftOrderUserIds', $this->season->players()->pluck('users.id')->toArray());
});

it('loads existing draft order when present', function () {
    $ordered = $this->players->reverse()->values();
    foreach ($ordered as $i => $player) {
        DraftOrder::factory()->create([
            'season_id' => $this->season->id,
            'user_id' => $player->id,
            'position' => $i + 1,
        ]);
    }

    $component = Livewire::test(DraftManager::class);

    expect($component->get('draftOrderUserIds'))->toBe($ordered->pluck('id')->toArray());
});

it('can move a player up in draft order', function () {
    $ids = $this->players->pluck('id')->toArray();

    Livewire::test(DraftManager::class)
        ->set('draftOrderUserIds', $ids)
        ->call('moveDraftOrderUp', 1)
        ->assertSet('draftOrderUserIds', [$ids[1], $ids[0], $ids[2]]);

    $orders = $this->season->draftOrders()->orderBy('position')->pluck('user_id')->toArray();
    expect($orders)->toBe([$ids[1], $ids[0], $ids[2]]);
});

it('can move a player down in draft order', function () {
    $ids = $this->players->pluck('id')->toArray();

    Livewire::test(DraftManager::class)
        ->set('draftOrderUserIds', $ids)
        ->call('moveDraftOrderDown', 0)
        ->assertSet('draftOrderUserIds', [$ids[1], $ids[0], $ids[2]]);

    $orders = $this->season->draftOrders()->orderBy('position')->pluck('user_id')->toArray();
    expect($orders)->toBe([$ids[1], $ids[0], $ids[2]]);
});

it('does not move first player up', function () {
    $ids = $this->players->pluck('id')->toArray();

    Livewire::test(DraftManager::class)
        ->set('draftOrderUserIds', $ids)
        ->call('moveDraftOrderUp', 0)
        ->assertSet('draftOrderUserIds', $ids);
});

it('does not move last player down', function () {
    $ids = $this->players->pluck('id')->toArray();

    Livewire::test(DraftManager::class)
        ->set('draftOrderUserIds', $ids)
        ->call('moveDraftOrderDown', 2)
        ->assertSet('draftOrderUserIds', $ids);
});

it('can randomize draft order', function () {
    $ids = $this->players->pluck('id')->toArray();

    Livewire::test(DraftManager::class)
        ->set('draftOrderUserIds', $ids)
        ->call('randomizeDraftOrder');

    $orders = $this->season->draftOrders()->orderBy('position')->get();
    expect($orders)->toHaveCount(3);

    $savedIds = $orders->pluck('user_id')->toArray();
    expect($savedIds)->toEqualCanonicalizing($ids);
});

it('saves draft order to database', function () {
    $ids = $this->players->pluck('id')->toArray();

    Livewire::test(DraftManager::class)
        ->set('draftOrderUserIds', $ids)
        ->call('saveDraftOrder')
        ->assertNotified('Draft order saved.');

    $orders = $this->season->draftOrders()->orderBy('position')->get();
    expect($orders)->toHaveCount(3)
        ->and($orders[0]->user_id)->toBe($ids[0])
        ->and($orders[0]->position)->toBe(1)
        ->and($orders[1]->user_id)->toBe($ids[1])
        ->and($orders[1]->position)->toBe(2)
        ->and($orders[2]->user_id)->toBe($ids[2])
        ->and($orders[2]->position)->toBe(3);
});
