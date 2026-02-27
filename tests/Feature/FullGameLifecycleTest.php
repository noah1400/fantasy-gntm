<?php

use App\Enums\EpisodeStatus;
use App\Enums\SeasonStatus;
use App\Filament\Player\Pages\DraftRoom;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\DraftService;
use App\Services\GameStateService;
use App\Services\SeasonService;
use Filament\Facades\Filament;

test('full game lifecycle: draft → episodes → post-episode → pre-episode swap', function () {
    Filament::setCurrentPanel(Filament::getPanel('player'));

    $gs = app(GameStateService::class);
    $ss = app(SeasonService::class);
    $ds = app(DraftService::class);

    // ── SETUP: 3 players, 8 models, 2 per player ──────────────
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $charlie = User::factory()->create(['name' => 'Charlie']);

    $season = Season::factory()->create(['models_per_player' => 2]);
    $season->players()->attach([$alice->id, $bob->id, $charlie->id]);

    $m = collect();
    for ($i = 1; $i <= 8; $i++) {
        $m[$i] = TopModel::factory()->create(['season_id' => $season->id, 'name' => "Model $i"]);
    }

    $ep1 = Episode::factory()->create(['season_id' => $season->id, 'number' => '1']);
    $ep2 = Episode::factory()->create(['season_id' => $season->id, 'number' => '2']);
    $ep3 = Episode::factory()->create(['season_id' => $season->id, 'number' => '3']);

    // ── STATE: SETUP — nothing accessible ──────────────────────
    $this->actingAs($alice);
    expect(DraftRoom::canAccess())->toBeFalse('DraftRoom not accessible during Setup');

    // ── STATE: DRAFT ───────────────────────────────────────────
    $ds->setDraftOrder($season, [$alice->id, $bob->id, $charlie->id]);
    $ss->startDraft($season);

    $this->actingAs($alice);
    expect(DraftRoom::canAccess())->toBeTrue('DraftRoom accessible during Draft');

    // Snake draft: Alice, Bob, Charlie, Charlie, Bob, Alice
    $ds->pickModel($season, $alice, $m[1]);
    $ds->pickModel($season, $bob, $m[2]);
    $ds->pickModel($season, $charlie, $m[3]);
    $ds->pickModel($season, $charlie, $m[4]);
    $ds->pickModel($season, $bob, $m[5]);
    $ds->pickModel($season, $alice, $m[6]);
    // Alice=[M1,M6] Bob=[M2,M5] Charlie=[M3,M4] Free=[M7,M8]

    expect($ds->isDraftComplete($season))->toBeTrue();

    // ── STATE: ACTIVE SEASON, ALL EPISODES UPCOMING ────────────
    $ss->activateSeason($season);
    $season->refresh();
    expect($season->status)->toBe(SeasonStatus::Active);

    $this->actingAs($alice);
    expect(DraftRoom::canAccess())->toBeFalse('DraftRoom not accessible after activation');

    // ── STATE: EPISODE 1 ACTIVE (action logging phase) ─────────
    $ep1->update(['status' => EpisodeStatus::Active, 'aired_at' => now()]);

    // Log actions: give Charlie 10 pts via M3 so score order is Alice(0) < Bob(0) <= Charlie(10)
    $action = Action::factory()->create(['season_id' => $season->id, 'multiplier' => 1.00]);
    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $m[3]->id,
        'episode_id' => $ep1->id,
        'count' => 10,
    ]);

    // ── STATE: EPISODE 1 ENDED — M1 (Alice's) eliminated ──────
    $gs->endEpisode($ep1, [$m[1]->id]);
    // Alice=[M6] Bob=[M2,M5] Charlie=[M3,M4] Free=[M7,M8]

    // ── POST-EP1: Alice picks free agent M7 ────────────────────
    $gs->pickFreeAgent($alice, $season, $m[7], $ep1);
    // Alice=[M6,M7] Bob=[M2,M5] Charlie=[M3,M4] Free=[M8]

    // ── OPTIONAL SWAP (before EP2) ─────────────────────────────
    // Alice swaps M6 for M8 via the new phase-based swap
    $gs->swapModel($alice, $season, $m[6], $m[8], $ep1);
    // Alice=[M7,M8] Bob=[M2,M5] Charlie=[M3,M4] Free=[M6]

    // Bob and Charlie choose not to swap (no action needed — purely optional)

    // ── STATE: EPISODE 2 ACTIVE ────────────────────────────────
    $ep2->update(['status' => EpisodeStatus::Active, 'aired_at' => now()]);

    // Log actions: give Bob 5 pts via M5
    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $m[5]->id,
        'episode_id' => $ep2->id,
        'count' => 5,
    ]);
    // Score: Alice=0, Bob=5, Charlie=10

    // ── STATE: EP2 ENDED — SPECIAL CASE (no free agents) ───────
    // Eliminate M7 (Alice's), M2 (Bob's), M6 (free agent from Alice's pre-ep swap)
    $gs->endEpisode($ep2, [$m[7]->id, $m[2]->id, $m[6]->id]);
    // After auto-drops:
    // Alice=[M8] Bob=[M5] Charlie=[M3,M4] Free=[] (M6,M7,M2 all eliminated)

    $freeAgents = $ss->getFreeAgents($season);
    expect($freeAgents)->toBeEmpty('No free agents — special case triggered');

    // ── POST-EP2: Mandatory drops create free agents ───────────
    $gs->dropModel($alice, $season, $m[8], isMandatory: true, episode: $ep2);
    $gs->dropModel($bob, $season, $m[5], isMandatory: true, episode: $ep2);
    // Alice=[] Bob=[] Charlie=[M3,M4] Free=[M8,M5]

    // Charlie swaps M4 for M5
    $gs->swapModel($charlie, $season, $m[4], $m[5], $ep2);
    // Alice=[] Bob=[] Charlie=[M3,M5] Free=[M4,M8]

    // ── STATE: EPISODE 3 ACTIVE ────────────────────────────────
    $ep3->update(['status' => EpisodeStatus::Active, 'aired_at' => now()]);

    // ── VERIFY FINAL MODEL OWNERSHIP ───────────────────────────
    $aliceActive = PlayerModel::where('user_id', $alice->id)->where('season_id', $season->id)->active()->count();
    $bobActive = PlayerModel::where('user_id', $bob->id)->where('season_id', $season->id)->active()->count();
    $charlieModels = PlayerModel::where('user_id', $charlie->id)
        ->where('season_id', $season->id)
        ->active()
        ->pluck('top_model_id')
        ->sort()
        ->values()
        ->toArray();

    expect($aliceActive)->toBe(0)
        ->and($bobActive)->toBe(0)
        ->and($charlieModels)->toBe([$m[3]->id, $m[5]->id]);
});
