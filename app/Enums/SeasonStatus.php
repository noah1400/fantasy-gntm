<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum SeasonStatus: string implements HasColor, HasLabel
{
    case Setup = 'setup';
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Setup => 'Setup',
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Setup => 'gray',
            self::Draft => 'warning',
            self::Active => 'success',
            self::Completed => 'info',
        };
    }
}
