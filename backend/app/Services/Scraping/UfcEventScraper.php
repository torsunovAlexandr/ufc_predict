<?php

namespace App\Services\Scraping;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Fighter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер турниров и кардов с ufc.com.
 *
 * ВАЖНО: вёрстка ufc.com периодически меняется. Все CSS-селекторы вынесены
 * в константы ниже — при изменении сайта правится только этот список.
 * Команда `php artisan ufc:sync-events --debug` печатает, что именно
 * удалось найти на странице, и помогает быстро подобрать новые селекторы.
 */
class UfcEventScraper
{
    /** Карточки турниров в списке /events */
    private const EVENT_CARD_SELECTORS = [
        '.c-card-event--result',
        '.l-listing__item .c-card-event--result',
        'article.c-card-event--result',
    ];

    /** Ссылка на страницу турнира внутри карточки */
    private const EVENT_LINK_SELECTORS = [
        '.c-card-event--result__headline a',
        'h3 a',
        'a[href*="/event/"]',
    ];

    /** Дата турнира */
    private const EVENT_DATE_SELECTORS = [
        '.c-card-event--result__date',
        '.c-card-event--result__date a',
        '[data-timestamp]',
    ];

    /** Блок одного боя на странице турнира */
    private const FIGHT_SELECTORS = [
        '.c-listing-fight',
        '.l-listing__item .c-listing-fight',
    ];

    public function __construct(private readonly HttpFetcher $fetcher) {}

    /**
     * Список предстоящих турниров. Возвращает массив массивов
     * с ключами name, url, starts_at.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchEventList(bool $force = false): array
    {
        $base = config('ufc.sources.ufc.base_url');
        $html = $this->fetcher->fetch('ufc', $base.config('ufc.sources.ufc.events_path'), null, $force);

        if (! $html) {
            return [];
        }

        $crawler = new Crawler($html, $base);
        $events = [];

        foreach (self::EVENT_CARD_SELECTORS as $selector) {
            $nodes = $this->safeFilter($crawler, $selector);

            if ($nodes->count() === 0) {
                continue;
            }

            $nodes->each(function (Crawler $card) use (&$events, $base) {
                $url = Dom::attr($card, self::EVENT_LINK_SELECTORS, 'href');

                if (! $url) {
                    return;
                }

                $url = str_starts_with($url, 'http') ? $url : rtrim($base, '/').'/'.ltrim($url, '/');

                $name = Dom::text($card, self::EVENT_LINK_SELECTORS)
                    ?? Dom::text($card, ['.c-card-event--result__headline'])
                    ?? Str::of($url)->afterLast('/')->replace('-', ' ')->title()->toString();

                $events[$url] = [
                    'name' => $name,
                    'url' => $url,
                    'starts_at' => $this->parseDate($card),
                    'venue' => Dom::text($card, ['.c-card-event--result__location .field--name-venue', '.c-card-event--result__location']),
                    'location' => Dom::text($card, ['.c-card-event--result__location']),
                ];
            });

            break;
        }

        return array_values($events);
    }

    /**
     * Синхронизировать список турниров с БД.
     *
     * @return array{created: int, updated: int}
     */
    public function syncEvents(bool $force = false): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->fetchEventList($force) as $data) {
            $slug = Str::slug(Str::of($data['url'])->afterLast('/')->toString() ?: $data['name']);

            $event = Event::firstOrNew(['slug' => $slug]);
            $isNew = ! $event->exists;

            $event->fill([
                'name' => $data['name'],
                'ufc_url' => $data['url'],
                'starts_at' => $data['starts_at'] ?? $event->starts_at ?? now()->addDays(7),
                'venue' => $data['venue'] ?? $event->venue,
                'city' => $this->cityFrom($data['location'] ?? null) ?? $event->city,
                'country' => $this->countryFrom($data['location'] ?? null) ?? $event->country,
                'status' => ($data['starts_at'] ?? null) && Carbon::parse($data['starts_at'])->isPast()
                    ? 'completed'
                    : 'scheduled',
                'last_scraped_at' => now(),
            ])->save();

            $isNew ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Разобрать карту боёв конкретного турнира и записать бои в БД.
     *
     * @return array<int, Fight>
     */
    public function syncFights(Event $event, bool $force = false): array
    {
        if (! $event->ufc_url) {
            return [];
        }

        $html = $this->fetcher->fetch('ufc', $event->ufc_url, null, $force);

        if (! $html) {
            return [];
        }

        $crawler = new Crawler($html, config('ufc.sources.ufc.base_url'));
        $fights = [];
        $order = 0;

        foreach (self::FIGHT_SELECTORS as $selector) {
            $nodes = $this->safeFilter($crawler, $selector);

            if ($nodes->count() === 0) {
                continue;
            }

            $nodes->each(function (Crawler $node) use ($event, &$fights, &$order) {
                $parsed = $this->parseFightNode($node);

                if (! $parsed) {
                    return;
                }

                $order++;

                $fighter1 = $this->resolveFighter($parsed['fighter1'], $parsed['fighter1_url'] ?? null);
                $fighter2 = $this->resolveFighter($parsed['fighter2'], $parsed['fighter2_url'] ?? null);

                if (! $fighter1 || ! $fighter2) {
                    return;
                }

                $fight = Fight::updateOrCreate(
                    [
                        'event_id' => $event->id,
                        'fighter1_id' => $fighter1->id,
                        'fighter2_id' => $fighter2->id,
                    ],
                    [
                        'weight_class' => $parsed['weight_class'],
                        'scheduled_rounds' => $parsed['rounds'],
                        'is_title_fight' => $parsed['is_title'],
                        'is_main_event' => $order === 1,
                        'card_segment' => $parsed['segment'],
                        'bout_order' => $order,
                    ]
                );

                $fights[] = $fight;
            });

            break;
        }

        $event->update(['last_scraped_at' => now()]);

        if ($fights === []) {
            Log::channel('scraping')->warning(
                "Не удалось разобрать бои турнира «{$event->name}» — вероятно, изменилась вёрстка ufc.com."
            );
        }

        return $fights;
    }

    /** @return array<string, mixed>|null */
    private function parseFightNode(Crawler $node): ?array
    {
        $names = Dom::texts($node, '.c-listing-fight__corner-name');

        if (count($names) < 2) {
            // Запасной вариант: имя и фамилия разнесены по отдельным элементам
            $given = Dom::texts($node, '.c-listing-fight__corner-given-name');
            $family = Dom::texts($node, '.c-listing-fight__corner-family-name');

            if (count($given) >= 2 && count($family) >= 2) {
                $names = [
                    trim($given[0].' '.$family[0]),
                    trim($given[1].' '.$family[1]),
                ];
            }
        }

        if (count($names) < 2) {
            return null;
        }

        $links = [];
        try {
            $links = $node->filter('.c-listing-fight__corner-link, a[href*="/athlete/"]')
                ->each(fn (Crawler $a) => $a->attr('href'));
        } catch (\Throwable) {
            $links = [];
        }

        $weightClass = Dom::text($node, [
            '.c-listing-fight__class-text',
            '.c-listing-fight__class',
        ]);

        $isTitle = str_contains(mb_strtolower((string) $weightClass), 'title')
            || $node->filter('.c-listing-fight__banner--belt')->count() > 0;

        return [
            'fighter1' => Dom::clean($names[0]),
            'fighter2' => Dom::clean($names[1]),
            'fighter1_url' => $links[0] ?? null,
            'fighter2_url' => $links[1] ?? null,
            'weight_class' => $weightClass ? preg_replace('/\s*title\s*bout\s*/i', '', $weightClass) : null,
            'is_title' => $isTitle,
            'rounds' => $isTitle ? 5 : 3,
            'segment' => $this->segmentOf($node),
        ];
    }

    private function segmentOf(Crawler $node): string
    {
        $text = mb_strtolower(Dom::clean($node->text('')));

        return match (true) {
            str_contains($text, 'early prelim') => 'early_prelim',
            str_contains($text, 'prelim') => 'prelim',
            default => 'main',
        };
    }

    /** Найти бойца в базе по имени или создать заготовку карточки. */
    public function resolveFighter(string $name, ?string $url = null): ?Fighter
    {
        $name = Dom::clean($name);

        if ($name === '') {
            return null;
        }

        $slug = Str::slug($name);

        $fighter = Fighter::where('slug', $slug)->first()
            ?? Fighter::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($fighter) {
            if ($url && ! $fighter->ufc_url) {
                $fighter->update(['ufc_url' => $this->absolute($url)]);
            }

            return $fighter;
        }

        return Fighter::create([
            'name' => $name,
            'slug' => $slug,
            'ufc_url' => $url ? $this->absolute($url) : null,
        ]);
    }

    private function absolute(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return rtrim((string) config('ufc.sources.ufc.base_url'), '/').'/'.ltrim($url, '/');
    }

    private function parseDate(Crawler $card): ?string
    {
        $timestamp = Dom::attr($card, ['[data-timestamp]'], 'data-timestamp');

        if ($timestamp && is_numeric($timestamp)) {
            return Carbon::createFromTimestamp((int) $timestamp)->toDateTimeString();
        }

        $text = Dom::text($card, self::EVENT_DATE_SELECTORS);

        if (! $text) {
            return null;
        }

        try {
            return Carbon::parse($text)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cityFrom(?string $location): ?string
    {
        if (! $location) {
            return null;
        }

        return Dom::clean(explode(',', $location)[0] ?? '') ?: null;
    }

    private function countryFrom(?string $location): ?string
    {
        if (! $location) {
            return null;
        }

        $parts = array_map('trim', explode(',', $location));

        return count($parts) > 1 ? end($parts) : null;
    }

    private function safeFilter(Crawler $crawler, string $selector): Crawler
    {
        try {
            return $crawler->filter($selector);
        } catch (\Throwable) {
            return new Crawler;
        }
    }
}
