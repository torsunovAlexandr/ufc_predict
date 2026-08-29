<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FighterStat extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fight_date' => 'date',
            'rounds' => 'array',
            'is_title_fight' => 'boolean',
            'opponent_quality' => 'float',
        ];
    }

    public function fighter(): BelongsTo
    {
        return $this->belongsTo(Fighter::class);
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Fighter::class, 'opponent_id');
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }
}
