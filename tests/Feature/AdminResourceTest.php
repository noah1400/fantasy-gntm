<?php

use App\Filament\Admin\Resources\ActionResource\Pages\ListActions;
use App\Filament\Admin\Resources\EpisodeResource\Pages\ListEpisodes;
use App\Filament\Admin\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Admin\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Admin\Resources\SeasonResource\Pages\ListSeasons;
use App\Filament\Admin\Resources\TopModelResource\Pages\ListTopModels;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(
        Filament::getPanel('admin'),
    );
});

it('can list seasons', function () {
    Season::factory(3)->create();

    Livewire::test(ListSeasons::class)->assertSuccessful();
});

it('can create a season', function () {
    Livewire::test(CreateSeason::class)
        ->fillForm([
            'name' => 'GNTM 2026',
            'year' => 2026,
            'models_per_player' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('seasons', [
        'name' => 'GNTM 2026',
        'year' => 2026,
        'models_per_player' => 3,
    ]);
});

it('can edit a season', function () {
    $season = Season::factory()->create(['name' => 'Old Name']);

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])
        ->fillForm([
            'name' => 'New Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($season->fresh()->name)->toBe('New Name');
});

it('can list episodes', function () {
    Livewire::test(ListEpisodes::class)->assertSuccessful();
});

it('can list top models', function () {
    Livewire::test(ListTopModels::class)->assertSuccessful();
});

it('can list actions', function () {
    Livewire::test(ListActions::class)->assertSuccessful();
});

it('can list users', function () {
    Livewire::test(ListUsers::class)->assertSuccessful();
});

it('can add players to a season', function () {
    $season = Season::factory()->create();
    $players = User::factory(3)->create();

    Livewire::test(EditSeason::class, ['record' => $season->getRouteKey()])
        ->fillForm([
            'players' => $players->pluck('id')->toArray(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($season->players()->count())->toBe(3);
});

it('prevents non-admin from accessing admin panel', function () {
    $player = User::factory()->create(['is_admin' => false]);

    expect($player->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    expect($this->admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});
