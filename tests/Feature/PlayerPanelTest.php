<?php

use App\Filament\Player\Pages\PostEpisode;
use App\Models\Episode;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\GameStateService;
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

it('post-episode page is deprecated and always returns false', function () {
    $season = Season::factory()->active()->create();
    $this->season = $season;
    $season->players()->attach($this->player->id);

    $endedEpisode = Episode::factory()->ended()->create([
        'season_id' => $season->id,
        'number' => '1',
    ]);

    $model = TopModel::factory()->create(['season_id' => $season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $season->id,
    ]);

    app(GameStateService::class)->endEpisode($endedEpisode, [$model->id]);

    // PostEpisode is deprecated — actions are now managed by PhaseService
    expect(PostEpisode::canAccess())->toBeFalse();
});
