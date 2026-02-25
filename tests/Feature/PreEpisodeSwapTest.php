<?php

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Enums\PickType;
use App\Filament\Player\Pages\PreEpisodeSwap;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\GameStateService;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->service = app(GameStateService::class);
    $this->season = Season::factory()->active()->create();
    $this->endedEpisode = Episode::factory()->ended()->create([
        'season_id' => $this->season->id,
        'number' => '1',
    ]);
    $this->upcomingEpisode = Episode::factory()->create([
        'season_id' => $this->season->id,
        'number' => '2',
        'status' => EpisodeStatus::Upcoming,
    ]);
    $this->player = User::factory()->create();
    $this->season->players()->attach($this->player->id);
});

// --- Service-level tests ---

it('performs a pre-episode swap successfully', function () {
    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    $newPm = $this->service->preEpisodeSwap(
        $this->player,
        $this->season,
        $dropModel,
        $pickModel,
        $this->upcomingEpisode,
        $this->endedEpisode,
    );

    expect($newPm->top_model_id)->toBe($pickModel->id)
        ->and($newPm->pick_type)->toBe(PickType::PreEpisodeSwap)
        ->and($newPm->picked_in_episode_id)->toBe($this->endedEpisode->id);

    // Old model dropped after ended episode
    $droppedPm = PlayerModel::where('top_model_id', $dropModel->id)
        ->where('user_id', $this->player->id)
        ->first();
    expect($droppedPm->dropped_after_episode_id)->toBe($this->endedEpisode->id);

    // Game event created with upcoming episode
    expect(GameEvent::where('type', GameEventType::PreEpisodeSwap)
        ->where('episode_id', $this->upcomingEpisode->id)
        ->count())->toBe(1);
});

it('throws when player already used pre-episode swap', function () {
    $dropModel1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $dropModel2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel2->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->preEpisodeSwap(
        $this->player, $this->season, $dropModel1, $pickModel1,
        $this->upcomingEpisode, $this->endedEpisode,
    );

    $this->service->preEpisodeSwap(
        $this->player, $this->season, $dropModel2, $pickModel2,
        $this->upcomingEpisode, $this->endedEpisode,
    );
})->throws(\InvalidArgumentException::class, 'already used');

it('throws when picking an eliminated model in pre-episode swap', function () {
    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->eliminated()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->preEpisodeSwap(
        $this->player, $this->season, $dropModel, $pickModel,
        $this->upcomingEpisode, $this->endedEpisode,
    );
})->throws(\InvalidArgumentException::class, 'eliminated');

it('throws when picking an already owned model in pre-episode swap', function () {
    $otherPlayer = User::factory()->create();
    $this->season->players()->attach($otherPlayer->id);

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $otherPlayer->id,
        'top_model_id' => $pickModel->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->preEpisodeSwap(
        $this->player, $this->season, $dropModel, $pickModel,
        $this->upcomingEpisode, $this->endedEpisode,
    );
})->throws(\InvalidArgumentException::class, 'already owned');

it('throws when eliminated player tries pre-episode swap', function () {
    $this->season->players()->updateExistingPivot($this->player->id, ['is_eliminated' => true]);

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->preEpisodeSwap(
        $this->player, $this->season, $dropModel, $pickModel,
        $this->upcomingEpisode, $this->endedEpisode,
    );
})->throws(\InvalidArgumentException::class, 'Eliminated players');

// --- Page-level tests ---

it('can access pre-episode swap page when conditions are met', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $freeAgent = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    expect(PreEpisodeSwap::canAccess())->toBeTrue();
});

it('cannot access pre-episode swap page without upcoming episode', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    $this->upcomingEpisode->update(['status' => EpisodeStatus::Active]);

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    expect(PreEpisodeSwap::canAccess())->toBeFalse();
});

it('cannot access pre-episode swap page while an episode is active', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    // Make the ended episode active (aired but not ended)
    $this->endedEpisode->update(['status' => EpisodeStatus::Active, 'ended_at' => null]);

    // Create a new ended episode so the "at least 1 ended" check would pass without the active block
    Episode::factory()->ended()->create([
        'season_id' => $this->season->id,
        'number' => '0',
    ]);

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    expect(PreEpisodeSwap::canAccess())->toBeFalse();
});

it('cannot access pre-episode swap page without ended episode', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    // Revert ended episode to upcoming
    $this->endedEpisode->update(['status' => EpisodeStatus::Upcoming]);

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    expect(PreEpisodeSwap::canAccess())->toBeFalse();
});

it('cannot access pre-episode swap page when no free agents', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    // Only one model exists and player owns it — no free agents
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    expect(PreEpisodeSwap::canAccess())->toBeFalse();
});

it('cannot access pre-episode swap page after already swapping', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $anotherFreeAgent = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->preEpisodeSwap(
        $this->player, $this->season, $dropModel, $pickModel,
        $this->upcomingEpisode, $this->endedEpisode,
    );

    expect(PreEpisodeSwap::canAccess())->toBeFalse();
});

it('performs swap via livewire page', function () {
    $this->actingAs($this->player);
    Filament::setCurrentPanel(Filament::getPanel('player'));

    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    Livewire\Livewire::test(PreEpisodeSwap::class)
        ->set('selectedDropModelId', $dropModel->id)
        ->set('selectedPickModelId', $pickModel->id)
        ->call('swapModel')
        ->assertNotified();

    expect(PlayerModel::where('user_id', $this->player->id)->active()->first()->top_model_id)
        ->toBe($pickModel->id);

    expect(GameEvent::where('type', GameEventType::PreEpisodeSwap)->count())->toBe(1);
});
