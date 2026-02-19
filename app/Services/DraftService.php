<?php

namespace App\Services;

use App\Enums\PickType;
use App\Models\DraftOrder;
use App\Models\DraftPick;
use App\Models\PlayerModel;
use App\Models\Season;
use App\Models\TopModel;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

class DraftService
{
    /**
     * Generate a snake draft order sequence for the given number of rounds.
     *
     * For 3 players, 2 rounds: [1,2,3,3,2,1]
     *
     * @return list<int>
     */
    public function generateSnakeOrder(Season $season): array
    {
        $players = $season->draftOrders()->orderBy('position')->get();
        $rounds = $season->models_per_player;
        $sequence = [];

        for ($round = 0; $round < $rounds; $round++) {
            $order = $players->pluck('user_id')->toArray();
            if ($round % 2 === 1) {
                $order = array_reverse($order);
            }
            $sequence = array_merge($sequence, $order);
        }

        return $sequence;
    }

    public function getCurrentPickNumber(Season $season): int
    {
        return $season->draftPicks()->count() + 1;
    }

    public function getCurrentDrafter(Season $season): ?User
    {
        $sequence = $this->generateSnakeOrder($season);
        $currentPick = $this->getCurrentPickNumber($season) - 1;

        if ($currentPick >= count($sequence)) {
            return null;
        }

        return User::find($sequence[$currentPick]);
    }

    public function getAvailableModels(Season $season): Collection
    {
        $pickedModelIds = $season->draftPicks()->pluck('top_model_id');

        return $season->topModels()
            ->whereNotIn('id', $pickedModelIds)
            ->where('is_eliminated', false)
            ->orderBy('name')
            ->get();
    }

    public function pickModel(Season $season, User $user, TopModel $topModel): DraftPick
    {
        $currentDrafter = $this->getCurrentDrafter($season);

        if (! $currentDrafter || $currentDrafter->id !== $user->id) {
            throw new \InvalidArgumentException('It is not this player\'s turn to pick.');
        }

        $alreadyPicked = $season->draftPicks()->where('top_model_id', $topModel->id)->exists();
        if ($alreadyPicked) {
            throw new \InvalidArgumentException('This model has already been picked.');
        }

        $pickNumber = $this->getCurrentPickNumber($season);
        $playersCount = $season->draftOrders()->count();
        $round = (int) ceil($pickNumber / $playersCount);

        $draftPick = DraftPick::create([
            'season_id' => $season->id,
            'user_id' => $user->id,
            'top_model_id' => $topModel->id,
            'round' => $round,
            'pick_number' => $pickNumber,
        ]);

        PlayerModel::create([
            'user_id' => $user->id,
            'top_model_id' => $topModel->id,
            'season_id' => $season->id,
            'picked_at' => now(),
            'pick_type' => PickType::Draft,
        ]);

        $nextDrafter = $this->getCurrentDrafter($season);
        if ($nextDrafter) {
            Notification::make()
                ->title("It's your turn to draft!")
                ->body('Head to the Draft Room to make your pick.')
                ->sendToDatabase($nextDrafter);
        }

        return $draftPick;
    }

    public function isDraftComplete(Season $season): bool
    {
        $totalPicks = $season->draftOrders()->count() * $season->models_per_player;

        return $season->draftPicks()->count() >= $totalPicks;
    }

    public function setDraftOrder(Season $season, array $userIds): void
    {
        $season->draftOrders()->delete();

        foreach ($userIds as $position => $userId) {
            DraftOrder::create([
                'season_id' => $season->id,
                'user_id' => $userId,
                'position' => $position + 1,
            ]);
        }
    }
}
