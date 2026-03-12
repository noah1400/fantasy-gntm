<?php

use App\Enums\GamePhaseType;
use App\Filament\Admin\Pages\GameControl;
use App\Models\Episode;
use App\Models\GamePhase;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(
        Filament::getPanel('admin'),
    );
});

it('preselects the latest ended episode for force assign', function () {
    $season = Season::factory()->active()->create();
    Episode::factory()->ended()->create([
        'season_id' => $season->id,
        'number' => 1,
        'ended_at' => now()->subHour(),
    ]);
    $latestEndedEpisode = Episode::factory()->ended()->create([
        'season_id' => $season->id,
        'number' => 2,
        'ended_at' => now(),
    ]);

    $component = Livewire::test(GameControl::class)
        ->set('selectedSeasonId', $season->id);

    expect((int) $component->get('forceAssignEpisodeId'))->toBe($latestEndedEpisode->id);
});

it('force assign uses the selected ended episode from game control', function () {
    $season = Season::factory()->active()->create();
    $player = User::factory()->create();
    $season->players()->attach($player->id);
    $freeAgent = TopModel::factory()->create(['season_id' => $season->id]);

    $selectedEpisode = Episode::factory()->ended()->create([
        'season_id' => $season->id,
        'number' => 1,
    ]);
    Episode::factory()->ended()->create([
        'season_id' => $season->id,
        'number' => 2,
    ]);

    Livewire::test(GameControl::class)
        ->set('selectedSeasonId', $season->id)
        ->set('forceAssignEpisodeId', $selectedEpisode->id)
        ->set('forceAssignUserId', $player->id)
        ->set('forceAssignModelId', $freeAgent->id)
        ->call('forceAssign');

    $phase = GamePhase::query()
        ->where('season_id', $season->id)
        ->where('type', GamePhaseType::ForceAssign->value)
        ->latest('id')
        ->first();

    expect($phase)->not->toBeNull()
        ->and($phase->episode_id)->toBe($selectedEpisode->id);

    $playerModel = PlayerModel::query()
        ->where('season_id', $season->id)
        ->where('user_id', $player->id)
        ->where('top_model_id', $freeAgent->id)
        ->latest('id')
        ->first();

    expect($playerModel)->not->toBeNull()
        ->and($playerModel->picked_in_episode_id)->toBe($selectedEpisode->id);
});
