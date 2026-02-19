<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Illuminate\Support\Collection;

class ScoringService
{
    public function logAction(Action $action, TopModel $topModel, Episode $episode): ActionLog
    {
        $actionLog = ActionLog::query()
            ->where('action_id', $action->id)
            ->where('top_model_id', $topModel->id)
            ->where('episode_id', $episode->id)
            ->first();

        if ($actionLog) {
            $actionLog->increment('count');

            return $actionLog->fresh();
        }

        return ActionLog::create([
            'action_id' => $action->id,
            'top_model_id' => $topModel->id,
            'episode_id' => $episode->id,
            'count' => 1,
        ]);
    }

    public function undoAction(Action $action, TopModel $topModel, Episode $episode): ?ActionLog
    {
        $actionLog = ActionLog::query()
            ->where('action_id', $action->id)
            ->where('top_model_id', $topModel->id)
            ->where('episode_id', $episode->id)
            ->first();

        if (! $actionLog) {
            return null;
        }

        if ($actionLog->count <= 1) {
            $actionLog->delete();

            return null;
        }

        $actionLog->decrement('count');

        return $actionLog->fresh();
    }

    public function getModelPoints(TopModel $topModel, ?Episode $episode = null): float
    {
        $query = ActionLog::query()
            ->where('top_model_id', $topModel->id)
            ->join('actions', 'action_logs.action_id', '=', 'actions.id');

        if ($episode) {
            $query->where('episode_id', $episode->id);
        }

        return (float) $query->selectRaw('COALESCE(SUM(action_logs.count * actions.multiplier), 0) as total')
            ->value('total');
    }

    public function getPlayerPoints(User $user, Season $season): float
    {
        $modelIds = $user->playerModels()
            ->forSeason($season)
            ->pluck('top_model_id')
            ->unique();

        if ($modelIds->isEmpty()) {
            return 0;
        }

        return (float) ActionLog::query()
            ->whereIn('top_model_id', $modelIds)
            ->join('actions', 'action_logs.action_id', '=', 'actions.id')
            ->selectRaw('COALESCE(SUM(action_logs.count * actions.multiplier), 0) as total')
            ->value('total');
    }

    /**
     * @return Collection<int, array{user: User, points: float}>
     */
    public function getScoreboard(Season $season): Collection
    {
        return $season->players->map(function (User $user) use ($season) {
            return [
                'user' => $user,
                'points' => $this->getPlayerPoints($user, $season),
            ];
        })->sortByDesc('points')->values();
    }

    /**
     * @return Collection<int, array{top_model: TopModel, points: float}>
     */
    public function getModelLeaderboard(Season $season): Collection
    {
        return $season->topModels->map(function (TopModel $topModel) {
            return [
                'top_model' => $topModel,
                'points' => $this->getModelPoints($topModel),
            ];
        })->sortByDesc('points')->values();
    }
}
