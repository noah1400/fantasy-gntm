<?php

namespace Database\Factories;

use App\Enums\PickType;
use App\Models\Episode;
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
            'picked_in_episode_id' => null,
            'dropped_after_episode_id' => null,
            'pick_type' => PickType::Draft,
        ];
    }

    public function dropped(Episode $episode): static
    {
        return $this->state(fn (array $attributes) => [
            'dropped_after_episode_id' => $episode->id,
        ]);
    }

    public function pickedIn(Episode $episode): static
    {
        return $this->state(fn (array $attributes) => [
            'picked_in_episode_id' => $episode->id,
        ]);
    }
}
