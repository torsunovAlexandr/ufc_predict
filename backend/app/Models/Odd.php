<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Odd extends Model
{
    use HasFactory;

    protected $table = 'odds';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'line' => 'float',
            'implied_probability' => 'float',
            'is_latest' => 'boolean',
            'fetched_at' => 'datetime',
        ];
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }

    public function fighter(): BelongsTo
    {
        return $this->belongsTo(Fighter::class);
    }

    /** Ключ рынка вида «moneyline:fighter1» или «totals:over:2.5». */
    public function marketKey(): string
    {
        return $this->line !== null
            ? "{$this->market}:{$this->selection}:{$this->line}"
            : "{$this->market}:{$this->selection}";
    }
}
