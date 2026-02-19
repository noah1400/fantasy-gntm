<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftPick extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'user_id',
        'top_model_id',
        'round',
        'pick_number',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'pick_number' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topModel(): BelongsTo
    {
        return $this->belongsTo(TopModel::class);
    }
}
