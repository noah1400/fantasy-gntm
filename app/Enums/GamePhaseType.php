<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum GamePhaseType: string implements HasColor, HasLabel
{
    case MandatoryDrop = 'mandatory_drop';
    case PickRound = 'pick_round';
    case OptionalSwap = 'optional_swap';
    case TradingPhase = 'trading_phase';
    case ForceAssign = 'force_assign';
    case EliminatePlayer = 'eliminate_player';
    case SkipPlayer = 'skip_player';
    case Redistribute = 'redistribute';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::MandatoryDrop => 'Mandatory Drop',
            self::PickRound => 'Pick Round',
            self::OptionalSwap => 'Optional Swap',
            self::TradingPhase => 'Trading Phase',
            self::ForceAssign => 'Force Assign',
            self::EliminatePlayer => 'Eliminate Player',
            self::SkipPlayer => 'Skip Player',
            self::Redistribute => 'Redistribute',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::MandatoryDrop => 'warning',
            self::PickRound => 'success',
            self::OptionalSwap => 'info',
            self::TradingPhase => 'info',
            self::ForceAssign => 'danger',
            self::EliminatePlayer => 'danger',
            self::SkipPlayer => 'gray',
            self::Redistribute => 'warning',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::MandatoryDrop => 'All players must drop models until they reach the target count. Everyone acts at the same time — no turn order. Auto-completes when all players are at or below the target.',
            self::PickRound => 'Players with fewer models than the threshold pick a free agent. Turn order: lowest points goes first. Auto-completes when all eligible players have picked or no free agents remain.',
            self::OptionalSwap => 'Each player may swap one of their models for a free agent, or skip. Turn order: lowest points goes first. Auto-completes when all players have swapped or skipped.',
            self::TradingPhase => 'All players can freely swap any of their models for free agents at the same time. No turn order, no limit. Must be closed manually by admin.',
            self::ForceAssign => 'Instantly assigns a specific free agent to a player. Executes immediately — no player action needed.',
            self::EliminatePlayer => 'Instantly eliminates a player from the season. Executes immediately.',
            self::SkipPlayer => 'Skips a player\'s turn in the current phase. Executes immediately.',
            self::Redistribute => 'Redistributes models among players. Not yet implemented.',
        };
    }

    public function isInstant(): bool
    {
        return in_array($this, [
            self::ForceAssign,
            self::EliminatePlayer,
            self::SkipPlayer,
            self::Redistribute,
        ]);
    }

    public function isSimultaneous(): bool
    {
        return in_array($this, [
            self::MandatoryDrop,
            self::TradingPhase,
        ]);
    }

    public function isTurnBased(): bool
    {
        return in_array($this, [
            self::PickRound,
            self::OptionalSwap,
        ]);
    }
}
