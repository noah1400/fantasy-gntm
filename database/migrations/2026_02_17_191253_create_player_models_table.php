<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('top_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->timestamp('picked_at');
            $table->timestamp('dropped_at')->nullable();
            $table->string('pick_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_models');
    }
};
