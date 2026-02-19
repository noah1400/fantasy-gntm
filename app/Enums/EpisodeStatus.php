<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum EpisodeStatus: string implements HasColor, HasLabel
{
    case Upcoming = 'upcoming';
    case Active = 'active';
    case Ended = 'ended';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::Active => 'Active',
            self::Ended => 'Ended',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Upcoming => 'gray',
            self::Active => 'success',
            self::Ended => 'info',
        };
    }
}
