<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Episode;
use App\Models\PlayerModel;
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

    public function getPlayerModelPoints(PlayerModel $playerModel): float
    {
        $query = ActionLog::query()
            ->where('top_model_id', $playerModel->top_model_id)
            ->join('actions', 'action_logs.action_id', '=', 'actions.id')
            ->join('episodes', 'action_logs.episode_id', '=', 'episodes.id');

        if ($playerModel->pickedInEpisode) {
            $query->where('episodes.number', '>', $playerModel->pickedInEpisode->number);
        }

        if ($playerModel->droppedAfterEpisode) {
            $query->where('episodes.number', '<=', $playerModel->droppedAfterEpisode->number);
        }

        return (float) $query->selectRaw('COALESCE(SUM(action_logs.count * actions.multiplier), 0) as total')
            ->value('total');
    }

    public function getPlayerPoints(User $user, Season $season): float
    {
        $playerModels = $user->playerModels()
            ->forSeason($season)
            ->with(['pickedInEpisode', 'droppedAfterEpisode'])
            ->get();

        if ($playerModels->isEmpty()) {
            return 0;
        }

        $total = 0;

        foreach ($playerModels as $playerModel) {
            $total += $this->getPlayerModelPoints($playerModel);
        }

        return $total;
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
