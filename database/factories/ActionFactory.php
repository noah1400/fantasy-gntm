<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Action>
 */
class ActionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'name' => fake()->word(),
            'description' => fake()->sentence(),
            'multiplier' => 1.00,
        ];
    }
}
