<?php

namespace App\Models;

use App\Enums\EpisodeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Episode extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'number',
        'title',
        'status',
        'aired_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => EpisodeStatus::class,
            'aired_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }

    public function eliminatedModels(): HasMany
    {
        return $this->hasMany(TopModel::class, 'eliminated_in_episode_id');
    }
}
