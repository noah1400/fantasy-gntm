# Admin-Driven Game Phases Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the automatic post-episode state machine with an admin-controlled phase queue where the admin explicitly creates game phases and players respond.

**Architecture:** New `game_phases` table stores admin-created phases as a queue. A `PhaseService` manages the queue lifecycle (create, advance, complete). The admin uses a new "Game Control" Filament page to manage phases. Players use a new unified "My Actions" page that adapts to the active phase. The existing `GameStateService` atomic operations (pick, drop, swap) are preserved but the auto-calculation logic (`getRequiredPostEpisodeActions`) is removed.

**Tech Stack:** Laravel 12, Filament v5, Pest 4, Livewire 4, Tailwind CSS v3

---

### Task 1: Create GamePhaseType and GamePhaseStatus Enums

**Files:**
- Create: `app/Enums/GamePhaseType.php`
- Create: `app/Enums/GamePhaseStatus.php`

**Step 1: Create GamePhaseStatus enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum GamePhaseStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::Completed => 'info',
            self::Cancelled => 'danger',
        };
    }
}
```

**Step 2: Create GamePhaseType enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum GamePhaseType: string implements HasColor, HasLabel
{
    case MandatoryDrop = 'mandatory_drop';
    case PickRound = 'pick_round';
    case OptionalSwap = 'optional_swap';
    case TradingPhase = 'trading_phase';
    case ForceAssign = 'force_assign';
    case EliminatePlayer = 'eliminate_player';
    case SkipPlayer = 'skip_player';
    case Redistribute = 'redistribute';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::MandatoryDrop => 'Mandatory Drop',
            self::PickRound => 'Pick Round',
            self::OptionalSwap => 'Optional Swap',
            self::TradingPhase => 'Trading Phase',
            self::ForceAssign => 'Force Assign',
            self::EliminatePlayer => 'Eliminate Player',
            self::SkipPlayer => 'Skip Player',
            self::Redistribute => 'Redistribute',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::MandatoryDrop => 'warning',
            self::PickRound => 'success',
            self::OptionalSwap => 'info',
            self::TradingPhase => 'info',
            self::ForceAssign => 'danger',
            self::EliminatePlayer => 'danger',
            self::SkipPlayer => 'gray',
            self::Redistribute => 'warning',
        };
    }

    public function isInstant(): bool
    {
        return in_array($this, [
            self::ForceAssign,
            self::EliminatePlayer,
            self::SkipPlayer,
            self::Redistribute,
        ]);
    }

    public function isSimultaneous(): bool
    {
        return in_array($this, [
            self::MandatoryDrop,
            self::TradingPhase,
        ]);
    }

    public function isTurnBased(): bool
    {
        return in_array($this, [
            self::PickRound,
            self::OptionalSwap,
        ]);
    }
}
```

**Step 3: Commit**

```bash
git add app/Enums/GamePhaseType.php app/Enums/GamePhaseStatus.php
git commit -m "feat: add GamePhaseType and GamePhaseStatus enums"
```

---

### Task 2: Create game_phases Migration and GamePhase Model

**Files:**
- Create: migration via `php artisan make:migration create_game_phases_table`
- Create: `app/Models/GamePhase.php` via `php artisan make:model GamePhase --factory`
- Modify: `app/Models/Season.php` (add `gamePhases()` relationship)
- Modify: `app/Models/GameEvent.php` (add `game_phase_id` fillable + relationship)

**Step 1: Create migration for game_phases table**

Run: `php artisan make:migration create_game_phases_table --no-interaction`

Then edit the migration:

```php
public function up(): void
{
    Schema::create('game_phases', function (Blueprint $table) {
        $table->id();
        $table->foreignId('season_id')->constrained()->cascadeOnDelete();
        $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
        $table->string('type');
        $table->json('config')->nullable();
        $table->unsignedInteger('position')->default(0);
        $table->string('status')->default('pending');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
    });
}
```

**Step 2: Create migration to add game_phase_id to game_events**

Run: `php artisan make:migration add_game_phase_id_to_game_events_table --no-interaction`

```php
public function up(): void
{
    Schema::table('game_events', function (Blueprint $table) {
        $table->foreignId('game_phase_id')->nullable()->after('episode_id')->constrained()->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('game_events', function (Blueprint $table) {
        $table->dropConstrainedForeignId('game_phase_id');
    });
}
```

**Step 3: Create GamePhase model**

`php artisan make:model GamePhase --factory --no-interaction` will scaffold the file. Then edit:

```php
<?php

namespace App\Models;

use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamePhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'episode_id',
        'type',
        'config',
        'position',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => GamePhaseType::class,
            'status' => GamePhaseStatus::class,
            'config' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GamePhaseStatus::Active);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', GamePhaseStatus::Pending);
    }
}
```

**Step 4: Create GamePhaseFactory**

Edit `database/factories/GamePhaseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GamePhase>
 */
class GamePhaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'episode_id' => null,
            'type' => GamePhaseType::MandatoryDrop,
            'config' => [],
            'position' => 0,
            'status' => GamePhaseStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GamePhaseStatus::Active,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => GamePhaseStatus::Completed,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function mandatoryDrop(int $targetModelCount = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => GamePhaseType::MandatoryDrop,
            'config' => ['target_model_count' => $targetModelCount],
        ]);
    }

    public function pickRound(int $eligibleBelow = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => GamePhaseType::PickRound,
            'config' => ['eligible_below' => $eligibleBelow],
        ]);
    }

    public function optionalSwap(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => GamePhaseType::OptionalSwap,
            'config' => [],
        ]);
    }

    public function tradingPhase(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => GamePhaseType::TradingPhase,
            'config' => [],
        ]);
    }
}
```

**Step 5: Add relationships to existing models**

In `app/Models/Season.php`, add after `gameEvents()`:

```php
public function gamePhases(): HasMany
{
    return $this->hasMany(GamePhase::class);
}
```

In `app/Models/GameEvent.php`, add `game_phase_id` to fillable and add relationship:

```php
protected $fillable = [
    'season_id',
    'episode_id',
    'game_phase_id',
    'type',
    'payload',
];

public function gamePhase(): BelongsTo
{
    return $this->belongsTo(GamePhase::class);
}
```

**Step 6: Run migrations**

Run: `php artisan migrate`

**Step 7: Commit**

```bash
git add -A
git commit -m "feat: add game_phases table, GamePhase model, and game_phase_id FK on game_events"
```

---

### Task 3: Create PhaseService — Core Queue Engine

**Files:**
- Create: `app/Services/PhaseService.php`
- Test: `tests/Feature/PhaseServiceTest.php`

**Step 1: Write failing tests for PhaseService**

Create `tests/Feature/PhaseServiceTest.php` via `php artisan make:test PhaseServiceTest --pest --no-interaction`:

```php
<?php

use App\Enums\GameEventType;
use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Models\Episode;
use App\Models\GameEvent;
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
    $phase = $this->service->createPhase(
        $this->season,
        GamePhaseType::MandatoryDrop,
        ['target_model_count' => 1],
    );

    expect($phase->fresh()->status)->toBe(GamePhaseStatus::Active)
        ->and($phase->fresh()->started_at)->not->toBeNull();
});

it('queues phase as pending when another phase is active', function () {
    $active = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $pending = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    expect($active->fresh()->status)->toBe(GamePhaseStatus::Active)
        ->and($pending->fresh()->status)->toBe(GamePhaseStatus::Pending)
        ->and($pending->position)->toBe(1);
});

it('returns the active phase for a season', function () {
    $phase = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

    expect($this->service->getActivePhase($this->season)->id)->toBe($phase->id);
});

it('returns null when no active phase', function () {
    expect($this->service->getActivePhase($this->season))->toBeNull();
});

it('advances queue when phase is completed', function () {
    $phase1 = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $phase2 = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);

    $this->service->closePhase($phase1);

    expect($phase1->fresh()->status)->toBe(GamePhaseStatus::Completed)
        ->and($phase2->fresh()->status)->toBe(GamePhaseStatus::Active);
});

it('cancels a pending phase', function () {
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

    $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);

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

it('getPlayerAction returns optional_swap for TradingPhase', function () {
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
    $phase1 = $this->service->createPhase($this->season, GamePhaseType::MandatoryDrop, ['target_model_count' => 1]);
    $phase2 = $this->service->createPhase($this->season, GamePhaseType::PickRound, ['eligible_below' => 2]);
    $phase3 = $this->service->createPhase($this->season, GamePhaseType::OptionalSwap, []);

    // Reorder: swap phase2 and phase3
    $this->service->reorderPhases($this->season, [$phase3->id, $phase2->id]);

    expect($phase3->fresh()->position)->toBeLessThan($phase2->fresh()->position);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/PhaseServiceTest.php`
Expected: FAIL (PhaseService class doesn't exist)

**Step 3: Implement PhaseService**

Create `app/Services/PhaseService.php`:

```php
<?php

namespace App\Services;

use App\Enums\GameEventType;
use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Enums\PickType;
use App\Models\GameEvent;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Notifications\Notification;

class PhaseService
{
    public function __construct(
        protected ScoringService $scoringService,
        protected SeasonService $seasonService,
        protected GameStateService $gameStateService,
    ) {}

    public function createPhase(Season $season, GamePhaseType $type, array $config = [], ?Episode $episode = null): GamePhase
    {
        $maxPosition = $season->gamePhases()
            ->whereIn('status', [GamePhaseStatus::Pending, GamePhaseStatus::Active])
            ->max('position') ?? -1;

        $phase = GamePhase::create([
            'season_id' => $season->id,
            'episode_id' => $episode?->id,
            'type' => $type,
            'config' => $config,
            'position' => $maxPosition + 1,
            'status' => GamePhaseStatus::Pending,
        ]);

        if ($type->isInstant()) {
            $this->executeInstantPhase($phase);
            return $phase;
        }

        // Auto-activate if no active phase
        if (! $this->getActivePhase($season)) {
            $this->activatePhase($phase);
        }

        return $phase;
    }

    public function getActivePhase(Season $season): ?GamePhase
    {
        return $season->gamePhases()->active()->first();
    }

    /**
     * Determine what action a player should take right now.
     *
     * @return array{action: string, phase: GamePhase, reason: string}|null
     */
    public function getPlayerAction(Season $season, User $user): ?array
    {
        $phase = $this->getActivePhase($season);
        if (! $phase) {
            return null;
        }

        $isEliminated = $season->players()
            ->where('user_id', $user->id)
            ->wherePivot('is_eliminated', true)
            ->exists();

        if ($isEliminated) {
            return null;
        }

        return match ($phase->type) {
            GamePhaseType::MandatoryDrop => $this->getMandatoryDropAction($phase, $season, $user),
            GamePhaseType::PickRound => $this->getPickRoundAction($phase, $season, $user),
            GamePhaseType::OptionalSwap => $this->getOptionalSwapAction($phase, $season, $user),
            GamePhaseType::TradingPhase => $this->getTradingPhaseAction($phase, $season, $user),
            default => null,
        };
    }

    public function checkPhaseCompletion(GamePhase $phase): void
    {
        if ($phase->status !== GamePhaseStatus::Active) {
            return;
        }

        $isComplete = match ($phase->type) {
            GamePhaseType::MandatoryDrop => $this->isMandatoryDropComplete($phase),
            GamePhaseType::PickRound => $this->isPickRoundComplete($phase),
            GamePhaseType::OptionalSwap => $this->isOptionalSwapComplete($phase),
            GamePhaseType::TradingPhase => false, // Admin closes manually
            default => true,
        };

        if ($isComplete) {
            $this->completePhase($phase);
        }
    }

    public function closePhase(GamePhase $phase): void
    {
        $this->completePhase($phase);
    }

    public function cancelPhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Cancelled,
        ]);
    }

    public function advanceQueue(Season $season): void
    {
        $next = $season->gamePhases()
            ->pending()
            ->orderBy('position')
            ->first();

        if ($next) {
            $this->activatePhase($next);
        }
    }

    public function reorderPhases(Season $season, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            GamePhase::where('id', $id)
                ->where('season_id', $season->id)
                ->where('status', GamePhaseStatus::Pending)
                ->update(['position' => $index]);
        }
    }

    // --- Private helpers ---

    private function activatePhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Active,
            'started_at' => now(),
        ]);

        // Check if already complete (e.g., MandatoryDrop where everyone is already at target)
        $this->checkPhaseCompletion($phase);

        if ($phase->fresh()->status === GamePhaseStatus::Active) {
            $this->notifyAffectedPlayers($phase);
        }
    }

    private function completePhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->advanceQueue($phase->season);
    }

    private function executeInstantPhase(GamePhase $phase): void
    {
        $phase->update([
            'status' => GamePhaseStatus::Active,
            'started_at' => now(),
        ]);

        match ($phase->type) {
            GamePhaseType::ForceAssign => $this->executeForceAssign($phase),
            GamePhaseType::EliminatePlayer => $this->executeEliminatePlayer($phase),
            GamePhaseType::SkipPlayer => $this->executeSkipPlayer($phase),
            GamePhaseType::Redistribute => $this->executeRedistribute($phase),
            default => null,
        };

        $this->completePhase($phase);
    }

    private function executeForceAssign(GamePhase $phase): void
    {
        $user = User::findOrFail($phase->config['user_id']);
        $topModel = TopModel::findOrFail($phase->config['top_model_id']);

        $this->gameStateService->pickFreeAgent(
            $user,
            $phase->season,
            $topModel,
            $phase->episode,
        );
    }

    private function executeEliminatePlayer(GamePhase $phase): void
    {
        $user = User::findOrFail($phase->config['user_id']);
        $this->gameStateService->eliminatePlayer($user, $phase->season, $phase->episode);
    }

    private function executeSkipPlayer(GamePhase $phase): void
    {
        $user = User::findOrFail($phase->config['user_id']);

        GameEvent::create([
            'season_id' => $phase->season_id,
            'episode_id' => $phase->episode_id,
            'game_phase_id' => $phase->config['parent_phase_id'] ?? null,
            'type' => GameEventType::SwapSkipped,
            'payload' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'skipped_by_admin' => true,
            ],
        ]);
    }

    private function executeRedistribute(GamePhase $phase): void
    {
        // TODO: Implement redistribution strategies
    }

    // --- Action resolvers per phase type ---

    private function getMandatoryDropAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $targetCount = $phase->config['target_model_count'] ?? 1;

        $activeModelCount = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->count();

        if ($activeModelCount <= $targetCount) {
            return null;
        }

        return [
            'action' => 'mandatory_drop',
            'phase' => $phase,
            'reason' => "You must drop to {$targetCount} model(s).",
        ];
    }

    private function getPickRoundAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $eligibleBelow = $phase->config['eligible_below'] ?? 2;

        $activeModelCount = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->count();

        if ($activeModelCount >= $eligibleBelow) {
            return null;
        }

        // Check if this player already picked in this phase
        $alreadyPicked = GameEvent::query()
            ->where('game_phase_id', $phase->id)
            ->where('type', GameEventType::FreeAgentPick)
            ->whereJsonContains('payload->user_id', $user->id)
            ->exists();

        if ($alreadyPicked) {
            return null;
        }

        $freeAgents = $this->seasonService->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return null;
        }

        // Turn-based: check if it's this player's turn (lowest points first among eligible)
        $currentTurnUser = $this->getCurrentTurnPlayer($phase, $season);

        if (! $currentTurnUser || $currentTurnUser->id !== $user->id) {
            return [
                'action' => 'waiting',
                'phase' => $phase,
                'reason' => 'Waiting for other players to pick.',
            ];
        }

        return [
            'action' => 'free_agent_pick',
            'phase' => $phase,
            'reason' => 'Pick a free agent.',
        ];
    }

    private function getOptionalSwapAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $hasActiveModels = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if (! $hasActiveModels) {
            return null;
        }

        $alreadyActed = GameEvent::query()
            ->where('game_phase_id', $phase->id)
            ->whereIn('type', [GameEventType::ModelSwap, GameEventType::SwapSkipped])
            ->whereJsonContains('payload->user_id', $user->id)
            ->exists();

        if ($alreadyActed) {
            return null;
        }

        $freeAgents = $this->seasonService->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return null;
        }

        // Turn-based: check if it's this player's turn
        $currentTurnUser = $this->getCurrentTurnPlayerForSwap($phase, $season);

        if (! $currentTurnUser || $currentTurnUser->id !== $user->id) {
            return [
                'action' => 'waiting',
                'phase' => $phase,
                'reason' => 'Waiting for other players to swap.',
            ];
        }

        return [
            'action' => 'optional_swap',
            'phase' => $phase,
            'reason' => 'You may swap one model with a free agent.',
        ];
    }

    private function getTradingPhaseAction(GamePhase $phase, Season $season, User $user): ?array
    {
        $hasActiveModels = PlayerModel::query()
            ->where('user_id', $user->id)
            ->where('season_id', $season->id)
            ->active()
            ->exists();

        if (! $hasActiveModels) {
            return null;
        }

        $freeAgents = $this->seasonService->getFreeAgents($season);
        if ($freeAgents->isEmpty()) {
            return null;
        }

        return [
            'action' => 'trading_swap',
            'phase' => $phase,
            'reason' => 'Swap any of your models for a free agent.',
        ];
    }

    // --- Turn order helpers ---

    private function getCurrentTurnPlayer(GamePhase $phase, Season $season): ?User
    {
        $activePlayers = $season->players()->wherePivot('is_eliminated', false)->get();
        $eligibleBelow = $phase->config['eligible_below'] ?? 2;

        $candidates = [];
        foreach ($activePlayers as $player) {
            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $season->id)
                ->active()
                ->count();

            if ($activeModelCount >= $eligibleBelow) {
                continue;
            }

            $alreadyPicked = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->where('type', GameEventType::FreeAgentPick)
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if ($alreadyPicked) {
                continue;
            }

            $candidates[] = $player;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $this->scoringService->getPlayerPoints($a, $season)
            <=> $this->scoringService->getPlayerPoints($b, $season));

        return $candidates[0];
    }

    private function getCurrentTurnPlayerForSwap(GamePhase $phase, Season $season): ?User
    {
        $activePlayers = $season->players()->wherePivot('is_eliminated', false)->get();

        $candidates = [];
        foreach ($activePlayers as $player) {
            $hasActiveModels = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $season->id)
                ->active()
                ->exists();

            if (! $hasActiveModels) {
                continue;
            }

            $alreadyActed = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->whereIn('type', [GameEventType::ModelSwap, GameEventType::SwapSkipped])
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if ($alreadyActed) {
                continue;
            }

            $candidates[] = $player;
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $this->scoringService->getPlayerPoints($a, $season)
            <=> $this->scoringService->getPlayerPoints($b, $season));

        return $candidates[0];
    }

    // --- Completion checks ---

    private function isMandatoryDropComplete(GamePhase $phase): bool
    {
        $targetCount = $phase->config['target_model_count'] ?? 1;
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();

        foreach ($activePlayers as $player) {
            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $phase->season_id)
                ->active()
                ->count();

            if ($activeModelCount > $targetCount) {
                return false;
            }
        }

        return true;
    }

    private function isPickRoundComplete(GamePhase $phase): bool
    {
        $eligibleBelow = $phase->config['eligible_below'] ?? 2;
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();
        $freeAgents = $this->seasonService->getFreeAgents($phase->season);

        if ($freeAgents->isEmpty()) {
            return true;
        }

        foreach ($activePlayers as $player) {
            $activeModelCount = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $phase->season_id)
                ->active()
                ->count();

            if ($activeModelCount >= $eligibleBelow) {
                continue;
            }

            $alreadyPicked = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->where('type', GameEventType::FreeAgentPick)
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if (! $alreadyPicked) {
                return false;
            }
        }

        return true;
    }

    private function isOptionalSwapComplete(GamePhase $phase): bool
    {
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();
        $freeAgents = $this->seasonService->getFreeAgents($phase->season);

        if ($freeAgents->isEmpty()) {
            return true;
        }

        foreach ($activePlayers as $player) {
            $hasActiveModels = PlayerModel::query()
                ->where('user_id', $player->id)
                ->where('season_id', $phase->season_id)
                ->active()
                ->exists();

            if (! $hasActiveModels) {
                continue;
            }

            $alreadyActed = GameEvent::query()
                ->where('game_phase_id', $phase->id)
                ->whereIn('type', [GameEventType::ModelSwap, GameEventType::SwapSkipped])
                ->whereJsonContains('payload->user_id', $player->id)
                ->exists();

            if (! $alreadyActed) {
                return false;
            }
        }

        return true;
    }

    // --- Notifications ---

    private function notifyAffectedPlayers(GamePhase $phase): void
    {
        $activePlayers = $phase->season->players()->wherePivot('is_eliminated', false)->get();

        foreach ($activePlayers as $player) {
            $action = $this->getPlayerAction($phase->season, $player);
            if (! $action || $action['action'] === 'waiting') {
                continue;
            }

            $title = match ($action['action']) {
                'mandatory_drop' => 'You must drop a model',
                'free_agent_pick' => 'Pick a free agent',
                'optional_swap' => 'You may swap a model',
                'trading_swap' => 'Trading phase is open',
                default => 'Action required',
            };

            Notification::make()
                ->title($title)
                ->body($action['reason'])
                ->sendToDatabase($player);
        }
    }
}
```

Note: The `Episode` import is missing from the `createPhase` type hint — add `use App\Models\Episode;` at the top.

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/PhaseServiceTest.php`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Services/PhaseService.php tests/Feature/PhaseServiceTest.php
git commit -m "feat: add PhaseService with queue engine, turn order, and completion checks"
```

---

### Task 4: Simplify GameStateService — Remove Auto-Phase Logic

**Files:**
- Modify: `app/Services/GameStateService.php`
- Modify: `tests/Feature/GameStateServiceTest.php`

**Step 1: Strip endEpisode to only end episode + eliminate models**

Remove from `endEpisode()`:
- The entire auto-elimination block (lines 57-108)
- The notification block (lines 110-125)

Keep only: episode status update + model elimination loop.

Updated `endEpisode()`:

```php
public function endEpisode(Episode $episode, array $eliminatedModelIds = []): void
{
    $episode->update([
        'status' => EpisodeStatus::Ended,
        'ended_at' => now(),
    ]);

    $season = $episode->season;

    foreach ($eliminatedModelIds as $modelId) {
        $topModel = TopModel::find($modelId);
        if ($topModel && ! $topModel->is_eliminated) {
            $topModel->update([
                'is_eliminated' => true,
                'eliminated_in_episode_id' => $episode->id,
            ]);

            PlayerModel::query()
                ->where('top_model_id', $topModel->id)
                ->where('season_id', $season->id)
                ->active()
                ->update(['dropped_after_episode_id' => $episode->id]);

            GameEvent::create([
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'type' => GameEventType::Elimination,
                'payload' => [
                    'top_model_id' => $topModel->id,
                    'top_model_name' => $topModel->name,
                ],
            ]);
        }
    }
}
```

**Step 2: Remove `getRequiredPostEpisodeActions()`, `playerHasEvent()`, `playerEventCount()`**

Delete these three methods entirely. The PhaseService replaces them.

**Step 3: Add `game_phase_id` parameter to atomic operations**

Update `pickFreeAgent()`, `dropModel()`, and `swapModel()` to accept an optional `?GamePhase $phase = null` parameter. Pass `$phase?->id` when creating GameEvent records:

```php
GameEvent::create([
    'season_id' => $season->id,
    'episode_id' => $episode?->id,
    'game_phase_id' => $phase?->id,
    'type' => GameEventType::FreeAgentPick,
    // ... payload
]);
```

**Step 4: Update tests**

Remove/update tests that test `getRequiredPostEpisodeActions()` behavior (these are now PhaseService tests). Keep tests for:
- `endEpisode` marks episode as ended
- `endEpisode` eliminates models
- `endEpisode` auto-drops PlayerModel records
- `pickFreeAgent`, `dropModel`, `swapModel`, `eliminatePlayer` atomic tests

Remove tests:
- All `getRequiredPostEpisodeActions` tests (replaced by PhaseService tests)
- All notification tests tied to auto-phases
- Auto-elimination tests (admin handles this now)

**Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/GameStateServiceTest.php`
Expected: All remaining tests pass

**Step 6: Commit**

```bash
git add app/Services/GameStateService.php tests/Feature/GameStateServiceTest.php
git commit -m "refactor: strip auto-phase logic from GameStateService, keep atomic operations"
```

---

### Task 5: Create Admin Game Control Page

**Files:**
- Create: `app/Filament/Admin/Pages/GameControl.php`
- Create: `resources/views/filament/admin/pages/game-control.blade.php`

**Step 1: Create the Filament page**

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use App\Enums\SeasonStatus;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use App\Services\PhaseService;
use App\Services\ScoringService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class GameControl extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Game';

    protected static ?string $navigationLabel = 'Game Control';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.admin.pages.game-control';

    public ?int $selectedSeasonId = null;

    // Add Phase form
    public ?string $newPhaseType = null;
    public ?int $newPhaseTargetModelCount = 1;
    public ?int $newPhaseEligibleBelow = 2;

    // Quick Action forms
    public ?int $forceAssignUserId = null;
    public ?int $forceAssignModelId = null;
    public ?int $eliminatePlayerId = null;

    public function mount(): void
    {
        $season = Season::query()->where('status', SeasonStatus::Active)->latest()->first();
        $this->selectedSeasonId = $season?->id;
    }

    public function getSeasonProperty(): ?Season
    {
        return $this->selectedSeasonId ? Season::find($this->selectedSeasonId) : null;
    }

    public function getPlayerStatusProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        $scoringService = app(ScoringService::class);

        return $this->season->players()->wherePivot('is_eliminated', false)->get()->map(fn (User $player) => [
            'user' => $player,
            'active_models' => PlayerModel::where('user_id', $player->id)->where('season_id', $this->season->id)->active()->with('topModel')->get(),
            'points' => $scoringService->getPlayerPoints($player, $this->season),
        ])->sortBy('points');
    }

    public function getFreeAgentsProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return app(SeasonService::class)->getFreeAgents($this->season);
    }

    public function getPhaseQueueProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return $this->season->gamePhases()
            ->whereIn('status', [GamePhaseStatus::Active, GamePhaseStatus::Pending])
            ->orderBy('position')
            ->get();
    }

    public function getCompletedPhasesProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return $this->season->gamePhases()
            ->whereIn('status', [GamePhaseStatus::Completed, GamePhaseStatus::Cancelled])
            ->latest('completed_at')
            ->limit(10)
            ->get();
    }

    public function addPhase(): void
    {
        if (! $this->season || ! $this->newPhaseType) {
            return;
        }

        $type = GamePhaseType::from($this->newPhaseType);
        $config = match ($type) {
            GamePhaseType::MandatoryDrop => ['target_model_count' => $this->newPhaseTargetModelCount],
            GamePhaseType::PickRound => ['eligible_below' => $this->newPhaseEligibleBelow],
            default => [],
        };

        $episode = $this->season->episodes()->where('status', 'ended')->latest('id')->first();

        app(PhaseService::class)->createPhase($this->season, $type, $config, $episode);

        Notification::make()->title("Phase added: {$type->getLabel()}")->success()->send();
        $this->newPhaseType = null;
    }

    public function closePhase(int $phaseId): void
    {
        $phase = GamePhase::findOrFail($phaseId);
        app(PhaseService::class)->closePhase($phase);
        Notification::make()->title('Phase closed.')->success()->send();
    }

    public function cancelPhase(int $phaseId): void
    {
        $phase = GamePhase::findOrFail($phaseId);
        app(PhaseService::class)->cancelPhase($phase);
        Notification::make()->title('Phase cancelled.')->success()->send();
    }

    public function forceAssign(): void
    {
        if (! $this->season || ! $this->forceAssignUserId || ! $this->forceAssignModelId) {
            Notification::make()->title('Select a player and model.')->warning()->send();
            return;
        }

        app(PhaseService::class)->createPhase(
            $this->season,
            GamePhaseType::ForceAssign,
            ['user_id' => $this->forceAssignUserId, 'top_model_id' => $this->forceAssignModelId],
        );

        Notification::make()->title('Model assigned.')->success()->send();
        $this->forceAssignUserId = null;
        $this->forceAssignModelId = null;
    }

    public function eliminatePlayer(): void
    {
        if (! $this->season || ! $this->eliminatePlayerId) {
            Notification::make()->title('Select a player.')->warning()->send();
            return;
        }

        app(PhaseService::class)->createPhase(
            $this->season,
            GamePhaseType::EliminatePlayer,
            ['user_id' => $this->eliminatePlayerId],
        );

        Notification::make()->title('Player eliminated.')->success()->send();
        $this->eliminatePlayerId = null;
    }
}
```

**Step 2: Create the Blade view**

Create `resources/views/filament/admin/pages/game-control.blade.php` with the layout from the design (player status, phase queue, quick actions). This is a large Blade file — reference the existing `end-episode.blade.php` and `draft-manager.blade.php` for Filament component patterns (`<x-filament::section>`, `<x-filament::button>`, `<x-filament::input.select>`, etc.).

**Step 3: Commit**

```bash
git add app/Filament/Admin/Pages/GameControl.php resources/views/filament/admin/pages/game-control.blade.php
git commit -m "feat: add admin Game Control page with phase queue and quick actions"
```

---

### Task 6: Create Player My Actions Page

**Files:**
- Create: `app/Filament/Player/Pages/MyActions.php`
- Create: `resources/views/filament/player/pages/my-actions.blade.php`

**Step 1: Create the Filament page**

```php
<?php

namespace App\Filament\Player\Pages;

use App\Enums\GameEventType;
use App\Enums\SeasonStatus;
use App\Models\GameEvent;
use App\Models\Season;
use App\Models\TopModel;
use App\Services\GameStateService;
use App\Services\PhaseService;
use App\Services\SeasonService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class MyActions extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'My Actions';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.player.pages.my-actions';

    public ?int $selectedPickModelId = null;
    public ?int $selectedDropModelId = null;

    public static function canAccess(): bool
    {
        $season = Season::query()->where('status', SeasonStatus::Active)->latest()->first();
        if (! $season) {
            return false;
        }

        $action = app(PhaseService::class)->getPlayerAction($season, auth()->user());

        return $action !== null;
    }

    public function getSeasonProperty(): ?Season
    {
        return Season::query()->where('status', SeasonStatus::Active)->latest()->first();
    }

    public function getMyActionProperty(): ?array
    {
        if (! $this->season) {
            return null;
        }

        return app(PhaseService::class)->getPlayerAction($this->season, auth()->user());
    }

    public function getFreeAgentsProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return app(SeasonService::class)->getFreeAgents($this->season);
    }

    public function getMyActiveModelsProperty(): Collection
    {
        if (! $this->season) {
            return collect();
        }

        return auth()->user()->playerModels()
            ->forSeason($this->season)
            ->active()
            ->with('topModel')
            ->get();
    }

    public function pickFreeAgent(int $topModelId): void
    {
        if (! $this->season || ! $this->myAction) {
            return;
        }

        $topModel = TopModel::find($topModelId);
        $episode = $this->myAction['phase']->episode;

        try {
            app(GameStateService::class)->pickFreeAgent(
                auth()->user(), $this->season, $topModel, $episode, $this->myAction['phase']
            );

            app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

            Notification::make()->title("You picked {$topModel->name}!")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function mandatoryDrop(int $topModelId): void
    {
        if (! $this->season || ! $this->myAction) {
            return;
        }

        $topModel = TopModel::find($topModelId);
        $episode = $this->myAction['phase']->episode;

        try {
            app(GameStateService::class)->dropModel(
                auth()->user(), $this->season, $topModel, isMandatory: true, episode: $episode, phase: $this->myAction['phase']
            );

            app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

            Notification::make()->title("Dropped {$topModel->name}.")->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function swapModel(): void
    {
        if (! $this->season || ! $this->selectedDropModelId || ! $this->selectedPickModelId || ! $this->myAction) {
            Notification::make()->title('Select both models for the swap.')->warning()->send();
            return;
        }

        $dropModel = TopModel::find($this->selectedDropModelId);
        $pickModel = TopModel::find($this->selectedPickModelId);
        $episode = $this->myAction['phase']->episode;

        try {
            app(GameStateService::class)->swapModel(
                auth()->user(), $this->season, $dropModel, $pickModel, $episode, $this->myAction['phase']
            );

            app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

            Notification::make()->title("Swapped {$dropModel->name} for {$pickModel->name}!")->success()->send();
            $this->selectedDropModelId = null;
            $this->selectedPickModelId = null;
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function skipSwap(): void
    {
        if (! $this->season || ! $this->myAction) {
            return;
        }

        GameEvent::create([
            'season_id' => $this->season->id,
            'episode_id' => $this->myAction['phase']->episode_id,
            'game_phase_id' => $this->myAction['phase']->id,
            'type' => GameEventType::SwapSkipped,
            'payload' => [
                'user_id' => auth()->id(),
                'user_name' => auth()->user()->name,
            ],
        ]);

        app(PhaseService::class)->checkPhaseCompletion($this->myAction['phase']);

        Notification::make()->title('Swap skipped.')->success()->send();
    }
}
```

**Step 2: Create the Blade view**

Create `resources/views/filament/player/pages/my-actions.blade.php`. Reuse the UI patterns from `post-episode.blade.php` but adapt sections for each action type: `mandatory_drop`, `free_agent_pick`, `optional_swap`, `trading_swap`, `waiting`.

**Step 3: Commit**

```bash
git add app/Filament/Player/Pages/MyActions.php resources/views/filament/player/pages/my-actions.blade.php
git commit -m "feat: add player My Actions page driven by phase queue"
```

---

### Task 7: Simplify EndEpisode Admin Page

**Files:**
- Modify: `app/Filament/Admin/Pages/EndEpisode.php`

**Step 1: Remove the GameStateService dependency**

Update `confirmEndEpisode()` to call the simplified `endEpisode()` (which no longer triggers auto-phases):

```php
public function confirmEndEpisode(): void
{
    if (! $this->selectedEpisodeId) {
        Notification::make()->title('Select an episode first')->warning()->send();
        return;
    }

    $episode = Episode::find($this->selectedEpisodeId);

    app(GameStateService::class)->endEpisode($episode, $this->eliminatedModelIds);

    Notification::make()
        ->title("Episode {$episode->number} ended")
        ->body(count($this->eliminatedModelIds) . ' model(s) eliminated. Go to Game Control to manage post-episode phases.')
        ->success()
        ->send();

    $this->eliminatedModelIds = [];
    $this->selectedEpisodeId = null;
}
```

**Step 2: Commit**

```bash
git add app/Filament/Admin/Pages/EndEpisode.php
git commit -m "refactor: simplify EndEpisode to only end episode, point admin to Game Control"
```

---

### Task 8: Remove Old Player Pages

**Files:**
- Delete: `app/Filament/Player/Pages/PostEpisode.php`
- Delete: `resources/views/filament/player/pages/post-episode.blade.php`
- Delete: `app/Filament/Player/Pages/PreEpisodeSwap.php`
- Delete: `resources/views/filament/player/pages/pre-episode-swap.blade.php`

**Step 1: Delete the old files**

```bash
rm app/Filament/Player/Pages/PostEpisode.php
rm resources/views/filament/player/pages/post-episode.blade.php
rm app/Filament/Player/Pages/PreEpisodeSwap.php
rm resources/views/filament/player/pages/pre-episode-swap.blade.php
```

**Step 2: Remove old test file references**

Delete or update `tests/Feature/PreEpisodeSwapTest.php` if it references the old page.

**Step 3: Commit**

```bash
git add -A
git commit -m "refactor: remove PostEpisode and PreEpisodeSwap pages, replaced by MyActions"
```

---

### Task 9: Run Full Test Suite and Fix Regressions

**Step 1: Run all tests**

Run: `php artisan test --compact`

**Step 2: Fix any failures**

Likely issues:
- Old tests referencing `getRequiredPostEpisodeActions` — delete or update
- Tests referencing `PostEpisode` or `PreEpisodeSwap` classes — delete
- Missing `Episode::factory()->ended()` state — add if missing from `EpisodeFactory`

**Step 3: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

**Step 4: Final test run**

Run: `php artisan test --compact`
Expected: All pass

**Step 5: Commit**

```bash
git add -A
git commit -m "fix: resolve test regressions from phase queue refactor"
```

---

### Task 10: Update Memory File

**Step 1: Update MEMORY.md**

Add a note about the new architecture: phase queue system, PhaseService, Game Control admin page, My Actions player page. Remove references to the old `getRequiredPostEpisodeActions` system.
