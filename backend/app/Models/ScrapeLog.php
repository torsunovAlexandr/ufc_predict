<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapeLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
