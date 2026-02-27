<?php

namespace App\Models;

use App\Enums\GameEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'episode_id',
        'game_phase_id',
        'type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => GameEventType::class,
            'payload' => 'array',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function gamePhase(): BelongsTo
    {
        return $this->belongsTo(GamePhase::class);
    }
}
