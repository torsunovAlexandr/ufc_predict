<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fighter extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'raw_data' => 'array',
            'stats_updated_at' => 'datetime',
            'last_scraped_at' => 'datetime',
            'height_cm' => 'integer',
            'reach_cm' => 'integer',
            'leg_reach_cm' => 'integer',
            'weight_kg' => 'float',
            'sig_strikes_landed_per_min' => 'float',
            'sig_strikes_absorbed_per_min' => 'float',
            'striking_accuracy' => 'float',
            'striking_defense' => 'float',
            'takedown_avg_per_15min' => 'float',
            'takedown_accuracy' => 'float',
            'takedown_defense' => 'float',
            'submission_avg_per_15min' => 'float',
            'knockdown_avg' => 'float',
        ];
    }

    public function stats(): HasMany
    {
        return $this->hasMany(FighterStat::class)->orderByDesc('fight_date');
    }

    public function fightsAsFirst(): HasMany
    {
        return $this->hasMany(Fight::class, 'fighter1_id');
    }

    public function fightsAsSecond(): HasMany
    {
        return $this->hasMany(Fight::class, 'fighter2_id');
    }

    /** Возраст на указанную дату (по умолчанию — сегодня). */
    public function ageAt(?\DateTimeInterface $date = null): ?int
    {
        if (! $this->date_of_birth) {
            return $this->age;
        }

        return (int) $this->date_of_birth->diffInYears($date ?? now());
    }

    public function recordString(): string
    {
        $record = "{$this->wins}-{$this->losses}-{$this->draws}";

        return $this->no_contests > 0 ? $record." ({$this->no_contests} NC)" : $record;
    }

    /** Доля досрочных побед. */
    public function finishRate(): float
    {
        if ($this->wins <= 0) {
            return 0.0;
        }

        return ($this->wins_by_ko + $this->wins_by_submission) / $this->wins;
    }
}
