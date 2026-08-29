<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Fight extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_title_fight' => 'boolean',
            'is_main_event' => 'boolean',
            'scheduled_rounds' => 'integer',
            'bout_order' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function fighter1(): BelongsTo
    {
        return $this->belongsTo(Fighter::class, 'fighter1_id');
    }

    public function fighter2(): BelongsTo
    {
        return $this->belongsTo(Fighter::class, 'fighter2_id');
    }

    public function odds(): HasMany
    {
        return $this->hasMany(Odd::class);
    }

    public function latestOdds(): HasMany
    {
        return $this->hasMany(Odd::class)->where('is_latest', true);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    /** Актуальный прогноз — последний из помеченных is_current. */
    public function currentPrediction(): HasOne
    {
        return $this->hasOne(Prediction::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('is_current', true)
        );
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(Result::class);
    }

    public function title(): string
    {
        return ($this->fighter1?->name ?? '?').' — '.($this->fighter2?->name ?? '?');
    }
}
