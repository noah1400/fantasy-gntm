<?php

use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Models\Episode;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\PhaseService;

beforeEach(function () {
    $this->service = app(PhaseService::class);
    $this->season = Season::factory()->active()->create();
    $this->episode = Episode::factory()->ended()->create(['season_id' => $this->season->id]);
});

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
    // Need a player with 2+ models so MandatoryDrop doesn't auto-complete
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
    // Need a player with 2+ models so MandatoryDrop doesn't auto-complete
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

    $phase1 = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $phase2 = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

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

it('executes instant phases immediately', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $phase = $this->service->createPhase(
        $this->season,
        GamePhaseType::EliminatePlayer,
        ['user_id' => $player->id],
    );

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed)
        ->and($this->season->players()->where('user_id', $player->id)->wherePivot('is_eliminated', true)->exists())->toBeTrue();

    // Verify game event is linked to the phase
    $event = \App\Models\GameEvent::where('game_phase_id', $phase->id)
        ->where('type', \App\Enums\GameEventType::PlayerEliminated)
        ->first();
    expect($event)->not->toBeNull();
});

it('force assign creates player model and completes instantly', function () {
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

    // Verify game event is linked to the phase (not orphaned)
    $event = \App\Models\GameEvent::where('game_phase_id', $phase->id)
        ->where('type', \App\Enums\GameEventType::FreeAgentPick)
        ->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['user_id'])->toBe($player->id);
});

it('getPlayerAction returns mandatory_drop for player with too many models', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action)->not->toBeNull()
        ->and($action['action'])->toBe('mandatory_drop');
});

it('getPlayerAction returns null for player already at target model count', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);

    // This phase will auto-complete since all players already at target, so create manually
    $phase = GamePhase::factory()->mandatoryDrop()->active()->create([
        'season_id' => $this->season->id,
    ]);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action)->toBeNull();
});

it('checkPhaseCompletion completes MandatoryDrop when all players at target', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);

    // Player already has 1 model, target is 1 — phase should auto-complete
    $phase = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Completed);
});

it('getPlayerAction returns free_agent_pick for eligible player on their turn in PickRound', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action)->not->toBeNull()
        ->and($action['action'])->toBe('free_agent_pick');
});

it('getPlayerAction returns waiting for non-turn player in PickRound', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player1->id, 'top_model_id' => $model1->id, 'season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player2->id, 'top_model_id' => $model2->id, 'season_id' => $this->season->id]);

    // 2 free agents so both are eligible
    TopModel::factory()->count(2)->create(['season_id' => $this->season->id]);

    $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    // Both have 0 points — one gets the turn, other waits
    $action1 = $this->service->getPlayerAction($this->season, $player1);
    $action2 = $this->service->getPlayerAction($this->season, $player2);

    $actions = collect([$action1, $action2])->filter();
    $picks = $actions->where('action', 'free_agent_pick');
    $waits = $actions->where('action', 'waiting');

    expect($picks)->toHaveCount(1)
        ->and($waits)->toHaveCount(1);
});

it('getPlayerAction returns trading_swap for TradingPhase', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    PlayerModel::factory()->create(['user_id' => $player->id, 'top_model_id' => $model->id, 'season_id' => $this->season->id]);
    TopModel::factory()->create(['season_id' => $this->season->id]); // free agent

    $this->service->createPhase($this->season, GamePhaseType::TradingPhase, []);

    $action = $this->service->getPlayerAction($this->season, $player);

    expect($action)->not->toBeNull()
        ->and($action['action'])->toBe('trading_swap');
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

    // Reorder pending: swap phase2 and phase3
    $this->service->reorderPhases($this->season, [$phase3->id, $phase2->id]);

    expect($phase3->fresh()->position)->toBeLessThan($phase2->fresh()->position);
});
