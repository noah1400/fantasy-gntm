<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CRITICAL: Do NOT wrap schema change in DB::transaction().
        // On SQLite, column changes require dropping and recreating the table.
        // Laravel disables foreign keys via PRAGMA for this, but PRAGMA is a
        // no-op inside a transaction — which causes cascade deletes on child
        // tables (action_logs, game_events, etc.) when episodes is recreated.

        $allData = DB::table('episodes')->get();
        $childCountsBefore = [
            'action_logs' => DB::table('action_logs')->count(),
            'game_events' => DB::table('game_events')->count(),
            'game_phases' => DB::table('game_phases')->count(),
            'top_models_eliminated' => DB::table('top_models')->whereNotNull('eliminated_in_episode_id')->count(),
            'player_models_picked' => DB::table('player_models')->whereNotNull('picked_in_episode_id')->count(),
            'player_models_dropped' => DB::table('player_models')->whereNotNull('dropped_after_episode_id')->count(),
        ];

        // Pre-check: all number values must be valid positive integers
        foreach ($allData as $episode) {
            $number = $episode->number;

            if (! is_numeric($number) || intval($number) != $number || intval($number) < 0) {
                throw new RuntimeException(
                    "Episode id={$episode->id} has invalid number value: '{$number}'. Aborting."
                );
            }
        }

        // Perform column type change (Laravel manages its own FK handling here)
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedInteger('number')->change();
        });

        // Post-check: verify episodes preserved
        $totalAfter = DB::table('episodes')->count();
        if ($totalAfter !== $allData->count()) {
            throw new RuntimeException(
                "Episode row count mismatch: {$allData->count()} before, {$totalAfter} after. Data loss detected."
            );
        }

        foreach ($allData as $original) {
            $migrated = DB::table('episodes')->where('id', $original->id)->first();

            if (! $migrated) {
                throw new RuntimeException(
                    "Episode id={$original->id} missing after migration. Data loss detected."
                );
            }

            if ((int) $migrated->number !== (int) $original->number) {
                throw new RuntimeException(
                    "Episode id={$original->id} number changed: '{$original->number}' -> '{$migrated->number}'."
                );
            }
        }

        // Post-check: verify NO child table lost rows (cascade delete detection)
        $childCountsAfter = [
            'action_logs' => DB::table('action_logs')->count(),
            'game_events' => DB::table('game_events')->count(),
            'game_phases' => DB::table('game_phases')->count(),
            'top_models_eliminated' => DB::table('top_models')->whereNotNull('eliminated_in_episode_id')->count(),
            'player_models_picked' => DB::table('player_models')->whereNotNull('picked_in_episode_id')->count(),
            'player_models_dropped' => DB::table('player_models')->whereNotNull('dropped_after_episode_id')->count(),
        ];

        foreach ($childCountsBefore as $label => $before) {
            $after = $childCountsAfter[$label];
            if ($after !== $before) {
                throw new RuntimeException(
                    "CASCADE DELETE DETECTED on {$label}: {$before} rows before, {$after} after. "
                    .'Data was lost. Restore from backup immediately.'
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('number')->change();
        });
    }
};
