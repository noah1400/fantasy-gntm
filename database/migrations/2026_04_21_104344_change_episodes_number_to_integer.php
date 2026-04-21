<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $allData = DB::table('episodes')->get();

        // Pre-check: verify all number values are valid positive integers
        foreach ($allData as $episode) {
            $number = $episode->number;

            if (! is_numeric($number) || intval($number) != $number || intval($number) < 0) {
                throw new RuntimeException(
                    "Episode id={$episode->id} has invalid number value: '{$number}'. Aborting."
                );
            }
        }

        DB::transaction(function () use ($allData) {
            // Change column type
            Schema::table('episodes', function (Blueprint $table) {
                $table->unsignedInteger('number')->change();
            });

            // Verify row count preserved
            $totalAfter = DB::table('episodes')->count();
            if ($totalAfter !== $allData->count()) {
                throw new RuntimeException(
                    "Row count mismatch: {$allData->count()} before, {$totalAfter} after. Rolling back."
                );
            }

            // Verify every row preserved correctly
            foreach ($allData as $original) {
                $migrated = DB::table('episodes')->where('id', $original->id)->first();

                if (! $migrated) {
                    throw new RuntimeException(
                        "Episode id={$original->id} missing after migration. Rolling back."
                    );
                }

                if ((int) $migrated->number !== (int) $original->number) {
                    throw new RuntimeException(
                        "Episode id={$original->id} number changed: '{$original->number}' ��� '{$migrated->number}'. Rolling back."
                    );
                }
            }

            // Verify foreign key references intact
            $episodeIds = $allData->pluck('id')->toArray();
            $fkChecks = [
                ['action_logs', 'episode_id'],
                ['game_events', 'episode_id'],
                ['game_phases', 'episode_id'],
                ['top_models', 'eliminated_in_episode_id'],
                ['player_models', 'picked_in_episode_id'],
                ['player_models', 'dropped_after_episode_id'],
            ];

            foreach ($fkChecks as [$table, $column]) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $orphaned = DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, $episodeIds)
                    ->count();

                if ($orphaned > 0) {
                    throw new RuntimeException(
                        "{$orphaned} orphaned rows in {$table}.{$column}. Rolling back."
                    );
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('number')->change();
        });
    }
};
