<?php

namespace App\Models;

use App\Enums\SeasonStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'year',
        'status',
        'models_per_player',
    ];

    protected function casts(): array
    {
        return [
            'status' => SeasonStatus::class,
            'models_per_player' => 'integer',
            'year' => 'integer',
        ];
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function topModels(): HasMany
    {
        return $this->hasMany(TopModel::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    public function draftOrders(): HasMany
    {
        return $this->hasMany(DraftOrder::class);
    }

    public function draftPicks(): HasMany
    {
        return $this->hasMany(DraftPick::class);
    }

    public function playerModels(): HasMany
    {
        return $this->hasMany(PlayerModel::class);
    }

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('is_eliminated')->withTimestamps();
    }
}
