<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('top_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();

            $table->unique(['action_id', 'top_model_id', 'episode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
