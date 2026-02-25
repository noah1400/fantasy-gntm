<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum GameEventType: string implements HasColor, HasLabel
{
    case Elimination = 'elimination';
    case FreeAgentPick = 'free_agent_pick';
    case MandatoryDrop = 'mandatory_drop';
    case ModelDrop = 'model_drop';
    case ModelSwap = 'model_swap';
    case PlayerEliminated = 'player_eliminated';
    case PreEpisodeSwap = 'pre_episode_swap';
    case SwapSkipped = 'swap_skipped';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Elimination => 'Elimination',
            self::FreeAgentPick => 'Free Agent Pick',
            self::MandatoryDrop => 'Mandatory Drop',
            self::ModelDrop => 'Model Drop',
            self::ModelSwap => 'Model Swap',
            self::PlayerEliminated => 'Player Eliminated',
            self::PreEpisodeSwap => 'Pre-Episode Swap',
            self::SwapSkipped => 'Swap Skipped',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Elimination => 'danger',
            self::FreeAgentPick => 'success',
            self::MandatoryDrop => 'warning',
            self::ModelDrop => 'warning',
            self::ModelSwap => 'info',
            self::PlayerEliminated => 'danger',
            self::PreEpisodeSwap => 'info',
            self::SwapSkipped => 'gray',
        };
    }
}
