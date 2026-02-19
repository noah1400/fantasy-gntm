<?php

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Models\Action;
use App\Models\ActionLog;
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

    $this->service->dropModel($player, $this->season, $model);

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

    $newPm = $this->service->swapModel($player, $this->season, $dropModel, $pickModel);

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
    expect(PlayerModel::where('user_id', $player->id)->whereNotNull('dropped_at')->count())->toBe(1);
});

it('getRequiredPostEpisodeActions returns free_agent_pick when free agents exist', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $freeAgent = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->endEpisode($this->episode, [$model1->id]);

    $actions = $this->service->getRequiredPostEpisodeActions($this->season, $this->episode);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('free_agent_pick')
        ->and($actions[0]['user']->id)->toBe($player->id);
});

it('getRequiredPostEpisodeActions returns mandatory_drop when no free agents', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model3 = TopModel::factory()->create(['season_id' => $this->season->id]);

    // player1 owns model1 and model2, player2 owns model3
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model3->id,
        'season_id' => $this->season->id,
    ]);

    // Eliminate model1 — no free agents remain since model2 & model3 are owned
    $this->service->endEpisode($this->episode, [$model1->id]);

    $actions = $this->service->getRequiredPostEpisodeActions($this->season, $this->episode);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('mandatory_drop')
        ->and($actions[0]['user']->id)->toBe($player1->id);
});

it('getRequiredPostEpisodeActions returns optional_swap after drops create free agents', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model3 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model4 = TopModel::factory()->create(['season_id' => $this->season->id]);

    // player1 owns model1, model2, model3; player2 owns model4
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model3->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model4->id,
        'season_id' => $this->season->id,
    ]);

    // Eliminate model1 — no free agents, so player1 must mandatory drop
    $this->service->endEpisode($this->episode, [$model1->id]);

    // Player1 performs mandatory drop, freeing model2
    $this->service->dropModel($player1, $this->season, $model2, isMandatory: true, episode: $this->episode);

    $actions = $this->service->getRequiredPostEpisodeActions($this->season, $this->episode);

    // Both players should get optional_swap since model2 is now a free agent
    $swapActions = collect($actions)->where('action', 'optional_swap');
    expect($swapActions)->toHaveCount(2);
});

it('getRequiredPostEpisodeActions returns player_eliminated when no models and no free agents', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    // Each player owns exactly one model
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);

    // Eliminate model1 — player1 has no models left and no free agents
    $this->service->endEpisode($this->episode, [$model1->id]);

    $actions = $this->service->getRequiredPostEpisodeActions($this->season, $this->episode);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('player_eliminated')
        ->and($actions[0]['user']->id)->toBe($player1->id);
});

it('getRequiredPostEpisodeActions skips already completed actions', function () {
    $player = User::factory()->create();
    $this->season->players()->attach($player->id);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $freeAgent = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->endEpisode($this->episode, [$model1->id]);

    // Player picks free agent
    $this->service->pickFreeAgent($player, $this->season, $freeAgent, $this->episode);

    $actions = $this->service->getRequiredPostEpisodeActions($this->season, $this->episode);

    // No more actions needed
    $pickActions = collect($actions)->where('action', 'free_agent_pick');
    expect($pickActions)->toHaveCount(0);
});

it('getRequiredPostEpisodeActions sorts by least points first', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $freeAgent1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $freeAgent2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);

    // Give player2 more points than player1
    $action = Action::factory()->create(['season_id' => $this->season->id, 'multiplier' => 1.00]);
    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $model2->id,
        'episode_id' => $this->episode->id,
        'count' => 10,
    ]);

    // Eliminate both models — both need free_agent_pick
    $this->service->endEpisode($this->episode, [$model1->id, $model2->id]);

    $actions = $this->service->getRequiredPostEpisodeActions($this->season, $this->episode);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]['user']->id)->toBe($player1->id)
        ->and($actions[1]['user']->id)->toBe($player2->id);
});

it('endEpisode sends database notifications to affected players', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $freeAgent = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->endEpisode($this->episode, [$model1->id]);

    expect($player1->unreadNotifications)->toHaveCount(1);
    expect($player1->unreadNotifications->first()->data['title'])->toBe('Pick a free agent');

    expect($player2->notifications)->toHaveCount(0);
});

it('endEpisode sends player_eliminated notification when no models and no free agents', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->endEpisode($this->episode, [$model1->id]);

    expect($player1->unreadNotifications)->toHaveCount(1);
    expect($player1->unreadNotifications->first()->data['title'])->toBe('You have been eliminated');
});

it('endEpisode sends mandatory_drop notification when no free agents but player has models', function () {
    $player1 = User::factory()->create();
    $player2 = User::factory()->create();
    $this->season->players()->attach([$player1->id, $player2->id]);

    $model1 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $this->season->id]);
    $model3 = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model1->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player1->id,
        'top_model_id' => $model2->id,
        'season_id' => $this->season->id,
    ]);
    PlayerModel::factory()->create([
        'user_id' => $player2->id,
        'top_model_id' => $model3->id,
        'season_id' => $this->season->id,
    ]);

    $this->service->endEpisode($this->episode, [$model1->id]);

    expect($player1->unreadNotifications)->toHaveCount(1);
    expect($player1->unreadNotifications->first()->data['title'])->toBe('You must drop a model');
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
