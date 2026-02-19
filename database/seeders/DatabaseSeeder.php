<?php

namespace Database\Seeders;

use App\Enums\EpisodeStatus;
use App\Enums\PickType;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\DraftOrder;
use App\Models\DraftPick;
use App\Models\Episode;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $players = User::factory(4)->create();

        $season = Season::factory()->active()->create([
            'name' => 'GNTM 2026',
            'year' => 2026,
            'models_per_player' => 2,
        ]);

        $season->players()->attach($players->pluck('id'));

        $modelNames = [
            'Anna Schmidt', 'Bella Fischer', 'Clara Weber', 'Diana Müller',
            'Emma Schneider', 'Fiona Meyer', 'Greta Wagner', 'Hannah Becker',
            'Ida Hoffmann', 'Jana Schäfer', 'Klara Koch', 'Lena Bauer',
            'Mia Richter', 'Nina Wolf', 'Olivia Klein',
        ];

        $topModels = collect($modelNames)->map(fn (string $name) => TopModel::factory()->create([
            'season_id' => $season->id,
            'name' => $name,
        ]));

        $actionDefinitions = [
            ['name' => 'Catwalk', 'description' => 'Walking the catwalk', 'multiplier' => 1.00],
            ['name' => 'Challenge Win', 'description' => 'Winning a challenge', 'multiplier' => 2.00],
            ['name' => 'Photo Shoot Best', 'description' => 'Best photo of the week', 'multiplier' => 1.50],
            ['name' => 'Drama', 'description' => 'Causing drama', 'multiplier' => 0.50],
            ['name' => 'Heidi Praise', 'description' => 'Praised by Heidi Klum', 'multiplier' => 1.00],
        ];

        $actions = collect($actionDefinitions)->map(fn (array $def) => Action::factory()->create([
            'season_id' => $season->id,
            ...$def,
        ]));

        $episodes = collect();
        for ($i = 1; $i <= 3; $i++) {
            $status = $i <= 2 ? EpisodeStatus::Ended : EpisodeStatus::Active;
            $episodes->push(Episode::factory()->create([
                'season_id' => $season->id,
                'number' => (string) $i,
                'title' => "Episode $i",
                'status' => $status,
                'aired_at' => now()->subWeeks(3 - $i),
                'ended_at' => $i <= 2 ? now()->subWeeks(3 - $i)->addHours(2) : null,
            ]));
        }

        // Draft: snake order for 4 players, 2 rounds
        $draftSequence = [0, 1, 2, 3, 3, 2, 1, 0];
        $modelIndex = 0;

        foreach ($players as $position => $player) {
            DraftOrder::factory()->create([
                'season_id' => $season->id,
                'user_id' => $player->id,
                'position' => $position + 1,
            ]);
        }

        foreach ($draftSequence as $pickNumber => $playerIndex) {
            $round = intdiv($pickNumber, 4) + 1;
            $player = $players[$playerIndex];
            $model = $topModels[$modelIndex];

            DraftPick::factory()->create([
                'season_id' => $season->id,
                'user_id' => $player->id,
                'top_model_id' => $model->id,
                'round' => $round,
                'pick_number' => $pickNumber + 1,
            ]);

            PlayerModel::factory()->create([
                'user_id' => $player->id,
                'top_model_id' => $model->id,
                'season_id' => $season->id,
                'pick_type' => PickType::Draft,
            ]);

            $modelIndex++;
        }

        // Action logs for ended episodes
        foreach ($episodes->take(2) as $episode) {
            foreach ($topModels->take(8) as $model) {
                $randomActions = $actions->random(rand(1, 3));
                foreach ($randomActions as $action) {
                    ActionLog::factory()->create([
                        'action_id' => $action->id,
                        'top_model_id' => $model->id,
                        'episode_id' => $episode->id,
                        'count' => rand(1, 3),
                    ]);
                }
            }
        }

        // Eliminate one model in episode 2
        $eliminatedModel = $topModels[14];
        $eliminatedModel->update([
            'is_eliminated' => true,
            'eliminated_in_episode_id' => $episodes[1]->id,
        ]);
    }
}
