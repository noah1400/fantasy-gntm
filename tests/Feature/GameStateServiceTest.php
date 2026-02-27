<?php

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\GameStateService;

beforeEach(function () {
    $this->service = app(GameStateService::class);
    $this->season = Season::factory()->active()->create();
    $this->episode = Episode::factory()->active()->create(['season_id' => $this->season->id]);
});

it('ends an episode and marks it as ended', function () {
    $this->service->endEpisode($this->episode);

    $this->episode->refresh();
    expect($this->episode->status)->toBe(EpisodeStatus::Ended)
        ->and($this->episode->ended_at)->not->toBeNull();
});

it('eliminates models when ending an episode', function () {
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    $this->service->endEpisode($this->episode, [$model->id]);

    $model->refresh();
    expect($model->is_eliminated)->toBeTrue()
        ->and($model->eliminated_in_episode_id)->toBe($this->episode->id);

    expect(GameEvent::where('type', GameEventType::Elimination)->count())->toBe(1);
});

it('picks a free agent for a player', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    $playerModel = $this->service->pickFreeAgent($player, $this->season, $model);

    expect($playerModel->user_id)->toBe($player->id)
        ->and($playerModel->top_model_id)->toBe($model->id);

    expect(GameEvent::where('type', GameEventType::FreeAgentPick)->count())->toBe(1);
});

it('throws when picking an eliminated model as free agent', function () {
    $player = User::factory()->create();
    $model = TopModel::factory()->eliminated()->create(['season_id' => $this->season->id]);

    $this->service->pickFreeAgent($player, $this->season, $model);
})->throws(\InvalidArgumentException::class, 'eliminated');

it('throws when picking an already owned model', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->pickFreeAgent($player2, $this->season, $model);
})->throws(\InvalidArgumentException::class, 'already owned');

it('drops a model', function () {
    $player = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->dropModel($player, $this->season, $model, episode: $this->episode);

    expect(PlayerModel::where('user_id', $player->id)->active()->count())->toBe(0);
    expect(GameEvent::where('type', GameEventType::ModelDrop)->count())->toBe(1);
});

it('swaps a model', function () {
    $player = User::factory()->create();
    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    $newPm = $this->service->swapModel($player, $this->season, $dropModel, $pickModel, $this->episode);

    expect($newPm->top_model_id)->toBe($pickModel->id);
    expect(PlayerModel::where('user_id', $player->id)->active()->count())->toBe(1);
    expect(GameEvent::where('type', GameEventType::ModelSwap)->count())->toBe(1);
});

it('eliminates a player from the season', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $this->service->eliminatePlayer($player, $this->season);

    $pivot = $this->season->players()->where('user_id', $player->id)->first()->pivot;
    expect((bool) $pivot->is_eliminated)->toBeTrue();
    expect(GameEvent::where('type', GameEventType::PlayerEliminated)->count())->toBe(1);
});

it('endEpisode auto-drops PlayerModel records for eliminated TopModels', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->endEpisode($this->episode, [$model->id]);

    expect(PlayerModel::where('user_id', $player->id)->active()->count())->toBe(0);
    expect(PlayerModel::where('user_id', $player->id)->whereNotNull('dropped_after_episode_id')->count())->toBe(1);
});

it('swapModel throws when picking eliminated model', function () {
    $player = User::factory()->create();
    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->eliminated()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->swapModel($player, $this->season, $dropModel, $pickModel);
})->throws(\InvalidArgumentException::class, 'eliminated');

it('swapModel throws when picking already owned model', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $dropModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $dropModel->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $pickModel->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->swapModel($player1, $this->season, $dropModel, $pickModel);
})->throws(\InvalidArgumentException::class, 'already owned');
