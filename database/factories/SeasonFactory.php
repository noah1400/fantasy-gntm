<?php

namespace Database\Factories;

use App\Enums\SeasonStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Season>
 */
class SeasonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'GNTM '.fake()->year(),
            'year' => fake()->year(),
            'status' => SeasonStatus::Setup,
            'models_per_player' => 2,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeasonStatus::Draft,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeasonStatus::Active,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SeasonStatus::Completed,
        ]);
    }
}
