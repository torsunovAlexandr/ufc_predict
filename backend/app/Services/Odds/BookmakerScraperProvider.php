<?php

namespace App\Services\Odds;

use App\Models\Event;
use App\Services\Scraping\Dom;
use App\Services\Scraping\HttpFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Запасной источник коэффициентов — парсинг сайта букмекера.
 *
 * По умолчанию ВЫКЛЮЧЕН (config('ufc.odds.scrapers.*.enabled')): у большинства
 * букмекеров линия подгружается скриптами, а правила сайта прямо запрещают
 * автоматический сбор. Включайте осознанно и только для личного использования.
 *
 * Ограничение по ТЗ — не чаще одного запроса в 10 минут на конкретный сайт —
 * соблюдается через блокировку в кэше.
 */
class BookmakerScraperProvider implements OddsProvider
{
    public function __construct(private readonly HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'bookmaker_scraper';
    }

    public function isAvailable(): bool
    {
        foreach ((array) config('ufc.odds.scrapers') as $scraper) {
            if ($scraper['enabled'] ?? false) {
                return true;
            }
        }

        return false;
    }

    public function fetchForEvent(Event $event): array
    {
        $quotes = [];

        foreach ((array) config('ufc.odds.scrapers') as $key => $scraper) {
            if (! ($scraper['enabled'] ?? false)) {
                continue;
            }

            if (! $this->acquireLock($key)) {
                Log::channel('scraping')->info("Парсер {$key}: слишком рано для нового запроса.");

                continue;
            }

            $html = $this->fetcher->fetch('bookmaker_'.$key, $scraper['url'], 1);

            if (! $html) {
                continue;
            }

            $quotes = array_merge($quotes, $this->parse($key, $html));
        }

        return $quotes;
    }

    /**
     * Разбор страницы линии. Разметка у каждого букмекера своя, поэтому
     * здесь реализован обобщённый вариант: ищем строки с двумя именами
     * и двумя числами-коэффициентами.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $bookmaker, string $html): array
    {
        $crawler = new Crawler($html);
        $quotes = [];

        $rowSelectors = [
            '.coupon-row', '.bet-event', '.c-events__item', 'tr.event',
        ];

        foreach ($rowSelectors as $selector) {
            try {
                $rows = $crawler->filter($selector);
            } catch (\Throwable) {
                continue;
            }

            if ($rows->count() === 0) {
                continue;
            }

            $rows->each(function (Crawler $row) use (&$quotes, $bookmaker) {
                $text = Dom::clean($row->text(''));

                if (! preg_match('/(.+?)\s+[–\-—vs\.]+\s+(.+?)\s/ui', $text, $names)) {
                    return;
                }

                preg_match_all('/\b(\d+[.,]\d{1,2})\b/', $text, $prices);

                if (count($prices[1] ?? []) < 2) {
                    return;
                }

                $quotes[] = [
                    'fighter1' => Dom::clean($names[1]),
                    'fighter2' => Dom::clean($names[2]),
                    'commence_time' => null,
                    'bookmaker' => $bookmaker,
                    'market' => 'moneyline',
                    'selection' => 'fighter1',
                    'line' => null,
                    'price' => (float) str_replace(',', '.', $prices[1][0]),
                ];

                $quotes[] = [
                    'fighter1' => Dom::clean($names[1]),
                    'fighter2' => Dom::clean($names[2]),
                    'commence_time' => null,
                    'bookmaker' => $bookmaker,
                    'market' => 'moneyline',
                    'selection' => 'fighter2',
                    'line' => null,
                    'price' => (float) str_replace(',', '.', $prices[1][1]),
                ];
            });

            break;
        }

        if ($quotes === []) {
            Log::channel('scraping')->warning("Парсер {$bookmaker} не нашёл котировок — вероятно, линия грузится скриптом.");
        }

        return $quotes;
    }

    /** Не чаще одного запроса в 10 минут на конкретный сайт. */
    private function acquireLock(string $key): bool
    {
        $minutes = (int) config('ufc.odds.scraper_interval_minutes', 10);

        return Cache::add("odds:scraper:{$key}", true, now()->addMinutes($minutes));
    }
}
