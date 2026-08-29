<?php

namespace App\Services\Scraping;

use App\Models\Fighter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер карточки бойца с ufc.com/athlete/{slug}.
 *
 * Со страницы забираются: физика, рекорд, разбивка побед по методам
 * и карьерная статистика (удары, тейкдауны, защита).
 *
 * Как и в UfcEventScraper, селекторы вынесены в константы —
 * при изменении вёрстки правится только этот файл.
 */
class UfcFighterScraper
{
    /** Подписи полей в блоке биографии -> внутренние ключи */
    private const BIO_FIELDS = [
        'status' => 'status',
        'hometown' => 'hometown',
        'place of birth' => 'hometown',
        'age' => 'age',
        'height' => 'height',
        'weight' => 'weight',
        'reach' => 'reach',
        'leg reach' => 'leg_reach',
        'fighting style' => 'fighting_style',
        'octagon debut' => 'debut',
        'trains at' => 'gym',
    ];

    public function __construct(private readonly HttpFetcher $fetcher) {}

    /** Обновить карточку бойца. Возвращает true, если данные удалось получить. */
    public function sync(Fighter $fighter, bool $force = false): bool
    {
        $url = $fighter->ufc_url ?: $this->guessUrl($fighter);

        if (! $url) {
            return false;
        }

        $html = $this->fetcher->fetch('ufc', $url, null, $force);

        if (! $html) {
            return false;
        }

        $data = $this->parse($html);

        if ($data === []) {
            Log::channel('scraping')->warning("Не удалось разобрать карточку бойца {$fighter->name} ({$url}).");

            return false;
        }

        $fighter->fill(array_merge($data, [
            'ufc_url' => $url,
            'last_scraped_at' => now(),
            'stats_updated_at' => now(),
        ]))->save();

        return true;
    }

    /**
     * Разбор HTML карточки бойца.
     *
     * @return array<string, mixed>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler($html, config('ufc.sources.ufc.base_url'));
        $data = [];

        // --- Прозвище и изображение ---
        if ($nickname = Dom::text($crawler, ['.hero-profile__nickname', '.field--name-nickname'])) {
            $data['nickname'] = trim($nickname, '"“” ');
        }

        if ($image = Dom::attr($crawler, ['.hero-profile__image img', '.c-bio__image img'], 'src')) {
            $data['image_url'] = $image;
        }

        if ($division = Dom::text($crawler, ['.hero-profile__division-title', '.c-bio__field--division .c-bio__text'])) {
            $data['weight_class'] = preg_replace('/\s*division\s*/i', '', $division);
        }

        // --- Рекорд ---
        $recordText = Dom::text($crawler, ['.hero-profile__division-body', '.c-hero__headline-suffix']);
        if ($record = Dom::parseRecord($recordText)) {
            $data = array_merge($data, $record);
        }

        // --- Блок биографии: пары «подпись — значение» ---
        $bio = $this->parseBio($crawler);

        if (isset($bio['age'])) {
            $data['age'] = Dom::integer($bio['age']);
        }

        if (isset($bio['height'])) {
            $data['height_cm'] = Dom::heightToCm($bio['height']);
        }

        if (isset($bio['reach'])) {
            $data['reach_cm'] = Dom::reachToCm($bio['reach']);
        }

        if (isset($bio['leg_reach'])) {
            $data['leg_reach_cm'] = Dom::reachToCm($bio['leg_reach']);
        }

        if (isset($bio['weight'])) {
            $weight = Dom::number($bio['weight']);
            // На ufc.com вес указан в фунтах
            $data['weight_kg'] = $weight ? round($weight * 0.4536, 2) : null;
        }

        if (isset($bio['hometown'])) {
            $parts = array_map('trim', explode(',', $bio['hometown']));
            $data['country'] = count($parts) > 1 ? end($parts) : $bio['hometown'];
        }

        if (isset($bio['fighting_style'])) {
            $data['raw_data'] = ['fighting_style' => $bio['fighting_style']];
        }

        if (isset($bio['date of birth'])) {
            try {
                $data['date_of_birth'] = Carbon::parse($bio['date of birth'])->toDateString();
            } catch (\Throwable) {
                // дата рождения не обязательна
            }
        }

        // --- Стойка ---
        $stance = $bio['stance'] ?? Dom::text($crawler, ['.c-bio__field--stance .c-bio__text']);
        $data['stance'] = Dom::normalizeStance($stance);

        // --- Разбивка побед по методам ---
        $data = array_merge($data, $this->parseWinMethods($crawler));

        // --- Карьерная статистика ---
        $data = array_merge($data, $this->parseCareerStats($crawler));

        return array_filter($data, fn ($value) => $value !== null);
    }

    /**
     * Блок c-bio: подписи и значения идут парами.
     *
     * @return array<string, string>
     */
    private function parseBio(Crawler $crawler): array
    {
        $bio = [];

        try {
            $crawler->filter('.c-bio__field')->each(function (Crawler $field) use (&$bio) {
                $label = mb_strtolower(Dom::clean($field->filter('.c-bio__label')->text('')));
                $value = Dom::clean($field->filter('.c-bio__text')->text(''));

                if ($label === '' || $value === '') {
                    return;
                }

                $key = self::BIO_FIELDS[$label] ?? $label;
                $bio[$key] = $value;
            });
        } catch (\Throwable) {
            // блок биографии отсутствует
        }

        return $bio;
    }

    /** @return array<string, int> */
    private function parseWinMethods(Crawler $crawler): array
    {
        $methods = [];

        try {
            $crawler->filter('.c-stat-3bar__group')->each(function (Crawler $group) use (&$methods) {
                $label = mb_strtolower(Dom::clean($group->filter('.c-stat-3bar__label')->text('')));
                $value = Dom::integer(Dom::clean($group->filter('.c-stat-3bar__value')->text('')));

                if ($value === null) {
                    return;
                }

                if (str_contains($label, 'ko') || str_contains($label, 'tko')) {
                    $methods['wins_by_ko'] = $value;
                } elseif (str_contains($label, 'sub')) {
                    $methods['wins_by_submission'] = $value;
                } elseif (str_contains($label, 'dec')) {
                    $methods['wins_by_decision'] = $value;
                }
            });
        } catch (\Throwable) {
            // разбивки нет
        }

        return $methods;
    }

    /**
     * Числовые показатели: удары в минуту, точность, тейкдауны, защита.
     *
     * @return array<string, float|int|null>
     */
    private function parseCareerStats(Crawler $crawler): array
    {
        $stats = [];

        // Блок «Striking accuracy / Takedown accuracy» — круговые диаграммы
        try {
            $crawler->filter('.c-overlap__stats')->each(function (Crawler $row) use (&$stats) {
                $label = mb_strtolower(Dom::clean($row->filter('.c-overlap__stats-text')->text('')));
                $value = Dom::number(Dom::clean($row->filter('.c-overlap__stats-value')->text('')));

                if ($value === null) {
                    return;
                }

                $stats[$label] = $value;
            });
        } catch (\Throwable) {
            // блок отсутствует
        }

        // Основной блок статистики: пары «подпись — значение»
        $compare = [];

        try {
            $crawler->filter('.c-stat-compare__group')->each(function (Crawler $group) use (&$compare) {
                $label = mb_strtolower(Dom::clean($group->filter('.c-stat-compare__label')->text('')));
                $raw = Dom::clean($group->filter('.c-stat-compare__number')->text(''));
                $suffix = Dom::clean($group->filter('.c-stat-compare__percent')->text(''));

                if ($label === '' || $raw === '') {
                    return;
                }

                $compare[$label] = Dom::number($raw.$suffix) !== null
                    ? (str_contains($suffix, '%') ? Dom::number($raw) / 100 : Dom::number($raw))
                    : null;
            });
        } catch (\Throwable) {
            // блок отсутствует
        }

        $map = [
            'sig. str. landed' => 'sig_strikes_landed_per_min',
            'sig. strikes landed' => 'sig_strikes_landed_per_min',
            'sig. str. absorbed' => 'sig_strikes_absorbed_per_min',
            'sig. strikes absorbed' => 'sig_strikes_absorbed_per_min',
            'takedown avg' => 'takedown_avg_per_15min',
            'takedown average' => 'takedown_avg_per_15min',
            'submission avg' => 'submission_avg_per_15min',
            'submission average' => 'submission_avg_per_15min',
            'takedown defense' => 'takedown_defense',
            'sig. str. defense' => 'striking_defense',
            'striking defense' => 'striking_defense',
            'knockdown avg' => 'knockdown_avg',
        ];

        foreach ($compare as $label => $value) {
            foreach ($map as $needle => $key) {
                if ($value !== null && str_contains($label, $needle)) {
                    $stats[$key] = $value;
                }
            }
        }

        // Круговые диаграммы точности
        foreach ($stats as $label => $value) {
            if (is_string($label) && str_contains($label, 'striking accuracy')) {
                $stats['striking_accuracy'] = $value > 1 ? $value / 100 : $value;
            }

            if (is_string($label) && str_contains($label, 'takedown accuracy')) {
                $stats['takedown_accuracy'] = $value > 1 ? $value / 100 : $value;
            }
        }

        $allowed = [
            'sig_strikes_landed_per_min', 'sig_strikes_absorbed_per_min', 'striking_accuracy',
            'striking_defense', 'takedown_avg_per_15min', 'takedown_accuracy', 'takedown_defense',
            'submission_avg_per_15min', 'knockdown_avg',
        ];

        return array_intersect_key($stats, array_flip($allowed));
    }

    private function guessUrl(Fighter $fighter): ?string
    {
        $slug = $fighter->slug ?: Str::slug($fighter->name);

        if ($slug === '') {
            return null;
        }

        return rtrim((string) config('ufc.sources.ufc.base_url'), '/')
            .config('ufc.sources.ufc.athlete_path')
            .$slug;
    }
}
