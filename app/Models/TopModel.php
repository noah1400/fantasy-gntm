<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TopModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'name',
        'slug',
        'image',
        'is_eliminated',
        'eliminated_in_episode_id',
    ];

    protected function casts(): array
    {
        return [
            'is_eliminated' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TopModel $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function eliminatedInEpisode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'eliminated_in_episode_id');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }

    public function playerModels(): HasMany
    {
        return $this->hasMany(PlayerModel::class);
    }
}
