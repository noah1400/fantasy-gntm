<?php

namespace App\Console\Commands;

use App\Enums\GameEventType;
use App\Enums\PickType;
use App\Models\Episode;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\TopModel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconstructEpisodeAssignments extends Command
{
    protected $signature = 'reconstruct:episode-assignments
                            {--season= : Season ID to reconstruct (defaults to all)}
                            {--dry-run : Preview changes without applying them}
                            {--force : Skip confirmation when applying}';

    protected $description = 'Reconstruct PlayerModel/GameEvent/TopModel episode assignments from GameEvent timestamps';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $seasonId = $this->option('season');

        $this->info($dryRun ? '=== DRY RUN (no changes will be written) ===' : '=== RECONSTRUCTION ===');

        $episodes = Episode::query()
            ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
            ->whereNotNull('ended_at')
            ->orderBy('ended_at')
            ->get();

        if ($episodes->isEmpty()) {
            $this->error('No ended episodes found.');

            return self::FAILURE;
        }

        $events = GameEvent::query()
            ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $playerModels = PlayerModel::query()
            ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
            ->with(['user', 'topModel'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $topModels = TopModel::query()
            ->when($seasonId, fn ($q) => $q->where('season_id', $seasonId))
            ->where('is_eliminated', true)
            ->get();

        $this->line(sprintf(
            'Loaded %d episodes, %d events, %d player models, %d eliminated top models.',
            $episodes->count(), $events->count(), $playerModels->count(), $topModels->count()
        ));

        $resolvedEventEpisodes = [];
        $eventChanges = [];
        foreach ($events as $event) {
            $resolvedId = $this->resolveEpisodeId($episodes, $event->created_at->toDateTimeString());
            $resolvedEventEpisodes[$event->id] = $resolvedId;

            if ($event->episode_id !== $resolvedId) {
                $eventChanges[] = [
                    'id' => $event->id,
                    'type' => $event->type->value,
                    'created_at' => $event->created_at->toDateTimeString(),
                    'old' => $event->episode_id,
                    'new' => $resolvedId,
                ];
            }
        }

        $pmsByUserModel = $playerModels->groupBy(fn (PlayerModel $pm) => $pm->user_id.'_'.$pm->top_model_id);

        $pmChanges = [];
        foreach ($playerModels as $pm) {
            $newPicked = $this->resolvePickedIn($pm, $events, $resolvedEventEpisodes);
            $newDropped = $this->resolveDroppedAfter($pm, $events, $resolvedEventEpisodes, $pmsByUserModel);

            if ($pm->picked_in_episode_id !== $newPicked || $pm->dropped_after_episode_id !== $newDropped) {
                $pmChanges[] = [
                    'id' => $pm->id,
                    'user_name' => $pm->user?->name ?? '?',
                    'top_model_name' => $pm->topModel?->name ?? '?',
                    'pick_type' => $pm->pick_type->value,
                    'created_at' => $pm->created_at->toDateTimeString(),
                    'old_picked' => $pm->picked_in_episode_id,
                    'new_picked' => $newPicked,
                    'old_dropped' => $pm->dropped_after_episode_id,
                    'new_dropped' => $newDropped,
                ];
            }
        }

        $tmChanges = [];
        foreach ($topModels as $tm) {
            $eliminationEvent = $events
                ->filter(fn (GameEvent $e) => $e->type === GameEventType::Elimination
                    && (int) ($e->payload['top_model_id'] ?? 0) === $tm->id)
                ->sortBy(fn (GameEvent $e) => $e->created_at->toDateTimeString())
                ->first();

            $newEliminated = $eliminationEvent ? ($resolvedEventEpisodes[$eliminationEvent->id] ?? null) : null;

            if ($tm->eliminated_in_episode_id !== $newEliminated) {
                $tmChanges[] = [
                    'id' => $tm->id,
                    'name' => $tm->name,
                    'old' => $tm->eliminated_in_episode_id,
                    'new' => $newEliminated,
                ];
            }
        }

        $this->printChanges($eventChanges, $pmChanges, $tmChanges, $episodes);

        $total = count($eventChanges) + count($pmChanges) + count($tmChanges);

        if ($total === 0) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Apply {$total} changes?", false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($eventChanges, $pmChanges, $tmChanges) {
            foreach ($eventChanges as $c) {
                GameEvent::where('id', $c['id'])->update(['episode_id' => $c['new']]);
            }
            foreach ($pmChanges as $c) {
                PlayerModel::where('id', $c['id'])->update([
                    'picked_in_episode_id' => $c['new_picked'],
                    'dropped_after_episode_id' => $c['new_dropped'],
                ]);
            }
            foreach ($tmChanges as $c) {
                TopModel::where('id', $c['id'])->update(['eliminated_in_episode_id' => $c['new']]);
            }
        });

        $this->info("Applied {$total} changes successfully.");

        return self::SUCCESS;
    }

    private function resolveEpisodeId(Collection $episodes, string $timestamp): ?int
    {
        return $episodes
            ->filter(fn (Episode $e) => $e->ended_at->toDateTimeString() <= $timestamp)
            ->sortByDesc(fn (Episode $e) => $e->ended_at->toDateTimeString())
            ->first()?->id;
    }

    private function resolvePickedIn(PlayerModel $pm, Collection $events, array $eventEpisodes): ?int
    {
        if ($pm->pick_type === PickType::Draft) {
            return null;
        }

        $pickEventTypes = match ($pm->pick_type) {
            PickType::FreeAgent => [GameEventType::FreeAgentPick],
            PickType::Swap => [GameEventType::ModelSwap],
            PickType::PreEpisodeSwap => [GameEventType::PreEpisodeSwap, GameEventType::ModelSwap],
            default => [],
        };

        $event = $events
            ->filter(function (GameEvent $e) use ($pm, $pickEventTypes) {
                if (! in_array($e->type, $pickEventTypes, true)) {
                    return false;
                }
                $payload = $e->payload;
                if ((int) ($payload['user_id'] ?? 0) !== $pm->user_id) {
                    return false;
                }
                if ($e->type === GameEventType::ModelSwap || $e->type === GameEventType::PreEpisodeSwap) {
                    return (int) ($payload['picked_model_id'] ?? 0) === $pm->top_model_id;
                }

                return (int) ($payload['top_model_id'] ?? 0) === $pm->top_model_id;
            })
            ->sortBy(fn (GameEvent $e) => abs($e->created_at->diffInSeconds($pm->created_at)))
            ->first();

        return $event ? ($eventEpisodes[$event->id] ?? null) : null;
    }

    private function resolveDroppedAfter(PlayerModel $pm, Collection $events, array $eventEpisodes, Collection $pmsByUserModel): ?int
    {
        $sameKey = $pm->user_id.'_'.$pm->top_model_id;
        $samePms = $pmsByUserModel->get($sameKey) ?? collect();
        $nextSamePm = $samePms
            ->filter(fn (PlayerModel $other) => $other->id !== $pm->id && $other->created_at->gt($pm->created_at))
            ->sortBy(fn (PlayerModel $other) => $other->created_at->toDateTimeString())
            ->first();

        $windowEndTs = $nextSamePm?->created_at->toDateTimeString();
        $pmCreatedTs = $pm->created_at->toDateTimeString();

        $removalEvent = $events
            ->filter(function (GameEvent $e) use ($pm, $pmCreatedTs, $windowEndTs) {
                $eventTs = $e->created_at->toDateTimeString();
                if ($eventTs < $pmCreatedTs) {
                    return false;
                }
                if ($windowEndTs !== null && $eventTs >= $windowEndTs) {
                    return false;
                }

                $payload = $e->payload;

                return match ($e->type) {
                    GameEventType::ModelDrop, GameEventType::MandatoryDrop => (int) ($payload['user_id'] ?? 0) === $pm->user_id
                        && (int) ($payload['top_model_id'] ?? 0) === $pm->top_model_id,
                    GameEventType::ModelSwap, GameEventType::PreEpisodeSwap => (int) ($payload['user_id'] ?? 0) === $pm->user_id
                        && (int) ($payload['dropped_model_id'] ?? 0) === $pm->top_model_id,
                    GameEventType::Elimination => (int) ($payload['top_model_id'] ?? 0) === $pm->top_model_id,
                    default => false,
                };
            })
            ->sortBy(fn (GameEvent $e) => $e->created_at->toDateTimeString())
            ->first();

        return $removalEvent ? ($eventEpisodes[$removalEvent->id] ?? null) : null;
    }

    private function printChanges(array $eventChanges, array $pmChanges, array $tmChanges, Collection $episodes): void
    {
        $epLabel = function (?int $id) use ($episodes): string {
            if ($id === null) {
                return 'NULL';
            }
            $ep = $episodes->firstWhere('id', $id);

            return $ep ? "ep{$ep->number}(id={$id})" : "?(id={$id})";
        };

        $this->line('');
        $this->info('--- GameEvent.episode_id ('.count($eventChanges).' changes) ---');
        foreach ($eventChanges as $c) {
            $this->line(sprintf(
                '  Event %-4d %-20s %s  %s -> %s',
                $c['id'], $c['type'], $c['created_at'], $epLabel($c['old']), $epLabel($c['new'])
            ));
        }

        $this->line('');
        $this->info('--- PlayerModel picked_in / dropped_after ('.count($pmChanges).' changes) ---');
        foreach ($pmChanges as $c) {
            $pickedChanged = $c['old_picked'] !== $c['new_picked'];
            $droppedChanged = $c['old_dropped'] !== $c['new_dropped'];
            $this->line(sprintf(
                '  PM %-3d %-10s/%-12s %-18s created=%s',
                $c['id'], $c['user_name'], $c['top_model_name'], $c['pick_type'], $c['created_at']
            ));
            if ($pickedChanged) {
                $this->line(sprintf('        picked_in:     %s -> %s',
                    $epLabel($c['old_picked']), $epLabel($c['new_picked'])));
            }
            if ($droppedChanged) {
                $this->line(sprintf('        dropped_after: %s -> %s',
                    $epLabel($c['old_dropped']), $epLabel($c['new_dropped'])));
            }
        }

        $this->line('');
        $this->info('--- TopModel.eliminated_in_episode_id ('.count($tmChanges).' changes) ---');
        foreach ($tmChanges as $c) {
            $this->line(sprintf(
                '  TopModel %-3d %-15s  %s -> %s',
                $c['id'], $c['name'], $epLabel($c['old']), $epLabel($c['new'])
            ));
        }
        $this->line('');
    }
}
