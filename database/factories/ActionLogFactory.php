<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\Episode;
use App\Models\TopModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActionLog>
 */
class ActionLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'action_id' => Action::factory(),
            'top_model_id' => TopModel::factory(),
            'episode_id' => Episode::factory(),
            'count' => fake()->numberBetween(1, 5),
        ];
    }
}
