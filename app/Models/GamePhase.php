<?php

namespace App\Models;

use App\Enums\GamePhaseStatus;
use App\Enums\GamePhaseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GamePhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'episode_id',
        'type',
        'config',
        'position',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => GamePhaseType::class,
            'status' => GamePhaseStatus::class,
            'config' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GamePhaseStatus::Active);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', GamePhaseStatus::Pending);
    }
}
