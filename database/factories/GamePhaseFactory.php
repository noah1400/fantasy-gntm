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
