<?php

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\ScoringService;

beforeEach(function () {
    $this->service = app(ScoringService::class);
    $this->season = Season::factory()->active()->create();
    $this->episode = Episode::factory()->active()->create(['season_id' => $this->season->id]);
    $this->topModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $this->action = Action::factory()->create([
        'season_id' => $this->season->id,
        'multiplier' => 2.00,
    ]);
});

it('logs an action and creates an action log', function () {
    $log = $this->service->logAction($this->action, $this->topModel, $this->episode);

    expect($log->count)->toBe(1)
        ->and($log->action_id)->toBe($this->action->id)
        ->and($log->top_model_id)->toBe($this->topModel->id)
        ->and($log->episode_id)->toBe($this->episode->id);
});

it('increments count when logging same action again', function () {
    $this->service->logAction($this->action, $this->topModel, $this->episode);
    $log = $this->service->logAction($this->action, $this->topModel, $this->episode);

    expect($log->count)->toBe(2);
    expect(ActionLog::count())->toBe(1);
});

it('undoes an action by decrementing count', function () {
    $this->service->logAction($this->action, $this->topModel, $this->episode);
    $this->service->logAction($this->action, $this->topModel, $this->episode);

    $log = $this->service->undoAction($this->action, $this->topModel, $this->episode);

    expect($log->count)->toBe(1);
});

it('deletes action log when undoing last count', function () {
    $this->service->logAction($this->action, $this->topModel, $this->episode);
    $result = $this->service->undoAction($this->action, $this->topModel, $this->episode);

    expect($result)->toBeNull();
    expect(ActionLog::count())->toBe(0);
});

it('returns null when undoing non-existent action', function () {
    $result = $this->service->undoAction($this->action, $this->topModel, $this->episode);

    expect($result)->toBeNull();
});

it('calculates model points correctly', function () {
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 3,
    ]);

    $points = $this->service->getModelPoints($this->topModel);

    // 3 * 2.00 = 6.0
    expect($points)->toBe(6.0);
});

it('calculates model points for specific episode', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id]);

    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 3,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 2,
    ]);

    $points = $this->service->getModelPoints($this->topModel, $this->episode);

    expect($points)->toBe(6.0);
});

it('calculates player points from active models', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 5,
    ]);

    $points = $this->service->getPlayerPoints($player, $this->season);

    // 5 * 2.00 = 10.0
    expect($points)->toBe(10.0);
});

it('returns scoreboard sorted by points descending', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);

    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 2,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $model2->id,
        'episode_id' => $this->episode->id,
        'count' => 5,
    ]);

    $scoreboard = $this->service->getScoreboard($this->season);

    expect($scoreboard)->toHaveCount(2)
        ->and($scoreboard[0]['user']->id)->toBe($player2->id)
        ->and($scoreboard[0]['points'])->toBe(10.0)
        ->and($scoreboard[1]['user']->id)->toBe($player1->id)
        ->and($scoreboard[1]['points'])->toBe(4.0);
});

it('player points persist after model is dropped', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 5,
    ]);

    $pointsBefore = $this->service->getPlayerPoints($player, $this->season);
    expect($pointsBefore)->toBe(10.0);

    // Drop the model
    PlayerModel::query()
        ->where('user_id', $player->id)
        ->where('top_model_id', $this->topModel->id)
        ->update(['dropped_at' => now()]);

    $pointsAfter = $this->service->getPlayerPoints($player, $this->season);
    expect($pointsAfter)->toBe(10.0);
});
