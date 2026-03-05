<?php

use App\Filament\Admin\Pages\ActionLogger;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(
        Filament::getPanel('admin'),
    );
});

it('lists tracked actions for the selected episode', function (): void {
    $season = Season::factory()->create();
    $episode = Episode::factory()->create(['season_id' => $season->id]);
    $otherEpisode = Episode::factory()->create(['season_id' => $season->id]);
    $model = TopModel::factory()->create(['season_id' => $season->id]);
    $otherModel = TopModel::factory()->create(['season_id' => $season->id]);
    $action = Action::factory()->create(['season_id' => $season->id, 'name' => 'Catwalk']);
    $otherAction = Action::factory()->create(['season_id' => $season->id, 'name' => 'Interview']);

    $expectedLog = ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $model->id,
        'episode_id' => $episode->id,
        'count' => 3,
    ]);

    ActionLog::factory()->create([
        'action_id' => $otherAction->id,
        'top_model_id' => $otherModel->id,
        'episode_id' => $otherEpisode->id,
        'count' => 2,
    ]);

    $component = Livewire::test(ActionLogger::class)
        ->set('selectedSeasonId', $season->id)
        ->set('selectedEpisodeId', $episode->id);

    $component
        ->assertSee($model->name)
        ->assertSee($action->name);

    $trackedLogs = $component->get('trackedActionLogs');

    expect($trackedLogs)->toHaveCount(1)
        ->and($trackedLogs->first()->id)->toBe($expectedLog->id);
});

it('can increment and decrement a tracked action count', function (): void {
    $season = Season::factory()->create();
    $episode = Episode::factory()->create(['season_id' => $season->id]);
    $model = TopModel::factory()->create(['season_id' => $season->id]);
    $action = Action::factory()->create(['season_id' => $season->id]);
    $log = ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $model->id,
        'episode_id' => $episode->id,
        'count' => 2,
    ]);

    $component = Livewire::test(ActionLogger::class)
        ->set('selectedSeasonId', $season->id)
        ->set('selectedEpisodeId', $episode->id);

    $component->call('adjustTrackedActionCount', $log->id, 1);

    expect($log->fresh()->count)->toBe(3);

    $component->call('adjustTrackedActionCount', $log->id, -1);

    expect($log->fresh()->count)->toBe(2);
});

it('deletes a tracked action log when decrementing from one', function (): void {
    $season = Season::factory()->create();
    $episode = Episode::factory()->create(['season_id' => $season->id]);
    $model = TopModel::factory()->create(['season_id' => $season->id]);
    $action = Action::factory()->create(['season_id' => $season->id]);
    $log = ActionLog::factory()->create([
        'action_id' => $action->id,
        'top_model_id' => $model->id,
        'episode_id' => $episode->id,
        'count' => 1,
    ]);

    Livewire::test(ActionLogger::class)
        ->set('selectedSeasonId', $season->id)
        ->set('selectedEpisodeId', $episode->id)
        ->call('adjustTrackedActionCount', $log->id, -1);

    expect(ActionLog::query()->find($log->id))->toBeNull();
});
