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
    $this->episode = Episode::factory()->active()->create(['season_id' => $this->season->id, 'number' => 1]);
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
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);

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

it('calculates player points from draft-picked models across all episodes', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Draft pick: picked_in_episode_id = null → counts all episodes
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

it('only counts points for episodes after the pick episode', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);
    $episode3 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 3]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Picked after episode 1 → only episodes 2+ count
    PlayerModel::factory()->pickedIn($this->episode)->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    // Episode 1 points (should NOT count)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 3,
    ]);
    // Episode 2 points (should count)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 2,
    ]);
    // Episode 3 points (should count)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode3->id,
        'count' => 1,
    ]);

    $points = $this->service->getPlayerPoints($player, $this->season);

    // Only episodes 2 and 3: (2 + 1) * 2.00 = 6.0
    expect($points)->toBe(6.0);
});

it('only counts points up to the dropped episode', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);
    $episode3 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 3]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Draft pick, dropped after episode 2 → only episodes 1–2 count
    PlayerModel::factory()->dropped($episode2)->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    // Episode 1 points (should count)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 3,
    ]);
    // Episode 2 points (should count)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 2,
    ]);
    // Episode 3 points (should NOT count — dropped after episode 2)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode3->id,
        'count' => 4,
    ]);

    $points = $this->service->getPlayerPoints($player, $this->season);

    // Only episodes 1 and 2: (3 + 2) * 2.00 = 10.0
    expect($points)->toBe(10.0);
});

it('scopes points correctly for mid-season pick and drop', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);
    $episode3 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 3]);
    $episode4 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 4]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Picked after episode 2, dropped after episode 3 → only episode 3 counts
    PlayerModel::factory()->pickedIn($episode2)->dropped($episode3)->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 1,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 1,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode3->id,
        'count' => 5,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode4->id,
        'count' => 3,
    ]);

    $points = $this->service->getPlayerPoints($player, $this->season);

    // Only episode 3: 5 * 2.00 = 10.0
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

it('calculates player model points respecting ownership window', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $playerModel = PlayerModel::factory()->pickedIn($this->episode)->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    // Episode 1 points (should NOT count — picked in episode 1, so only > 1)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 3,
    ]);
    // Episode 2 points (should count)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 4,
    ]);

    $points = $this->service->getPlayerModelPoints($playerModel);

    // Only episode 2: 4 * 2.00 = 8.0
    expect($points)->toBe(8.0);
});

it('scores correctly after mid-season swap: old model keeps pre-swap points, new model gets none yet', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $oldModel = $this->topModel;
    $newModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    // Draft-picked old model, dropped after episode 2 (swap)
    $oldPlayerModel = PlayerModel::factory()->dropped($episode2)->create([
        'user_id' => $player->id,
        'top_model_id' => $oldModel->id,
        'season_id' => $this->season->id,
    ]);

    // New model picked in episode 2 (swap)
    $newPlayerModel = PlayerModel::factory()->pickedIn($episode2)->create([
        'user_id' => $player->id,
        'top_model_id' => $newModel->id,
        'season_id' => $this->season->id,
    ]);

    // Old model earned 3 points in Ep 1, 5 in Ep 2
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $oldModel->id,
        'episode_id' => $this->episode->id,
        'count' => 3,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $oldModel->id,
        'episode_id' => $episode2->id,
        'count' => 5,
    ]);

    // New model earned 4 points in Ep 1, 2 in Ep 2
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $newModel->id,
        'episode_id' => $this->episode->id,
        'count' => 4,
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $newModel->id,
        'episode_id' => $episode2->id,
        'count' => 2,
    ]);

    // Old model: draft pick, dropped after ep 2 → episodes 1-2 count
    $oldPoints = $this->service->getPlayerModelPoints($oldPlayerModel);
    expect($oldPoints)->toBe(16.0); // (3 + 5) * 2.00

    // New model: picked in ep 2 → only episodes > 2 count → 0 points (no ep 3 yet)
    $newPoints = $this->service->getPlayerModelPoints($newPlayerModel);
    expect($newPoints)->toBe(0.0);

    // Total player points: old model's ep 1-2 + new model's ep 3+ = 16 + 0
    $totalPoints = $this->service->getPlayerPoints($player, $this->season);
    expect($totalPoints)->toBe(16.0);

    // getModelPoints (without ownership) would wrongly show new model's total
    $newModelTotalPoints = $this->service->getModelPoints($newModel);
    expect($newModelTotalPoints)->toBe(12.0); // (4 + 2) * 2.00 — NOT what player should see
});

it('scores correctly when player owns same model twice in a season', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);
    $episode3 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 3]);
    $episode4 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 4]);
    $episode5 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 5]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // First ownership: draft pick, dropped after episode 2
    PlayerModel::factory()->dropped($episode2)->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    // Second ownership: picked again in episode 4 (swap)
    PlayerModel::factory()->pickedIn($episode4)->create([
        'user_id' => $player->id,
        'top_model_id' => $this->topModel->id,
        'season_id' => $this->season->id,
    ]);

    // Points per episode
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $this->episode->id,
        'count' => 1, // Ep 1: owned → counts
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 2, // Ep 2: owned → counts
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode3->id,
        'count' => 3, // Ep 3: NOT owned → does not count
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode4->id,
        'count' => 4, // Ep 4: NOT owned (picked_in = 4, so > 4 only) → does not count
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode5->id,
        'count' => 5, // Ep 5: owned again → counts
    ]);

    $points = $this->service->getPlayerPoints($player, $this->season);

    // First ownership (ep 1-2): (1 + 2) * 2.00 = 6.0
    // Second ownership (ep 5+): 5 * 2.00 = 10.0
    // Total: 16.0
    expect($points)->toBe(16.0);
});

it('trading swap uses correct episode boundary for scoring', function () {
    // Reproduces: phase created with Ep1, but swap happens after Ep2 ended.
    // Ep1 (ended), Ep2 (ended), then player swaps old→new.
    // Old model (Juna): should get credit for Ep1+Ep2
    // New model (Marleen): should get 0 (no episodes after Ep2 yet)
    $episode2 = Episode::factory()->ended()->create(['season_id' => $this->season->id, 'number' => 2]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $oldModel = $this->topModel; // "Juna"
    $newModel = TopModel::factory()->create(['season_id' => $this->season->id]); // "Marleen"

    // Player draft-picked oldModel, then swaps after Ep2 ended
    // Correct: dropped_after_episode = Ep2, picked_in_episode = Ep2
    $oldPm = PlayerModel::factory()->dropped($episode2)->create([
        'user_id' => $player->id,
        'top_model_id' => $oldModel->id,
        'season_id' => $this->season->id,
    ]);
    $newPm = PlayerModel::factory()->pickedIn($episode2)->create([
        'user_id' => $player->id,
        'top_model_id' => $newModel->id,
        'season_id' => $this->season->id,
    ]);

    // Old model scored in Ep1 and Ep2
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $oldModel->id,
        'episode_id' => $this->episode->id,
        'count' => 5, // Ep1: 5 * 2 = 10
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $oldModel->id,
        'episode_id' => $episode2->id,
        'count' => 3, // Ep2: 3 * 2 = 6
    ]);

    // New model scored in Ep1 and Ep2 (under different owner or unowned)
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $newModel->id,
        'episode_id' => $this->episode->id,
        'count' => 4, // Ep1: 4 * 2 = 8
    ]);
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $newModel->id,
        'episode_id' => $episode2->id,
        'count' => 7, // Ep2: 7 * 2 = 14
    ]);

    // Old model: draft pick, dropped after Ep2 → Ep1+Ep2 count
    expect($this->service->getPlayerModelPoints($oldPm))->toBe(16.0); // (5+3)*2

    // New model: picked after Ep2 → only Ep3+ count → 0
    expect($this->service->getPlayerModelPoints($newPm))->toBe(0.0);

    // Total: 16 + 0 = 16
    expect($this->service->getPlayerPoints($player, $this->season))->toBe(16.0);

    // BUG SCENARIO: if episode IDs were wrong (Ep1 instead of Ep2):
    // Old model would get: episodes <= 1 → only Ep1 = 10
    // New model would get: episodes > 1 → Ep2 = 14
    // Total would be 24 instead of 16 — WRONG
});

it('dropped model points only count through drop episode', function () {
    $episode2 = Episode::factory()->create(['season_id' => $this->season->id, 'number' => 2]);

    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    PlayerModel::factory()->dropped($this->episode)->create([
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
    ActionLog::factory()->create([
        'action_id' => $this->action->id,
        'top_model_id' => $this->topModel->id,
        'episode_id' => $episode2->id,
        'count' => 3,
    ]);

    $pointsAfter = $this->service->getPlayerPoints($player, $this->season);

    // Only episode 1 counts: 5 * 2.00 = 10.0 (episode 2 excluded)
    expect($pointsAfter)->toBe(10.0);
});
