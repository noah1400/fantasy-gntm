<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DraftPick>
 */
class DraftPickFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'user_id' => User::factory(),
            'top_model_id' => TopModel::factory(),
            'round' => 1,
            'pick_number' => 1,
        ];
    }
}
