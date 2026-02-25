<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum PickType: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case FreeAgent = 'free_agent';
    case PreEpisodeSwap = 'pre_episode_swap';
    case Swap = 'swap';

    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::FreeAgent => 'Free Agent',
            self::PreEpisodeSwap => 'Pre-Episode Swap',
            self::Swap => 'Swap',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'info',
            self::FreeAgent => 'success',
            self::PreEpisodeSwap => 'info',
            self::Swap => 'warning',
        };
    }
}
