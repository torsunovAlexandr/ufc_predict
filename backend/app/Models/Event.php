<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'last_scraped_at' => 'datetime',
            'altitude_meters' => 'integer',
        ];
    }

    public function fights(): HasMany
    {
        return $this->hasMany(Fight::class)->orderByDesc('is_main_event')->orderBy('bout_order');
    }

    /**
     * Колонки квалифицированы через qualifyColumn: у fights тоже есть `status`,
     * и без префикса запрос с join на обе таблицы падает с ошибкой MySQL 1052.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), 'scheduled')
            ->where($query->qualifyColumn('starts_at'), '>=', now()->subHours(12));
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('starts_at'), '<', now());
    }
}
