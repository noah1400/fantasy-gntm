<?php

use App\Enums\SeasonStatus;
use App\Models\DraftOrder;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\SeasonService;

beforeEach(function () {
    $this->service = app(SeasonService::class);
});

it('starts a draft from setup status', function () {
    $season = Season::factory()->create(['status' => SeasonStatus::Setup]);
    $player = User::factory()->create();
    $season->players()->attach($player->id);
    DraftOrder::factory()->create(['season_id' => $season->id, 'user_id' => $player->id, 'position' => 1]);

    $this->service->startDraft($season);

    expect($season->fresh()->status)->toBe(SeasonStatus::Draft);
});

it('throws when starting draft from wrong status', function () {
    $season = Season::factory()->active()->create();

    $this->service->startDraft($season);
})->throws(\InvalidArgumentException::class, 'setup status');

it('throws when starting draft without players', function () {
    $season = Season::factory()->create(['status' => SeasonStatus::Setup]);

    $this->service->startDraft($season);
})->throws(\InvalidArgumentException::class, 'must have players');

it('throws when starting draft without draft order', function () {
    $season = Season::factory()->create(['status' => SeasonStatus::Setup]);
    $season->players()->attach(User::factory()->create()->id);

    $this->service->startDraft($season);
})->throws(\InvalidArgumentException::class, 'Draft order must be set');

it('activates a season from draft status', function () {
    $season = Season::factory()->draft()->create();

    $this->service->activateSeason($season);

    expect($season->fresh()->status)->toBe(SeasonStatus::Active);
});

it('throws when activating from wrong status', function () {
    $season = Season::factory()->create(['status' => SeasonStatus::Setup]);

    $this->service->activateSeason($season);
})->throws(\InvalidArgumentException::class, 'draft status');

it('completes a season', function () {
    $season = Season::factory()->active()->create();

    $this->service->completeSeason($season);

    expect($season->fresh()->status)->toBe(SeasonStatus::Completed);
});

it('returns free agents', function () {
    $season = Season::factory()->active()->create();
    $player = User::factory()->create();
    $season->players()->attach($player->id);

    $model1 = TopModel::factory()->create(['season_id' => $season->id]);
    $model2 = TopModel::factory()->create(['season_id' => $season->id]);
    $model3 = TopModel::factory()->eliminated()->create(['season_id' => $season->id]);

    PlayerModel::factory()->create([
        'user_id' => $player->id,
        'top_model_id' => $model1->id,
        'season_id' => $season->id,
    ]);

    $freeAgents = $this->service->getFreeAgents($season);

    expect($freeAgents)->toHaveCount(1)
        ->and($freeAgents->first()->id)->toBe($model2->id);
});
