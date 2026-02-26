# Admin-Driven Game Phases

## Problem

The automatic post-episode state machine calculates required player actions (drops, picks, swaps) based on game state. This leads to bugs in edge cases (e.g., players with 1 model forced to drop) and gives the admin no control over the flow. The admin should drive every step.

## Core Concept

Replace the automatic post-episode state machine with an admin-driven phase queue. The game logic never acts on its own — the admin creates phases, and players respond.

## Data Model

### New `game_phases` table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint PK | |
| season_id | FK → seasons | |
| episode_id | FK → episodes, nullable | Which episode triggered this (null for ad-hoc) |
| type | string (GamePhaseType enum) | Phase type |
| config | JSON | Phase-specific parameters |
| position | integer | Order in queue |
| status | string (GamePhaseStatus enum) | pending, active, completed, cancelled |
| started_at | timestamp, nullable | When phase became active |
| completed_at | timestamp, nullable | When phase finished |
| created_at / updated_at | timestamps | |

### New `game_phase_id` FK on `game_events`

Nullable FK so player actions during a phase are tied to that phase. Existing events remain with null (backward compatible).

### No changes to existing tables

`player_models`, `episodes`, `seasons`, `top_models` stay as-is.

## Phase Types (GamePhaseType enum)

| Type | Config | Turn Order | Ends When |
|------|--------|------------|-----------|
| MandatoryDrop | `{"target_model_count": 1}` | Simultaneous | All players with >N models have dropped |
| PickRound | `{"eligible_below": 2}` | One-at-a-time (lowest pts) | All eligible picked or no free agents remain |
| OptionalSwap | `{}` | One-at-a-time (lowest pts) | All players swapped or skipped |
| TradingPhase | `{}` | Simultaneous, unlimited | Admin closes manually |
| ForceAssign | `{"user_id": X, "top_model_id": Y}` | Instant (admin) | Immediately |
| EliminatePlayer | `{"user_id": X}` | Instant (admin) | Immediately |
| SkipPlayer | `{"user_id": X, "parent_phase_id": Y}` | Instant (admin) | Immediately |
| Redistribute | `{"strategy": "random"}` | Instant (admin) | Immediately |

## Phase Queue Engine (PhaseService)

### Methods

- `createPhase(season, type, config, episode?)` — adds to queue
- `getActivePhase(season)` — returns the currently active phase
- `getPlayerAction(season, user)` — what should this player do right now?
- `checkPhaseCompletion(phase)` — is the active phase done?
- `advanceQueue(season)` — activate next pending phase if current is complete
- `closePhase(phase)` — admin force-closes a phase
- `cancelPhase(phase)` — admin cancels a pending phase
- `reorderPhases(season, orderedIds)` — reorder pending phases

### Auto-advance flow

```
Player acts (drop/pick/swap/skip)
  → GameEvent created (audit trail)
  → PhaseService::checkPhaseCompletion()
      → If complete: mark phase completed, call advanceQueue()
          → advanceQueue() activates next pending phase
          → Send notifications to affected players
      → If not complete: for turn-based phases, notify next player
```

### Turn order for sequential phases (PickRound, OptionalSwap)

`getPlayerAction()` checks:
1. Is there an active phase?
2. Is it this player's turn? (lowest points among players who haven't acted in this phase)
3. If yes → return the action. If no → return "waiting" or null.

## Admin UI: Game Control Page

New Filament admin page at `/admin/game-control`.

**Left panel — Player Status:** Live view of all players, active models, points, elimination status.

**Right panel — Phase Queue:** Queued phases in order. Active phase highlighted. Admin can:
- Add a new phase (modal with type + config)
- Cancel a pending phase
- Manually close the active phase
- Reorder pending phases

**Bottom — Quick Actions:** Instant admin actions (ForceAssign, EliminatePlayer, SkipPlayer, Redistribute). Create + immediately complete a phase for audit trail.

## Player UI: My Actions Page

Unified page at `/play/my-actions`. Replaces both PostEpisode and PreEpisodeSwap.

Adapts based on active phase type:
- **MandatoryDrop**: Shows player's models with drop buttons. "Drop N models to continue."
- **PickRound (your turn)**: Shows available free agents with pick buttons.
- **PickRound (waiting)**: Shows who's currently picking and queue position.
- **OptionalSwap (your turn)**: Drop/pick selectors + swap/skip buttons.
- **TradingPhase**: Drop/pick selectors, swap button. "Waiting for admin to close trading."
- **No active phase**: "No actions needed right now."

Notifications sent when a phase activates or when it becomes a player's turn.

## Migration Path

### Deployment (safe mid-season)

1. New `game_phases` table (migration)
2. Add `game_phase_id` nullable FK to `game_events` (migration)
3. No changes to existing tables

### Existing data

All historical GameEvents remain valid with null `game_phase_id`. No data loss.

### Current in-progress state

After deploying: no active phases exist → My Actions shows "No actions needed." Admin uses Game Control to create phases for any unresolved situation.

### Code changes

| Remove/Deprecate | Replace with |
|---|---|
| `GameStateService::getRequiredPostEpisodeActions()` | `PhaseService::getPlayerAction()` |
| `endEpisode()` auto-drop/notification logic | Keep only episode ending + model elimination |
| `PostEpisode.php` player page | `MyActions.php` player page |
| `PreEpisodeSwap.php` player page | `MyActions.php` (admin creates TradingPhase) |
| Auto-elimination in `endEpisode()` | Admin uses EliminatePlayer explicitly |

### What stays

- `GameStateService::pickFreeAgent()`, `dropModel()`, `swapModel()` — atomic operations
- `GameStateService::eliminatePlayer()` — called by EliminatePlayer phase
- All GameEvent creation — audit trail
- `ScoringService`, `SeasonService::getFreeAgents()` — untouched
- `DraftManager`, `ActionLogger` admin pages — untouched
- `EndEpisode` admin page — simplified (just ends episode + eliminates models)
