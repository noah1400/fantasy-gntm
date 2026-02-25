<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_models', function (Blueprint $table) {
            $table->dropColumn(['picked_at', 'dropped_at']);
            $table->foreignId('picked_in_episode_id')->nullable()->after('season_id')->constrained('episodes')->nullOnDelete();
            $table->foreignId('dropped_after_episode_id')->nullable()->after('picked_in_episode_id')->constrained('episodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('player_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picked_in_episode_id');
            $table->dropConstrainedForeignId('dropped_after_episode_id');
            $table->timestamp('picked_at')->after('season_id');
            $table->timestamp('dropped_at')->nullable()->after('picked_at');
        });
    }
};
