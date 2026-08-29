<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankrollEntry extends Model
{
    use HasFactory;

    protected $table = 'bankroll_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'occurred_at' => 'datetime',
        ];
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }
}
