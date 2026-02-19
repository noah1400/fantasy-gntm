<?php

namespace Database\Factories;

use App\Enums\PickType;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlayerModel>
 */
class PlayerModelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'top_model_id' => TopModel::factory(),
            'season_id' => Season::factory(),
            'picked_at' => now(),
            'dropped_at' => null,
            'pick_type' => PickType::Draft,
        ];
    }

    public function dropped(): static
    {
        return $this->state(fn (array $attributes) => [
            'dropped_at' => now(),
        ]);
    }
}
