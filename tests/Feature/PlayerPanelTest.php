<?php

use App\Models\Season;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->player = User::factory()->create();
    $this->actingAs($this->player);
    Filament::setCurrentPanel(
        Filament::getPanel('player'),
    );
});

it('allows any authenticated user to access player panel', function () {
    expect($this->player->canAccessPanel(Filament::getPanel('player')))->toBeTrue();
});

it('can access player dashboard', function () {
    $this->get('/play')->assertSuccessful();
});

it('can access model leaderboard page', function () {
    Season::factory()->active()->create();

    $this->get('/play/model-leaderboard')->assertSuccessful();
});

it('shows draft room only during draft', function () {
    // No draft season - should redirect or 403
    $response = $this->get('/play/draft-room');
    expect($response->status())->toBeIn([403, 302]);

    // Create draft season
    Season::factory()->draft()->create();

    $this->get('/play/draft-room')->assertSuccessful();
});
