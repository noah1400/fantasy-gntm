<?php

use App\Enums\EpisodeStatus;
use App\Enums\GameEventType;
use App\Enums\SeasonStatus;
use App\Filament\Player\Pages\DraftRoom;
use App\Filament\Player\Pages\PostEpisode;
use App\Filament\Player\Pages\PreEpisodeSwap;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\GameEvent;
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
    expect(DraftRoom::canAccess())->toBeFalse('DraftRoom not accessible during Setup')
        ->and(PostEpisode::canAccess())->toBeFalse('PostEpisode not accessible during Setup')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap not accessible during Setup');

    // ── STATE: DRAFT ───────────────────────────────────────────
    $ds->setDraftOrder($season, [$alice->id, $bob->id, $charlie->id]);
    $ss->startDraft($season);

    $this->actingAs($alice);
    expect(DraftRoom::canAccess())->toBeTrue('DraftRoom accessible during Draft')
        ->and(PostEpisode::canAccess())->toBeFalse('PostEpisode not accessible during Draft')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap not accessible during Draft');

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
    expect(DraftRoom::canAccess())->toBeFalse('DraftRoom not accessible after activation')
        ->and(PostEpisode::canAccess())->toBeFalse('PostEpisode not accessible — no ended episode')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap not accessible — no ended episode');

    // ── STATE: EPISODE 1 ACTIVE (action logging phase) ─────────
    $ep1->update(['status' => EpisodeStatus::Active, 'aired_at' => now()]);

    $this->actingAs($alice);
    expect(PostEpisode::canAccess())->toBeFalse('PostEpisode not accessible — no ended episode')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked by active episode');

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

    // PostEpisode accessible for affected player, PreEpisodeSwap blocked
    $this->actingAs($alice);
    expect(PostEpisode::canAccess())->toBeTrue('Alice needs free_agent_pick')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — pending post-episode');

    $this->actingAs($bob);
    expect(PostEpisode::canAccess())->toBeFalse('Bob has no Phase 1 action')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — pending post-episode');

    $this->actingAs($charlie);
    expect(PostEpisode::canAccess())->toBeFalse('Charlie has no Phase 1 action')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — pending post-episode');

    // ── POST-EP1: Alice picks free agent M7 ────────────────────
    $gs->pickFreeAgent($alice, $season, $m[7], $ep1);
    // Alice=[M6,M7] Bob=[M2,M5] Charlie=[M3,M4] Free=[M8]

    // Phase 2: optional_swap with turn order (lowest score first)
    $actions = $gs->getRequiredPostEpisodeActions($season, $ep1);
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('optional_swap');
    $firstSwapper = $actions[0]['user'];

    // Verify page access: only the current turn player can access PostEpisode
    $this->actingAs($firstSwapper);
    expect(PostEpisode::canAccess())->toBeTrue('Current turn player can access PostEpisode');

    $nonSwappers = collect([$alice, $bob, $charlie])->filter(fn ($u) => $u->id !== $firstSwapper->id);
    foreach ($nonSwappers as $player) {
        $this->actingAs($player);
        expect(PostEpisode::canAccess())->toBeFalse("Non-turn player {$player->name} cannot access PostEpisode");
    }

    // Verify PreEpisodeSwap still blocked for everyone
    $this->actingAs($alice);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — optional_swap pending');

    // First player skips → second player gets turn
    GameEvent::create([
        'season_id' => $season->id,
        'episode_id' => $ep1->id,
        'type' => GameEventType::SwapSkipped,
        'payload' => ['user_id' => $firstSwapper->id, 'user_name' => $firstSwapper->name],
    ]);

    $actions = $gs->getRequiredPostEpisodeActions($season, $ep1);
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('optional_swap')
        ->and($actions[0]['user']->id)->not->toBe($firstSwapper->id, 'Different player gets next turn');
    $secondSwapper = $actions[0]['user'];

    // Verify page-level turn handoff
    $this->actingAs($secondSwapper);
    expect(PostEpisode::canAccess())->toBeTrue('Second turn player can access PostEpisode');

    $this->actingAs($firstSwapper);
    expect(PostEpisode::canAccess())->toBeFalse('First player already skipped — no access');

    // Second player skips → third player (Charlie, highest score) gets turn
    GameEvent::create([
        'season_id' => $season->id,
        'episode_id' => $ep1->id,
        'type' => GameEventType::SwapSkipped,
        'payload' => ['user_id' => $secondSwapper->id, 'user_name' => $secondSwapper->name],
    ]);

    $actions = $gs->getRequiredPostEpisodeActions($season, $ep1);
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('optional_swap')
        ->and($actions[0]['user']->id)->toBe($charlie->id, 'Charlie (highest score) goes last');

    // Charlie skips
    GameEvent::create([
        'season_id' => $season->id,
        'episode_id' => $ep1->id,
        'type' => GameEventType::SwapSkipped,
        'payload' => ['user_id' => $charlie->id, 'user_name' => $charlie->name],
    ]);

    // ── ALL POST-EP1 ACTIONS DONE → PRE-EPISODE SWAP OPENS ────
    expect($gs->getRequiredPostEpisodeActions($season, $ep1))->toBeEmpty();

    $this->actingAs($alice);
    expect(PostEpisode::canAccess())->toBeFalse('PostEpisode done — no more actions')
        ->and(PreEpisodeSwap::canAccess())->toBeTrue('PreEpisodeSwap now accessible for Alice');

    $this->actingAs($bob);
    expect(PostEpisode::canAccess())->toBeFalse()
        ->and(PreEpisodeSwap::canAccess())->toBeTrue('PreEpisodeSwap accessible for Bob too');

    $this->actingAs($charlie);
    expect(PreEpisodeSwap::canAccess())->toBeTrue('PreEpisodeSwap accessible for Charlie too');

    // ── PRE-EPISODE SWAP (before EP2) ──────────────────────────
    // Alice swaps M6 for M8 (no turn order — first come first served)
    $gs->preEpisodeSwap($alice, $season, $m[6], $m[8], $ep2, $ep1);
    // Alice=[M7,M8] Bob=[M2,M5] Charlie=[M3,M4] Free=[M6]

    $this->actingAs($alice);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('Alice already used her swap');

    $this->actingAs($bob);
    expect(PreEpisodeSwap::canAccess())->toBeTrue('Bob can still swap');

    $this->actingAs($charlie);
    expect(PreEpisodeSwap::canAccess())->toBeTrue('Charlie can still swap');

    // Bob and Charlie choose not to swap (no action needed — purely optional)

    // ── STATE: EPISODE 2 ACTIVE ────────────────────────────────
    $ep2->update(['status' => EpisodeStatus::Active, 'aired_at' => now()]);

    $this->actingAs($alice);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('Alice: PreEpisodeSwap blocked — active episode')
        ->and(PostEpisode::canAccess())->toBeFalse('Alice: PostEpisode — ep2 not ended yet');

    $this->actingAs($bob);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('Bob: PreEpisodeSwap blocked — active episode')
        ->and(PostEpisode::canAccess())->toBeFalse('Bob: PostEpisode — ep2 not ended yet');

    $this->actingAs($charlie);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('Charlie: PreEpisodeSwap blocked — active episode')
        ->and(PostEpisode::canAccess())->toBeFalse('Charlie: PostEpisode — ep2 not ended yet');

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

    // Alice and Bob: had models eliminated, no free agents, have active models → mandatory_drop
    $actions = $gs->getRequiredPostEpisodeActions($season, $ep2);
    $mandatoryDrops = collect($actions)->where('action', 'mandatory_drop');
    expect($mandatoryDrops)->toHaveCount(2, 'Both Alice and Bob need mandatory_drop');

    $this->actingAs($alice);
    expect(PostEpisode::canAccess())->toBeTrue('Alice needs mandatory_drop')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — pending post-episode');

    $this->actingAs($bob);
    expect(PostEpisode::canAccess())->toBeTrue('Bob needs mandatory_drop')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — pending post-episode');

    $this->actingAs($charlie);
    expect(PostEpisode::canAccess())->toBeFalse('Charlie has no eliminated model in ep2')
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — pending post-episode');

    // ── POST-EP2: Mandatory drops create free agents ───────────
    $gs->dropModel($alice, $season, $m[8], isMandatory: true, episode: $ep2);
    $gs->dropModel($bob, $season, $m[5], isMandatory: true, episode: $ep2);
    // Alice=[] Bob=[] Charlie=[M3,M4] Free=[M8,M5]

    // Phase 2: optional swap — turn order by lowest score
    // Alice(0) and Bob(5) have 0 active models → can't swap
    // Only Charlie has active models
    $actions = $gs->getRequiredPostEpisodeActions($season, $ep2);
    expect($actions)->toHaveCount(1)
        ->and($actions[0]['action'])->toBe('optional_swap')
        ->and($actions[0]['user']->id)->toBe($charlie->id, 'Only Charlie (with active models) can swap');

    // Charlie swaps M4 for M5
    $gs->swapModel($charlie, $season, $m[4], $m[5], $ep2);
    // Alice=[] Bob=[] Charlie=[M3,M5] Free=[M4,M8]

    // ── ALL POST-EP2 DONE ──────────────────────────────────────
    expect($gs->getRequiredPostEpisodeActions($season, $ep2))->toBeEmpty();

    // Alice and Bob have 0 active models → PreEpisodeSwap not accessible
    $this->actingAs($alice);
    expect(PostEpisode::canAccess())->toBeFalse()
        ->and(PreEpisodeSwap::canAccess())->toBeFalse('Alice has no active models');

    $this->actingAs($bob);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('Bob has no active models');

    // Charlie CAN access pre-episode swap
    $this->actingAs($charlie);
    expect(PostEpisode::canAccess())->toBeFalse()
        ->and(PreEpisodeSwap::canAccess())->toBeTrue('Charlie can pre-episode swap before ep3');

    // ── STATE: EPISODE 3 ACTIVE ────────────────────────────────
    $ep3->update(['status' => EpisodeStatus::Active, 'aired_at' => now()]);

    $this->actingAs($charlie);
    expect(PreEpisodeSwap::canAccess())->toBeFalse('PreEpisodeSwap blocked — active episode')
        ->and(PostEpisode::canAccess())->toBeFalse('PostEpisode — ep3 not ended yet');

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
