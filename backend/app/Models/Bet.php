<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bet extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'odds' => 'float',
            'line' => 'float',
            'model_probability' => 'float',
            'implied_probability' => 'float',
            'expected_value' => 'float',
            'kelly_fraction' => 'float',
            'stake_fraction' => 'float',
            'stake' => 'float',
            'payout' => 'float',
            'profit' => 'float',
            'bankroll_before' => 'float',
            'bankroll_after' => 'float',
            'is_benchmark' => 'boolean',
            'placed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }

    public function fighter(): BelongsTo
    {
        return $this->belongsTo(Fighter::class);
    }

    /**
     * Во всех скоупах колонки квалифицированы: скоуп может применяться
     * к запросу с join, где имя колонки окажется неоднозначным.
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('status'), ['won', 'lost', 'void']);
    }

    /** Только реальные ставки — без смоделированных в бэктесте. */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_benchmark'), false);
    }

    public function scopeBenchmark(Builder $query, ?string $strategy = null): Builder
    {
        $query = $query->where($query->qualifyColumn('is_benchmark'), true);

        return $strategy
            ? $query->where($query->qualifyColumn('benchmark_strategy'), $strategy)
            : $query;
    }
}
