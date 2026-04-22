<?php

namespace App\Console\Commands;

use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\TopModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UndoReconstructEpisodeAssignments extends Command
{
    protected $signature = 'reconstruct:episode-assignments:undo
                            {--dry-run : Preview changes without applying them}
                            {--force : Skip confirmation when applying}';

    protected $description = 'Revert the 151 changes applied by reconstruct:episode-assignments on 2026-04-22';

    /**
     * GameEvent ids whose episode_id must be reverted to NULL (86 events).
     *
     * @var array<int, int>
     */
    private const EVENT_IDS_TO_NULL = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10,
        11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
        31, 32, 33, 34, 35, 36, 37, 38, 39, 40,
        41, 42, 43, 44, 45, 46, 47, 48, 49, 50,
        51, 52, 53, 54, 55, 56, 57, 58, 59, 60,
        61, 62, 63, 64, 65, 66, 67, 68, 69, 70,
        71, 72, 73, 74, 75, 76, 77, 78, 79, 80,
        81, 82, 83, 84, 85, 86,
    ];

    /**
     * PlayerModels whose dropped_after_episode_id must be reverted to 12 (picked_in unchanged).
     *
     * @var array<int, int>
     */
    private const PM_IDS_DROPPED_WAS_12 = [1, 2, 3, 4, 6, 7, 8, 9, 10, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    /**
     * PlayerModels where picked_in must revert to NULL and dropped_after must revert to 12.
     *
     * @var array<int, int>
     */
    private const PM_IDS_PICKED_NULL_DROPPED_12 = [21, 22, 23, 24, 25, 26, 27, 28, 29, 31, 32, 35, 36, 39, 40, 41, 42];

    /**
     * PlayerModel where picked_in must revert to NULL and dropped_after must revert to 16.
     */
    private const PM_ID_PICKED_NULL_DROPPED_16 = 38;

    /**
     * PlayerModels where only picked_in must revert to NULL (dropped_after unchanged).
     *
     * @var array<int, int>
     */
    private const PM_IDS_PICKED_NULL_ONLY = [30, 33, 34, 37, 43, 44, 45, 46];

    /**
     * TopModels whose eliminated_in_episode_id must be reverted to NULL.
     *
     * @var array<int, int>
     */
    private const TOP_MODEL_IDS_TO_NULL = [1, 6, 7, 9, 10, 12, 13, 14, 15, 16, 18, 19, 20, 21, 22, 24, 25, 30, 31, 34, 36];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? '=== DRY RUN (no changes will be written) ===' : '=== UNDO RECONSTRUCTION ===');

        $eventCount = count(self::EVENT_IDS_TO_NULL);
        $pmCount = count(self::PM_IDS_DROPPED_WAS_12)
            + count(self::PM_IDS_PICKED_NULL_DROPPED_12)
            + 1
            + count(self::PM_IDS_PICKED_NULL_ONLY);
        $tmCount = count(self::TOP_MODEL_IDS_TO_NULL);
        $total = $eventCount + $pmCount + $tmCount;

        $this->line('Will revert:');
        $this->line("  - {$eventCount} GameEvent.episode_id -> NULL");
        $this->line("  - {$pmCount} PlayerModel picked_in/dropped_after reverts");
        $this->line("  - {$tmCount} TopModel.eliminated_in_episode_id -> NULL");
        $this->line("  Total: {$total} changes");

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Apply {$total} reverts?", false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            GameEvent::whereIn('id', self::EVENT_IDS_TO_NULL)
                ->update(['episode_id' => null]);

            PlayerModel::whereIn('id', self::PM_IDS_DROPPED_WAS_12)
                ->update(['dropped_after_episode_id' => 12]);

            PlayerModel::whereIn('id', self::PM_IDS_PICKED_NULL_DROPPED_12)
                ->update([
                    'picked_in_episode_id' => null,
                    'dropped_after_episode_id' => 12,
                ]);

            PlayerModel::where('id', self::PM_ID_PICKED_NULL_DROPPED_16)
                ->update([
                    'picked_in_episode_id' => null,
                    'dropped_after_episode_id' => 16,
                ]);

            PlayerModel::whereIn('id', self::PM_IDS_PICKED_NULL_ONLY)
                ->update(['picked_in_episode_id' => null]);

            TopModel::whereIn('id', self::TOP_MODEL_IDS_TO_NULL)
                ->update(['eliminated_in_episode_id' => null]);
        });

        $this->info("Reverted {$total} changes successfully.");

        return self::SUCCESS;
    }
}
