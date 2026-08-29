<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prediction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'probability_fighter1' => 'float',
            'probability_fighter2' => 'float',
            'probability_draw' => 'float',
            'score_fighter1' => 'float',
            'score_fighter2' => 'float',
            'method_probabilities' => 'array',
            'probability_over_2_5' => 'float',
            'probability_under_2_5' => 'float',
            'factors' => 'array',
            'applied_rules' => 'array',
            'confidence' => 'float',
            'data_completeness' => 'float',
            'recommended_odds' => 'float',
            'recommended_stake' => 'float',
            'recommended_ev' => 'float',
            'is_current' => 'boolean',
        ];
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    /** Кого модель считает фаворитом. */
    public function favouriteId(): ?int
    {
        $fight = $this->fight;

        if (! $fight) {
            return null;
        }

        return $this->probability_fighter1 >= $this->probability_fighter2
            ? $fight->fighter1_id
            : $fight->fighter2_id;
    }
}
