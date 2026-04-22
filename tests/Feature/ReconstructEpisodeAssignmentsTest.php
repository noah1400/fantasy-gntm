<?php

use App\Enums\GameEventType;
use App\Enums\PickType;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;

beforeEach(function () {
    $this->season = Season::factory()->active()->create();
    $this->ep1 = Episode::factory()->ended()->create([
        'season_id' => $this->season->id,
        'number' => 1,
        'ended_at' => '2026-02-26 22:00:00',
    ]);
    $this->ep2 = Episode::factory()->ended()->create([
        'season_id' => $this->season->id,
        'number' => 2,
        'ended_at' => '2026-03-05 22:00:00',
    ]);
    $this->ep3 = Episode::factory()->ended()->create([
        'season_id' => $this->season->id,
        'number' => 3,
        'ended_at' => '2026-03-12 22:00:00',
    ]);
});

it('reconstructs game event episode ids from created_at', function () {
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    $event = GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::Elimination,
        'payload' => ['top_model_id' => $model->id, 'top_model_name' => $model->name],
        'created_at' => '2026-03-02 12:00:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($event->fresh()->episode_id)->toBe($this->ep1->id);
});

it('reconstructs picked_in for free agent pick', function () {
    $user = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::FreeAgentPick,
        'payload' => ['user_id' => $user->id, 'top_model_id' => $model->id],
        'created_at' => '2026-03-06 10:00:00',
    ]);

    $pm = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::FreeAgent,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => null,
        'created_at' => '2026-03-06 10:00:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($pm->fresh()->picked_in_episode_id)->toBe($this->ep2->id);
});

it('reconstructs picked_in for swap using picked_model_id', function () {
    $user = User::factory()->create();
    $droppedModel = TopModel::factory()->create(['season_id' => $this->season->id]);
    $pickedModel = TopModel::factory()->create(['season_id' => $this->season->id]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::ModelSwap,
        'payload' => [
            'user_id' => $user->id,
            'dropped_model_id' => $droppedModel->id,
            'picked_model_id' => $pickedModel->id,
        ],
        'created_at' => '2026-03-13 10:00:00',
    ]);

    $pm = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $pickedModel->id,
        'pick_type' => PickType::Swap,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => null,
        'created_at' => '2026-03-13 10:00:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($pm->fresh()->picked_in_episode_id)->toBe($this->ep3->id);
});

it('leaves picked_in null for draft PMs', function () {
    $user = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    $pm = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::Draft,
        'picked_in_episode_id' => $this->ep1->id,
        'dropped_after_episode_id' => null,
        'created_at' => '2026-02-20 10:00:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($pm->fresh()->picked_in_episode_id)->toBeNull();
});

it('uses earliest drop event for dropped_after ignoring later duplicates', function () {
    $user = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::MandatoryDrop,
        'payload' => ['user_id' => $user->id, 'top_model_id' => $model->id],
        'created_at' => '2026-03-02 19:45:00',
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => $this->ep3->id,
        'type' => GameEventType::MandatoryDrop,
        'payload' => ['user_id' => $user->id, 'top_model_id' => $model->id],
        'created_at' => '2026-03-13 12:00:00',
    ]);

    $pm = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::Draft,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => $this->ep3->id,
        'created_at' => '2026-02-26 19:04:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($pm->fresh()->dropped_after_episode_id)->toBe($this->ep1->id);
});

it('uses elimination event as drop when model was owned at elimination time', function () {
    $user = User::factory()->create();
    $model = TopModel::factory()->create([
        'season_id' => $this->season->id,
        'is_eliminated' => true,
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::Elimination,
        'payload' => ['top_model_id' => $model->id],
        'created_at' => '2026-03-05 22:00:00',
    ]);

    $pm = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::Draft,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => null,
        'created_at' => '2026-02-26 19:04:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($pm->fresh()->dropped_after_episode_id)->toBe($this->ep2->id);
});

it('respects window between consecutive PMs of same user and model', function () {
    $user = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::MandatoryDrop,
        'payload' => ['user_id' => $user->id, 'top_model_id' => $model->id],
        'created_at' => '2026-03-02 19:45:00',
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::MandatoryDrop,
        'payload' => ['user_id' => $user->id, 'top_model_id' => $model->id],
        'created_at' => '2026-03-08 10:00:00',
    ]);

    $pm1 = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::Draft,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => null,
        'created_at' => '2026-02-26 19:04:00',
    ]);

    $pm2 = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::FreeAgent,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => null,
        'created_at' => '2026-03-03 10:00:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($pm1->fresh()->dropped_after_episode_id)->toBe($this->ep1->id);
    expect($pm2->fresh()->dropped_after_episode_id)->toBe($this->ep2->id);
});

it('reconstructs top model eliminated_in_episode_id', function () {
    $model = TopModel::factory()->create([
        'season_id' => $this->season->id,
        'is_eliminated' => true,
        'eliminated_in_episode_id' => null,
    ]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::Elimination,
        'payload' => ['top_model_id' => $model->id],
        'created_at' => '2026-03-05 22:00:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--force' => true])
        ->assertExitCode(0);

    expect($model->fresh()->eliminated_in_episode_id)->toBe($this->ep2->id);
});

it('dry-run does not apply changes', function () {
    $user = User::factory()->create();
    $model = TopModel::factory()->create(['season_id' => $this->season->id]);

    GameEvent::factory()->create([
        'season_id' => $this->season->id,
        'episode_id' => null,
        'type' => GameEventType::MandatoryDrop,
        'payload' => ['user_id' => $user->id, 'top_model_id' => $model->id],
        'created_at' => '2026-03-02 19:45:00',
    ]);

    $pm = PlayerModel::factory()->create([
        'season_id' => $this->season->id,
        'user_id' => $user->id,
        'top_model_id' => $model->id,
        'pick_type' => PickType::Draft,
        'picked_in_episode_id' => null,
        'dropped_after_episode_id' => $this->ep3->id,
        'created_at' => '2026-02-26 19:04:00',
    ]);

    $this->artisan('reconstruct:episode-assignments', ['--dry-run' => true])
        ->assertExitCode(0);

    expect($pm->fresh()->dropped_after_episode_id)->toBe($this->ep3->id);
});
