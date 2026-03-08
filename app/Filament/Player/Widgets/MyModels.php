<?php

namespace App\Filament\Player\Widgets;

use App\Enums\GameEventType;
use App\Enums\SeasonStatus;
use App\Models\GameEvent;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Services\ScoringService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class MyModels extends Widget
{
    protected string $view = 'filament.player.widgets.my-models';

    protected int|string|array $columnSpan = 'full';

    public function getMyModelsData(): Collection
    {
        $season = Season::query()
            ->whereIn('status', [SeasonStatus::Active, SeasonStatus::Draft])
            ->latest()
            ->first();

        if (! $season) {
            return collect();
        }

        $scoringService = app(ScoringService::class);

        $playerModels = PlayerModel::query()
            ->where('user_id', auth()->id())
            ->where('season_id', $season->id)
            ->with(['topModel', 'pickedInEpisode', 'droppedAfterEpisode'])
            ->get();

        $dropEvents = $this->getDropEvents($season, $playerModels);

        return $playerModels
            ->sortBy(fn (PlayerModel $pm) => $pm->dropped_after_episode_id ? 1 : 0)
            ->values()
            ->map(function (PlayerModel $pm) use ($scoringService, $dropEvents) {
                $dropEvent = $pm->dropped_after_episode_id
                    ? $dropEvents->get($pm->top_model_id.'_'.$pm->dropped_after_episode_id)
                    : null;

                return [
                    'top_model' => $pm->topModel,
                    'points' => $scoringService->getPlayerModelPoints($pm),
                    'pick_type' => $pm->pick_type,
                    'picked_in_episode' => $pm->pickedInEpisode,
                    'dropped_after_episode' => $pm->droppedAfterEpisode,
                    'drop_reason' => $dropEvent?->type,
                    'is_dropped' => $pm->dropped_after_episode_id !== null,
                ];
            });
    }

    private function getDropEvents(Season $season, Collection $playerModels): Collection
    {
        $droppedModels = $playerModels->whereNotNull('dropped_after_episode_id');

        if ($droppedModels->isEmpty()) {
            return collect();
        }

        return GameEvent::query()
            ->where('season_id', $season->id)
            ->whereIn('type', [
                GameEventType::MandatoryDrop,
                GameEventType::ModelDrop,
                GameEventType::ModelSwap,
                GameEventType::Elimination,
            ])
            ->where(function ($query) use ($droppedModels) {
                foreach ($droppedModels as $pm) {
                    $query->orWhere(function ($q) use ($pm) {
                        $q->where('episode_id', $pm->dropped_after_episode_id);

                        $q->where(function ($inner) use ($pm) {
                            $inner->where(function ($sub) use ($pm) {
                                $sub->whereIn('type', [GameEventType::MandatoryDrop, GameEventType::ModelDrop])
                                    ->whereJsonContains('payload->top_model_id', $pm->top_model_id)
                                    ->whereJsonContains('payload->user_id', $pm->user_id);
                            })->orWhere(function ($sub) use ($pm) {
                                $sub->where('type', GameEventType::ModelSwap)
                                    ->whereJsonContains('payload->dropped_model_id', $pm->top_model_id)
                                    ->whereJsonContains('payload->user_id', $pm->user_id);
                            })->orWhere(function ($sub) use ($pm) {
                                $sub->where('type', GameEventType::Elimination)
                                    ->whereJsonContains('payload->top_model_id', $pm->top_model_id);
                            });
                        });
                    });
                }
            })
            ->get()
            ->keyBy(function (GameEvent $event) {
                $modelId = match ($event->type) {
                    GameEventType::ModelSwap => $event->payload['dropped_model_id'],
                    default => $event->payload['top_model_id'],
                };

                return $modelId.'_'.$event->episode_id;
            });
    }
}
