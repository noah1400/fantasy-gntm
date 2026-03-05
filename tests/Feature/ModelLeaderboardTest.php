<?php

use App\Filament\Player\Pages\ModelLeaderboard;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->player = User::factory()->create();
    $this->actingAs($this->player);
    Filament::setCurrentPanel(
        Filament::getPanel('player'),
    );
});

it('includes total and last episode points in leaderboard data', function (): void {
    $season = Season::factory()->active()->create();
    $episodeOne = Episode::factory()->create(['season_id' => $season->id, 'number' => '1']);
    $episodeTwo = Episode::factory()->create(['season_id' => $season->id, 'number' => '2']);

    $modelA = TopModel::factory()->create(['season_id' => $season->id, 'name' => 'Model A']);
    $modelB = TopModel::factory()->create(['season_id' => $season->id, 'name' => 'Model B']);

    $owner = User::factory()->create();
    PlayerModel::factory()->create([
        'season_id' => $season->id,
        'user_id' => $owner->id,
        'top_model_id' => $modelA->id,
    ]);

    $actionOne = Action::factory()->create(['season_id' => $season->id, 'multiplier' => 2.0]);
    $actionTwo = Action::factory()->create(['season_id' => $season->id, 'multiplier' => 1.0]);

    ActionLog::factory()->create([
        'action_id' => $actionOne->id,
        'top_model_id' => $modelA->id,
        'episode_id' => $episodeOne->id,
        'count' => 3,
    ]);
    ActionLog::factory()->create([
        'action_id' => $actionTwo->id,
        'top_model_id' => $modelA->id,
        'episode_id' => $episodeTwo->id,
        'count' => 1,
    ]);

    $leaderboard = Livewire::test(ModelLeaderboard::class)
        ->set('selectedSeasonId', $season->id)
        ->instance()
        ->getLeaderboardData();

    $modelAEntry = $leaderboard->first(fn (array $entry): bool => $entry['top_model']->id === $modelA->id);
    $modelBEntry = $leaderboard->first(fn (array $entry): bool => $entry['top_model']->id === $modelB->id);

    expect($modelAEntry)->not->toBeNull()
        ->and($modelAEntry['owner']?->id)->toBe($owner->id)
        ->and($modelAEntry['points'])->toBe(7.0)
        ->and($modelAEntry['last_episode_points'])->toBe(1.0)
        ->and($modelBEntry)->not->toBeNull()
        ->and($modelBEntry['points'])->toBe(0.0)
        ->and($modelBEntry['last_episode_points'])->toBe(0.0);
});

it('returns episode model points when episode is selected and no model is selected', function (): void {
    $season = Season::factory()->active()->create();
    $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => '1']);
    $otherEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => '2']);
    $modelA = TopModel::factory()->create(['season_id' => $season->id, 'name' => 'Model A']);
    $modelB = TopModel::factory()->create(['season_id' => $season->id, 'name' => 'Model B']);
    $action = Action::factory()->create(['season_id' => $season->id, 'multiplier' => 1.5]);

    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $modelA->id,
        'episode_id' => $episode->id,
        'count' => 2,
    ]);
    ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $modelA->id,
        'episode_id' => $otherEpisode->id,
        'count' => 3,
    ]);

    $component = Livewire::test(ModelLeaderboard::class)
        ->set('selectedSeasonId', $season->id)
        ->set('selectedEpisodeId', $episode->id)
        ->set('selectedTopModelId', null);

    /** @var \Illuminate\Support\Collection<int, array{top_model: TopModel, points: float, action_count: int}> $stats */
    $stats = $component->get('episodeModelPoints');

    $modelAStats = $stats->first(fn (array $entry): bool => $entry['top_model']->id === $modelA->id);
    $modelBStats = $stats->first(fn (array $entry): bool => $entry['top_model']->id === $modelB->id);

    expect($modelAStats)->not->toBeNull()
        ->and($modelAStats['points'])->toBe(3.0)
        ->and($modelAStats['action_count'])->toBe(2)
        ->and($modelBStats)->not->toBeNull()
        ->and($modelBStats['points'])->toBe(0.0)
        ->and($modelBStats['action_count'])->toBe(0);
});

it('returns model episode points and action breakdown for model filters', function (): void {
    $season = Season::factory()->active()->create();
    $episodeOne = Episode::factory()->create(['season_id' => $season->id, 'number' => '1']);
    $episodeTwo = Episode::factory()->create(['season_id' => $season->id, 'number' => '2']);
    $model = TopModel::factory()->create(['season_id' => $season->id, 'name' => 'Model A']);
    $actionOne = Action::factory()->create(['season_id' => $season->id, 'name' => 'Photo', 'multiplier' => 2.0]);
    $actionTwo = Action::factory()->create(['season_id' => $season->id, 'name' => 'Walk', 'multiplier' => 1.0]);

    ActionLog::factory()->create([
        'action_id' => $actionOne->id,
        'top_model_id' => $model->id,
        'episode_id' => $episodeOne->id,
        'count' => 1,
    ]);
    ActionLog::factory()->create([
        'action_id' => $actionTwo->id,
        'top_model_id' => $model->id,
        'episode_id' => $episodeTwo->id,
        'count' => 2,
    ]);

    $component = Livewire::test(ModelLeaderboard::class)
        ->set('selectedSeasonId', $season->id)
        ->set('selectedTopModelId', $model->id)
        ->set('selectedEpisodeId', null);

    /** @var \Illuminate\Support\Collection<int, array{episode: Episode, points: float, action_count: int}> $episodeStats */
    $episodeStats = $component->get('modelEpisodePoints');

    $episodeOneStats = $episodeStats->first(fn (array $entry): bool => $entry['episode']->id === $episodeOne->id);
    $episodeTwoStats = $episodeStats->first(fn (array $entry): bool => $entry['episode']->id === $episodeTwo->id);

    expect($episodeOneStats)->not->toBeNull()
        ->and($episodeOneStats['points'])->toBe(2.0)
        ->and($episodeOneStats['action_count'])->toBe(1)
        ->and($episodeTwoStats)->not->toBeNull()
        ->and($episodeTwoStats['points'])->toBe(2.0)
        ->and($episodeTwoStats['action_count'])->toBe(2);

    $component->set('selectedEpisodeId', $episodeTwo->id);

    /** @var \Illuminate\Support\Collection<int, ActionLog> $breakdown */
    $breakdown = $component->get('modelEpisodeActionBreakdown');

    expect($breakdown)->toHaveCount(1)
        ->and($breakdown->first()->action->name)->toBe('Walk')
        ->and($component->get('modelEpisodeActionBreakdownTotalPoints'))->toBe(2.0);
});

it('can access model detail page route for a model slug', function (): void {
    $season = Season::factory()->active()->create();
    $model = TopModel::factory()->create(['season_id' => $season->id]);

    $this->get('/play/models/'.$model->slug)->assertSuccessful();
});
