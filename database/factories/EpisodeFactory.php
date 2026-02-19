<?php

namespace Database\Factories;

use App\Enums\EpisodeStatus;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Episode>
 */
class EpisodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'number' => (string) fake()->numberBetween(1, 20),
            'title' => fake()->sentence(3),
            'status' => EpisodeStatus::Upcoming,
            'aired_at' => null,
            'ended_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EpisodeStatus::Active,
            'aired_at' => now(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EpisodeStatus::Ended,
            'aired_at' => now()->subHours(3),
            'ended_at' => now(),
        ]);
    }
}
