<?php

namespace App\Models;

use App\Enums\PickType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'top_model_id',
        'season_id',
        'picked_at',
        'dropped_at',
        'pick_type',
    ];

    protected function casts(): array
    {
        return [
            'picked_at' => 'datetime',
            'dropped_at' => 'datetime',
            'pick_type' => PickType::class,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dropped_at');
    }

    public function scopeForSeason(Builder $query, int|Season $season): Builder
    {
        $seasonId = $season instanceof Season ? $season->id : $season;

        return $query->where('season_id', $seasonId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topModel(): BelongsTo
    {
        return $this->belongsTo(TopModel::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}
