<?php

use App\Enums\GameEventType;
use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\GameStateService;
use App\Services\PhaseService;

beforeEach(function () {
    $this->service = app(PhaseService::class);
    $this->gs = app(GameStateService::class);
    $this->season = Season::factory()->active()->create();
    $this->episode = Episode::factory()->ended()->create(['season_id' => $this->season->id]);
});

// ── Queue mechanics ─────────────────────────────────────────────────────

it('creates a phase and adds it to the queue', function () {
    $phase = $this->service->createPhase(
        $this->season,
        GamePhaseType::MandatoryDrop,
        ['target_model_count' => 1],
        $this->episode
    );

    expect($phase->type)->toBe(GamePhaseType::MandatoryDrop)
        ->and($phase->status)->toBe(GamePhaseStatus::Pending)
        ->and($phase->config)->toBe(['target_model_count' => 1])
        ->and($phase->position)->toBe(0);
});

it('auto-activates the first phase if no active phase exists', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $phase = $this->service->createPhase(
        $this->season,
        GamePhaseType::MandatoryDrop,
        ['target_model_count' => 1],
    );

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Active)
        ->and($phase->fresh()->started_at)->not->toBeNull();
});

it('queues phase as pending when another phase is active', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $active = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $pending = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    expect($active->fresh()->status)->toBe(GamePhaseStatus::Active)
        ->and($pending->fresh()->status)->toBe(GamePhaseStatus::Pending)
        ->and($pending->position)->toBe(1);
});

it('returns the active phase for a season', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

    expect($this->service->getActivePhase($this->season)->id)->toBe($phase->id);
});

it('returns null when no active phase', function () {
    expect($this->service->getActivePhase($this->season))->toBeNull();
});

it('advances queue when phase is completed', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    // Use TradingPhase as phase2 since it never auto-completes (admin-closed)
    $phase1 = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $phase2 = $this->service->createPhase($this->season, GamePhaseType::TradingPhase);

    $this->service->closePhase($phase1);

    expect($phase1->fresh()->status)->toBe(GamePhaseStatus::Completed)
        ->and($phase2->fresh()->status)->toBe(GamePhaseStatus::Active);
});

it('cancels a pending phase', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $phase1 = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $phase2 = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    $this->service->cancelPhase($phase2);

    expect($phase2->fresh()->status)->toBe(GamePhaseStatus::Cancelled);
});

it('reorders pending phases', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $phase1 = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $phase2 = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);
    $phase3 = $this->service->createPhase($this->season, GamePhaseType::OptionalSwap, []);

    $this->service->reorderPhases($this->season, [$phase3->id, $phase2->id]);

    expect($phase3->fresh()->position)->toBeLessThan($phase2->fresh()->position);
});

// ── MandatoryDrop: "All players must drop models until they reach the target
//    count. Everyone acts at the same time — no turn order. Auto-completes when
//    all players are at or below the target." ─────────────────────────────────

it('MandatoryDrop: all players with too many models see action simultaneously', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $this->season->players()->attach([$alice->id, $bob->id]);

    // Both have 3 models, target is 1
    foreach ([$alice, $bob] as $player) {
        for ($i = 0; $i < 3; $i++) {
            $m = TopModel::factory()->create(['season_id' => $this->season->id]);
            PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $m->id, 'season_id' => $this->season->id]);
        }
    }

    $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

    // Both should see mandatory_drop at the same time (no waiting)
    $aliceAction = $this->service->getPlayerAction($this->season, $alice);
    $bobAction = $this->service->getPlayerAction($this->season, $bob);

    expect($aliceAction['action'])->toBe('mandatory_drop')
        ->and($bobAction['action'])->toBe('mandatory_drop');
});

it('MandatoryDrop: player at target gets no action', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);

    $phase = GamePhase::factory()->mandatoryDrop()->active()->create([
        'season_id' => $this->season->id,
    ]);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action)->toBeNull();
});

it('MandatoryDrop: auto-completes when all players drop to target', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $this->season->players()->attach([$alice->id, $bob->id]);

    $aliceModels = TopModel::factory()->count(2)->create(['season_id' => $this->season->id]);
    $bobModels = TopModel::factory()->count(2)->create(['season_id' => $this->season->id]);

    foreach ($aliceModels as $m) {
        PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $m->id, 'season_id' => $this->season->id]);
    }
    foreach ($bobModels as $m) {
        PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $m->id, 'season_id' => $this->season->id]);
    }

    $phase = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1], $this->episode);

    // Alice drops — Bob still needs to
    $this->gs->dropModel($alice, $this->season, $aliceModels[0], isMandatory: true, episode: $this->episode, phase: $phase);
    $this->service->checkPhaseCompletion($phase);
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Active);

    // Bob drops — now everyone is at target
    $this->gs->dropModel($bob, $this->season, $bobModels[0], isMandatory: true, episode: $this->episode, phase: $phase);
    $this->service->checkPhaseCompletion($phase);
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

it('MandatoryDrop: auto-completes on activation when all already at target', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

// ── PickRound: "Players with fewer models than the threshold pick a free agent.
//    Turn order: lowest points goes first. Auto-completes when all eligible
//    players have picked or no free agents remain." ──────────────────────────

it('PickRound: lowest points player picks first', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $this->season->players()->attach([$alice->id, $bob->id]);

    // Give Alice a model that scored points so she has MORE points
    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);

    // Bob has a model with 0 points
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    // Score Alice's model with 10 points
    $action = Action::factory()->create(['season_id' => $this->season->id, 'multiplier' => 1.00]);
    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $aliceModel->id,
        'episode_id' => $this->episode->id,
        'count' => 10,
    ]);

    // Both eligible (1 model, threshold 2), 2 free agents
    TopModel::factory()->count(2)->create(['season_id' => $this->season->id]);

    $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    // Bob (0 pts) should pick first, Alice (10 pts) should wait
    $aliceAction = $this->service->getPlayerAction($this->season, $alice);
    $bobAction = $this->service->getPlayerAction($this->season, $bob);

    expect($bobAction['action'])->toBe('free_agent_pick')
        ->and($aliceAction['action'])->toBe('waiting');
});

it('PickRound: player at or above threshold gets no action', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Player has 2 models, threshold is 2 — not eligible
    $models = TopModel::factory()->count(2)->create(['season_id' => $this->season->id]);
    foreach ($models as $m) {
        PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $m->id, 'season_id' => $this->season->id]);
    }

    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    // Use factory to avoid auto-completion issues
    $phase = GamePhase::factory()->pickRound()->active()->create([
        'season_id' => $this->season->id,
        'config' => ['eligible_below' => 2],
    ]);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action)->toBeNull();
});

it('PickRound: auto-completes when all eligible players have picked', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $this->season->players()->attach([$alice->id, $bob->id]);

    // Both have 1 model (eligible below 2)
    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    $free1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $free2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2], $this->episode);

    // First player picks
    $turnPlayer = $this->service->getPlayerAction($this->season, $alice);
    $firstPicker = $turnPlayer && $turnPlayer['action'] === 'free_agent_pick' ? $alice : $bob;
    $secondPicker = $firstPicker->id === $alice->id ? $bob : $alice;
    $this->gs->pickFreeAgent($firstPicker, $this->season, $free1, $this->episode, $phase);
    $this->service->checkPhaseCompletion($phase);
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Active);

    // Second player picks — phase should complete
    $this->gs->pickFreeAgent($secondPicker, $this->season, $free2, $this->episode, $phase);
    $this->service->checkPhaseCompletion($phase);
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

it('PickRound: auto-completes when no free agents remain', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $this->season->players()->attach([$alice->id, $bob->id]);

    // Both have 1 model (eligible below 2)
    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    // Only 1 free agent for 2 eligible players
    $freeAgent = TopModel::factory()->create(['season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2], $this->episode);

    // First player picks the only free agent
    $turnPlayer = $this->service->getPlayerAction($this->season, $alice);
    $firstPicker = $turnPlayer && $turnPlayer['action'] === 'free_agent_pick' ? $alice : $bob;
    $this->gs->pickFreeAgent($firstPicker, $this->season, $freeAgent, $this->episode, $phase);
    $this->service->checkPhaseCompletion($phase);

    // No more free agents — phase should auto-complete
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

it('PickRound: auto-completes when no players are eligible', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Player already has 2 models (at threshold), so nobody is eligible
    $models = TopModel::factory()->count(2)->create(['season_id' => $this->season->id]);
    foreach ($models as $m) {
        PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $m->id, 'season_id' => $this->season->id]);
    }
    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $phase = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    // Nobody is eligible — phase should auto-complete on activation
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

// ── OptionalSwap: "Each player may swap one of their models for a free agent,
//    or skip. Turn order: lowest points goes first. Auto-completes when all
//    players have swapped or skipped." ────────────────────────────────────────

it('OptionalSwap: player gets optional_swap action', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $this->service->createPhase($this->season, GamePhaseType::OptionalSwap);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action['action'])->toBe('optional_swap');
});

it('OptionalSwap: lowest points player goes first', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $this->season->players()->attach([$alice->id, $bob->id]);

    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    // Alice has 10 pts, Bob has 0
    $action = Action::factory()->create(['season_id' => $this->season->id, 'multiplier' => 1.00]);
    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $aliceModel->id,
        'episode_id' => $this->episode->id,
        'count' => 10,
    ]);

    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $this->service->createPhase($this->season, GamePhaseType::OptionalSwap);

    $aliceAction = $this->service->getPlayerAction($this->season, $alice);
    $bobAction = $this->service->getPlayerAction($this->season, $bob);

    expect($bobAction['action'])->toBe('optional_swap')
        ->and($aliceAction['action'])->toBe('waiting');
});

it('OptionalSwap: player can skip and phase sees it', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $phase = $this->service->createPhase($this->season, GamePhaseType::OptionalSwap, [], $this->episode);

    // Player skips
    GameEvent::create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode->id,
        'game_phase_id' => $phase->id,
        'type' => GameEventType::SwapSkipped,
        'payload' => ['user_id' => $player->id, 'user_name' => $player->name],
    ]);

    $this->service->checkPhaseCompletion($phase);

    // Only player skipped — phase should complete
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

it('OptionalSwap: auto-completes when all players have swapped or skipped', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $this->season->players()->attach([$alice->id, $bob->id]);

    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    $free1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // extra free agent

    $phase = $this->service->createPhase($this->season, GamePhaseType::OptionalSwap, [], $this->episode);

    // Alice swaps
    $this->gs->swapModel($alice, $this->season, $aliceModel, $free1, $this->episode, $phase);
    $this->service->checkPhaseCompletion($phase);
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Active);

    // Bob skips
    GameEvent::create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode->id,
        'game_phase_id' => $phase->id,
        'type' => GameEventType::SwapSkipped,
        'payload' => ['user_id' => $bob->id, 'user_name' => $bob->name],
    ]);
    $this->service->checkPhaseCompletion($phase);

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

it('OptionalSwap: auto-completes on activation when no free agents exist', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    // Player has a model but there are NO free agents
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::OptionalSwap);

    // No free agents — phase should auto-complete on activation
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

// ── TradingPhase: "All players can freely swap any of their models for free
//    agents at the same time. No turn order, no limit. Must be closed manually
//    by admin." ──────────────────────────────────────────────────────────────

it('TradingPhase: all players see action simultaneously (no turn order)', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $this->season->players()->attach([$alice->id, $bob->id]);

    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $this->service->createPhase($this->season, GamePhaseType::TradingPhase);

    $aliceAction = $this->service->getPlayerAction($this->season, $alice);
    $bobAction = $this->service->getPlayerAction($this->season, $bob);

    // Both see trading_swap at the same time — no waiting
    expect($aliceAction['action'])->toBe('trading_swap')
        ->and($bobAction['action'])->toBe('trading_swap');
});

it('TradingPhase: player can swap multiple times (no limit)', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $free1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $free2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::TradingPhase, [], $this->episode);

    // First swap
    $this->gs->swapModel($player, $this->season, $model1, $free1, $this->episode, $phase);

    // Player should still see trading_swap (no limit)
    $action = $this->service->getPlayerAction($this->season, $player);
    expect($action['action'])->toBe('trading_swap');

    // Second swap
    $this->gs->swapModel($player, $this->season, $model2, $free2, $this->episode, $phase);

    // Still has action if free agents exist (model1 was dropped, now free)
    $action = $this->service->getPlayerAction($this->season, $player);
    expect($action['action'])->toBe('trading_swap');
});

it('TradingPhase: does not auto-complete (must be closed by admin)', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);

    $free = TopModel::factory()->create(['season_id' => $this->season->id]);

    $phase = $this->service->createPhase($this->season, GamePhaseType::TradingPhase, [], $this->episode);

    // Player swaps
    $this->gs->swapModel($player, $this->season, $model, $free, $this->episode, $phase);
    $this->service->checkPhaseCompletion($phase);

    // Should still be active — admin must close manually
    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Active);
});

it('TradingPhase: admin can close it manually', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $phase = $this->service->createPhase($this->season, GamePhaseType::TradingPhase);

    $this->service->closePhase($phase);

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

// ── ForceAssign: "Instantly assigns a specific free agent to a player.
//    Executes immediately — no player action needed." ────────────────────────

it('ForceAssign: assigns model and completes instantly', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    $phase = $this->service->createPhase(
        $this->season,
        GamePhaseType::ForceAssign,
        ['user_id' => $player->id, 'top_model_id' => $model->id],
    );

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed)
        ->and(PlayerModel::where('user_id', $player->id)->where('top_model_id', $model->id)->active()->exists())->toBeTrue();

    // Game event linked to this phase
    $event = GameEvent::where('game_phase_id', $phase->id)
        ->where('type', GameEventType::FreeAgentPick)
        ->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['user_id'])->toBe($player->id);
});

// ── EliminatePlayer: "Instantly eliminates a player from the season.
//    Executes immediately." ──────────────────────────────────────────────────

it('EliminatePlayer: eliminates and completes instantly', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $phase = $this->service->createPhase(
        $this->season,
        GamePhaseType::EliminatePlayer,
        ['user_id' => $player->id],
    );

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed)
        ->and($this->season->players()->where('user_id', $player->id)->wherePivot('is_eliminated', true)->exists())->toBeTrue();

    $event = GameEvent::where('game_phase_id', $phase->id)
        ->where('type', GameEventType::PlayerEliminated)
        ->first();
    expect($event)->not->toBeNull();
});

// ── SkipPlayer: "Skips a player's turn in the current phase.
//    Executes immediately." ──────────────────────────────────────────────────

it('SkipPlayer: skips player turn in the active phase and phase sees it', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $this->season->players()->attach([$alice->id, $bob->id]);

    $aliceModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $bobModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $alice->id, 'top_model_id' => $aliceModel->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $bob->id, 'top_model_id' => $bobModel->id, 'season_id' => $this->season->id]);

    $free1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // extra free agent

    // Create OptionalSwap phase — both players need to act
    $swapPhase = $this->service->createPhase($this->season, GamePhaseType::OptionalSwap, [], $this->episode);

    // Admin skips Alice
    $this->service->createPhase(
        $this->season,
        GamePhaseType::SkipPlayer,
        ['user_id' => $alice->id],
        $this->episode,
    );

    // Alice should have no action in the OptionalSwap now (she was skipped)
    $aliceAction = $this->service->getPlayerAction($this->season, $alice);
    expect($aliceAction)->toBeNull();

    // Bob swaps — phase should complete (Alice skipped + Bob swapped = all done)
    $this->gs->swapModel($bob, $this->season, $bobModel, $free1, $this->episode, $swapPhase);
    $this->service->checkPhaseCompletion($swapPhase);

    expect($swapPhase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});
