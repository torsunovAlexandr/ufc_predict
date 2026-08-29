<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Result extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_draw' => 'boolean',
            'is_no_contest' => 'boolean',
            'entered_manually' => 'boolean',
        ];
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Fighter::class, 'winner_id');
    }
}
