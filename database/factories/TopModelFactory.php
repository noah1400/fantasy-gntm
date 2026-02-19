<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TopModel>
 */
class TopModelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->name('female');

        return [
            'season_id' => Season::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'image' => null,
            'is_eliminated' => false,
            'eliminated_in_episode_id' => null,
        ];
    }

    public function eliminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_eliminated' => true,
        ]);
    }
}
