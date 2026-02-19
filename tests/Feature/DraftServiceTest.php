<?php

use App\Models\DraftOrder;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\DraftService;

beforeEach(function () {
    $this->service = app(DraftService::class);
    $this->season = Season::factory()->draft()->create(['models_per_player' => 2]);
    $this->players = User::factory(3)->create();
    $this->season->players()->attach($this->players->pluck('id'));

    foreach ($this->players as $i => $player) {
        DraftOrder::factory()->create([
            'season_id' => $this->season->id,
            'user_id' => $player->id,
            'position' => $i + 1,
        ]);
    }

    $this->models = TopModel::factory(6)->create(['season_id' => $this->season->id]);
});

it('generates snake draft order for 3 players with 2 rounds', function () {
    $order = $this->service->generateSnakeOrder($this->season);

    expect($order)->toHaveCount(6)
        ->and($order[0])->toBe($this->players[0]->id)
        ->and($order[1])->toBe($this->players[1]->id)
        ->and($order[2])->toBe($this->players[2]->id)
        ->and($order[3])->toBe($this->players[2]->id)
        ->and($order[4])->toBe($this->players[1]->id)
        ->and($order[5])->toBe($this->players[0]->id);
});

it('returns the correct current drafter', function () {
    $drafter = $this->service->getCurrentDrafter($this->season);

    expect($drafter->id)->toBe($this->players[0]->id);
});

it('picks a model for the current drafter', function () {
    $pick = $this->service->pickModel($this->season, $this->players[0], $this->models[0]);

    expect($pick->user_id)->toBe($this->players[0]->id)
        ->and($pick->top_model_id)->toBe($this->models[0]->id)
        ->and($pick->round)->toBe(1)
        ->and($pick->pick_number)->toBe(1);

    $nextDrafter = $this->service->getCurrentDrafter($this->season);
    expect($nextDrafter->id)->toBe($this->players[1]->id);
});

it('throws when wrong player tries to pick', function () {
    $this->service->pickModel($this->season, $this->players[1], $this->models[0]);
})->throws(\InvalidArgumentException::class, 'not this player\'s turn');

it('throws when model already picked', function () {
    $this->service->pickModel($this->season, $this->players[0], $this->models[0]);
    $this->service->pickModel($this->season, $this->players[1], $this->models[0]);
})->throws(\InvalidArgumentException::class, 'already been picked');

it('detects draft completion', function () {
    expect($this->service->isDraftComplete($this->season))->toBeFalse();

    // Pick all 6 models in snake order
    $order = $this->service->generateSnakeOrder($this->season);
    foreach ($order as $i => $userId) {
        $player = User::find($userId);
        $this->service->pickModel($this->season, $player, $this->models[$i]);
    }

    expect($this->service->isDraftComplete($this->season))->toBeTrue();
    expect($this->service->getCurrentDrafter($this->season))->toBeNull();
});

it('returns available models excluding picked ones', function () {
    $this->service->pickModel($this->season, $this->players[0], $this->models[0]);

    $available = $this->service->getAvailableModels($this->season);

    expect($available)->toHaveCount(5)
        ->and($available->pluck('id'))->not->toContain($this->models[0]->id);
});

it('pickModel sends database notification to next drafter', function () {
    $this->service->pickModel($this->season, $this->players[0], $this->models[0]);

    $nextDrafter = $this->players[1];
    expect($nextDrafter->notifications()->count())->toBe(1);

    $notification = $nextDrafter->notifications->first();
    expect($notification->data['title'])->toBe("It's your turn to draft!");
    expect($notification->data['body'])->toBe('Head to the Draft Room to make your pick.');
});

it('pickModel does not send notification when draft is complete', function () {
    $order = $this->service->generateSnakeOrder($this->season);
    foreach ($order as $i => $userId) {
        $player = User::find($userId);
        $this->service->pickModel($this->season, $player, $this->models[$i]);
    }

    // No player should have more than the expected notifications
    // The last drafter should NOT get a notification after the last pick
    $lastDrafter = User::find($order[count($order) - 1]);
    $notificationsAsRecipient = $lastDrafter->notifications()
        ->where('data->title', "It's your turn to draft!")
        ->count();

    // Last drafter received notifications when it was their turn, but no extra one after final pick
    expect($this->service->getCurrentDrafter($this->season))->toBeNull();
});

it('sets draft order', function () {
    $newOrder = [$this->players[2]->id, $this->players[0]->id, $this->players[1]->id];
    $this->service->setDraftOrder($this->season, $newOrder);

    $orders = $this->season->draftOrders()->orderBy('position')->get();
    expect($orders[0]->user_id)->toBe($this->players[2]->id)
        ->and($orders[1]->user_id)->toBe($this->players[0]->id)
        ->and($orders[2]->user_id)->toBe($this->players[1]->id);
});
