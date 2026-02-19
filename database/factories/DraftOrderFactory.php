<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DraftOrder>
 */
class DraftOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'user_id' => User::factory(),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
