<?php

namespace App\Services\Scraping;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Вспомогательные функции разбора HTML. Все методы «мягкие»: если селектор
 * не найден, возвращается null вместо исключения — вёрстка сайтов меняется,
 * и парсер не должен ронять всю загрузку из-за одного поля.
 */
final class Dom
{
    /** Текст первого узла по любому из селекторов. */
    public static function text(Crawler $crawler, string|array $selectors, ?string $default = null): ?string
    {
        foreach ((array) $selectors as $selector) {
            try {
                $node = $crawler->filter($selector);

                if ($node->count() > 0) {
                    $text = self::clean($node->first()->text(''));

                    if ($text !== '') {
                        return $text;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $default;
    }

    /** Тексты всех узлов по селектору. */
    public static function texts(Crawler $crawler, string $selector): array
    {
        try {
            return $crawler->filter($selector)->each(fn (Crawler $node) => self::clean($node->text('')));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function attr(Crawler $crawler, string|array $selectors, string $attribute): ?string
    {
        foreach ((array) $selectors as $selector) {
            try {
                $node = $crawler->filter($selector);

                if ($node->count() > 0) {
                    $value = $node->first()->attr($attribute);

                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public static function clean(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)) ?? $value;

        return trim($value);
    }

    /** «53%» -> 0.53, «0.53» -> 0.53 */
    public static function percent(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (! preg_match('/(-?\d+(?:[.,]\d+)?)/', $value, $m)) {
            return null;
        }

        $number = (float) str_replace(',', '.', $m[1]);

        return str_contains($value, '%') ? $number / 100 : $number;
    }

    public static function number(?string $value): ?float
    {
        if ($value === null || ! preg_match('/(-?\d+(?:[.,]\d+)?)/', $value, $m)) {
            return null;
        }

        return (float) str_replace(',', '.', $m[1]);
    }

    public static function integer(?string $value): ?int
    {
        $number = self::number($value);

        return $number === null ? null : (int) round($number);
    }

    /** «5' 11"» -> 180 см; «180 cm» -> 180 */
    public static function heightToCm(?string $value): ?int
    {
        if (! $value) {
            return null;
        }

        if (preg_match('/(\d+)\s*(?:\'|ft|фут)\s*(\d+)?/ui', $value, $m)) {
            $feet = (int) $m[1];
            $inches = (int) ($m[2] ?? 0);

            return (int) round(($feet * 12 + $inches) * 2.54);
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:cm|см)/ui', $value, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]));
        }

        $number = self::number($value);

        // Голое число: дюймы, если меньше 100, иначе сантиметры
        if ($number === null) {
            return null;
        }

        return $number < 100 ? (int) round($number * 2.54) : (int) round($number);
    }

    /** «76.0» дюймов -> 193 см */
    public static function reachToCm(?string $value): ?int
    {
        return self::heightToCm($value);
    }

    /** «4:35» -> 275 секунд */
    public static function timeToSeconds(?string $value): ?int
    {
        if (! $value || ! preg_match('/(\d+):(\d{1,2})/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** «18-3-1 (1 NC)» -> [18, 3, 1, 1] */
    public static function parseRecord(?string $value): ?array
    {
        if (! $value || ! preg_match('/(\d+)\s*-\s*(\d+)\s*-\s*(\d+)/', $value, $m)) {
            return null;
        }

        preg_match('/\((\d+)\s*NC\)/i', $value, $nc);

        return [
            'wins' => (int) $m[1],
            'losses' => (int) $m[2],
            'draws' => (int) $m[3],
            'no_contests' => (int) ($nc[1] ?? 0),
        ];
    }

    /** Приведение метода победы к внутреннему словарю. */
    public static function normalizeMethod(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = mb_strtolower($value);

        return match (true) {
            str_contains($value, 'ko') || str_contains($value, 'tko') || str_contains($value, 'нокаут') => 'ko_tko',
            str_contains($value, 'sub') || str_contains($value, 'сабмиш') || str_contains($value, 'удуш')
                || str_contains($value, 'болев') => 'submission',
            str_contains($value, 'dec') || str_contains($value, 'решени') => 'decision',
            str_contains($value, 'dq') || str_contains($value, 'дисквал') => 'dq',
            default => 'other',
        };
    }

    public static function normalizeStance(?string $value): string
    {
        if (! $value) {
            return 'unknown';
        }

        $value = mb_strtolower($value);

        return match (true) {
            str_contains($value, 'southpaw') || str_contains($value, 'левш') => 'southpaw',
            str_contains($value, 'switch') || str_contains($value, 'универс') => 'switch',
            str_contains($value, 'orthodox') || str_contains($value, 'правш') => 'orthodox',
            default => 'unknown',
        };
    }
}
