<?php

namespace Database\Factories;

use App\Enums\GameEventType;
use App\Models\Episode;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GameEvent>
 */
class GameEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'episode_id' => Episode::factory(),
            'type' => GameEventType::Elimination,
            'payload' => [],
        ];
    }
}
