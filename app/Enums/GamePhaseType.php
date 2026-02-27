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
