<?php

use App\Enums\GameEventType;
use App\Enums\PickType;
use App\Filament\Player\Widgets\MyModels;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;

beforeEach(function () {
    $this->season = Season::factory()->active()->create();
    $this->episode1 = Episode::factory()->ended()->create(['season_id' => $this->season->id, 'number' => 1]);
    $this->episode2 = Episode::factory()->ended()->create(['season_id' => $this->season->id, 'number' => 2]);
    $this->player = User::factory()->create();
    $this->season->players()->attach($this->player->id);

    $this->actingAs($this->player);
});

it('shows active models without drop reason', function () {
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    $data = app(MyModels::class)->getMyModelsData();

    expect($data)->toHaveCount(1)
        ->and($data[0]['top_model']->id)->toBe($model->id)
        ->and($data[0]['is_dropped'])->toBeFalse()
        ->and($data[0]['drop_reason'])->toBeNull()
        ->and($data[0]['picked_in_episode'])->toBeNull();
});

it('resolves mandatory drop reason', function () {
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->dropped($this->episode2)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode2->id,
        'type' => GameEventType::MandatoryDrop,
        'payload' => [
            'user_id' => $this->player->id,
            'user_name' => $this->player->name,
            'top_model_id' => $model->id,
            'top_model_name' => $model->name,
        ],
    ]);

    $data = app(MyModels::class)->getMyModelsData();

    expect($data)->toHaveCount(1)
        ->and($data[0]['is_dropped'])->toBeTrue()
        ->and($data[0]['drop_reason'])->toBe(GameEventType::MandatoryDrop)
        ->and($data[0]['dropped_after_episode']->id)->toBe($this->episode2->id);
});

it('resolves model swap drop reason', function () {
    $droppedModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickedModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    PlayerModel::factory()->dropped($this->episode2)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $droppedModel->id,
        'season_id' => $this->season->id,
    ]);

    PlayerModel::factory()->pickedIn($this->episode2)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $pickedModel->id,
        'season_id' => $this->season->id,
        'pick_type' => PickType::Swap,
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode2->id,
        'type' => GameEventType::ModelSwap,
        'payload' => [
            'user_id' => $this->player->id,
            'user_name' => $this->player->name,
            'dropped_model_id' => $droppedModel->id,
            'dropped_model_name' => $droppedModel->name,
            'picked_model_id' => $pickedModel->id,
            'picked_model_name' => $pickedModel->name,
        ],
    ]);

    $data = app(MyModels::class)->getMyModelsData();

    $active = $data->firstWhere('is_dropped', false);
    $dropped = $data->firstWhere('is_dropped', true);

    expect($data)->toHaveCount(2)
        ->and($active['top_model']->id)->toBe($pickedModel->id)
        ->and($active['drop_reason'])->toBeNull()
        ->and($dropped['top_model']->id)->toBe($droppedModel->id)
        ->and($dropped['drop_reason'])->toBe(GameEventType::ModelSwap);
});

it('resolves elimination drop reason', function () {
    $model = TopModel::factory()->create([
        'season_id' => $this->season->id,
        'is_eliminated' => true,
        'eliminated_in_episode_id' => $this->episode2->id,
    ]);

    PlayerModel::factory()->dropped($this->episode2)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode2->id,
        'type' => GameEventType::Elimination,
        'payload' => [
            'top_model_id' => $model->id,
            'top_model_name' => $model->name,
        ],
    ]);

    $data = app(MyModels::class)->getMyModelsData();

    expect($data)->toHaveCount(1)
        ->and($data[0]['drop_reason'])->toBe(GameEventType::Elimination);
});

it('resolves drop reasons for re-owned model', function () {
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    $episode4 = Episode::factory()->ended()->create(['season_id' => $this->season->id, 'number' => 4]);

    // First ownership: draft, mandatory dropped after ep 2
    PlayerModel::factory()->dropped($this->episode2)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode2->id,
        'type' => GameEventType::MandatoryDrop,
        'payload' => [
            'user_id' => $this->player->id,
            'user_name' => $this->player->name,
            'top_model_id' => $model->id,
            'top_model_name' => $model->name,
        ],
    ]);

    // Second ownership: swapped back in ep 4, still active
    PlayerModel::factory()->pickedIn($episode4)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
        'pick_type' => PickType::Swap,
    ]);

    $data = app(MyModels::class)->getMyModelsData();

    // Active models sorted first
    expect($data)->toHaveCount(2)
        ->and($data[0]['is_dropped'])->toBeFalse()
        ->and($data[0]['pick_type'])->toBe(PickType::Swap)
        ->and($data[0]['picked_in_episode']->id)->toBe($episode4->id)
        ->and($data[1]['is_dropped'])->toBeTrue()
        ->and($data[1]['drop_reason'])->toBe(GameEventType::MandatoryDrop)
        ->and($data[1]['dropped_after_episode']->id)->toBe($this->episode2->id);
});

it('does not match drop events from other players', function () {
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);
    $otherPlayer = User::factory()->create();
    $this->season->players()->attach($otherPlayer->id);

    PlayerModel::factory()->dropped($this->episode2)->create([
        'user_id' => $this->player->id,
        'top_model_id' => $model->id,
        'season_id' => $this->season->id,
    ]);

    // Drop event belongs to other player
    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => $this->episode2->id,
        'type' => GameEventType::MandatoryDrop,
        'payload' => [
            'user_id' => $otherPlayer->id,
            'user_name' => $otherPlayer->name,
            'top_model_id' => $model->id,
            'top_model_name' => $model->name,
        ],
    ]);

    $data = app(MyModels::class)->getMyModelsData();

    expect($data)->toHaveCount(1)
        ->and($data[0]['is_dropped'])->toBeTrue()
        ->and($data[0]['drop_reason'])->toBeNull();
});
