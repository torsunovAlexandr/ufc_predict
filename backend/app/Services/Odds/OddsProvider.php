<?php

namespace App\Services\Odds;

use App\Models\Event;

/**
 * Контракт поставщика коэффициентов. Позволяет подключать новые источники
 * (API, парсер БК, ручной ввод), не меняя остальной код.
 */
interface OddsProvider
{
    /** Короткое имя источника — попадает в поле odds.source. */
    public function name(): string;

    /** Доступен ли источник прямо сейчас (есть ключ, не исчерпана квота). */
    public function isAvailable(): bool;

    /**
     * Котировки по боям турнира.
     *
     * @return array<int, array{
     *   fighter1: string, fighter2: string, commence_time: ?string,
     *   bookmaker: string, market: string, selection: string,
     *   line: float|null, price: float
     * }>
     */
    public function fetchForEvent(Event $event): array;
}
