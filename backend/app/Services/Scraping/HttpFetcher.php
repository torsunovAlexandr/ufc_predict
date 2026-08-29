<?php

namespace App\Services\Scraping;

use App\Models\ScrapeLog;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Единая точка обращения к внешним сайтам (раздел 2 ТЗ).
 *
 * Отвечает за:
 *  - кэширование ответов в таблице scrape_logs (по умолчанию 24 часа);
 *  - паузу между запросами к одному источнику;
 *  - учёт robots.txt;
 *  - журналирование всех обращений.
 */
class HttpFetcher
{
    private ?Client $client = null;

    public function __construct(private readonly array $config) {}

    /**
     * Получить HTML страницы. Возвращает null, если запрос запрещён
     * robots.txt или завершился ошибкой.
     *
     * @param  string  $source  ключ источника из config('ufc.sources')
     */
    public function fetch(string $source, string $url, ?int $ttlHours = null, bool $force = false): ?string
    {
        $settings = $this->sourceSettings($source);
        $ttlHours = $ttlHours ?? (int) ($settings['page_ttl_hours'] ?? 24);
        $hash = sha1($url);

        // 1. Кэш
        if (! $force) {
            $cached = ScrapeLog::query()
                ->where('url_hash', $hash)
                ->where('status', '!=', 'failed')
                ->whereNotNull('body')
                ->where('expires_at', '>', now())
                ->latest('fetched_at')
                ->first();

            if ($cached) {
                return $cached->body;
            }
        }

        // 2. robots.txt
        if (($settings['respect_robots'] ?? true) && ! $this->isAllowedByRobots($url, $settings)) {
            $this->log($source, $url, $hash, 'skipped', null, null, 'Запрещено robots.txt');
            Log::channel('scraping')->warning("robots.txt запрещает загрузку {$url}");

            return null;
        }

        // 3. Пауза между запросами к одному источнику
        $this->throttle($source, (int) ($settings['request_delay_seconds'] ?? 3));

        // 4. Запрос
        $startedAt = microtime(true);

        try {
            $response = $this->client($settings)->get($url);
            $body = (string) $response->getBody();
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $this->log(
                $source, $url, $hash, 'ok', $response->getStatusCode(),
                $body, null, $ttlHours, $duration
            );

            return $body;
        } catch (GuzzleException $e) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->log($source, $url, $hash, 'failed', null, null, $e->getMessage(), 0, $duration);
            Log::channel('scraping')->error("Ошибка загрузки {$url}: ".$e->getMessage());

            return null;
        }
    }

    /** Запрос к JSON API с той же защитой по частоте и журналированием. */
    public function fetchJson(string $source, string $url, array $query = [], ?int $ttlMinutes = null, bool $force = false): ?array
    {
        $fullUrl = $url.($query ? '?'.http_build_query($query) : '');
        dd($fullUrl);
        $hash = sha1($fullUrl);

        if (! $force && $ttlMinutes) {
            $cached = ScrapeLog::query()
                ->where('url_hash', $hash)
                ->where('status', 'ok')
                ->whereNotNull('body')
                ->where('expires_at', '>', now())
                ->latest('fetched_at')
                ->first();

            if ($cached) {
                return json_decode($cached->body, true);
            }
        }

        $settings = $this->sourceSettings($source);
        $this->throttle($source, (int) ($settings['request_delay_seconds'] ?? 1));

        try {
            $response = $this->client($settings)->get($url, ['query' => $query]);
            $body = (string) $response->getBody();

            $this->log($source, $fullUrl, $hash, 'ok', $response->getStatusCode(), $body, null, ($ttlMinutes ?? 0) / 60);

            return json_decode($body, true);
        } catch (GuzzleException $e) {
            $this->log($source, $fullUrl, $hash, 'failed', null, null, $e->getMessage());
            Log::channel('scraping')->error("Ошибка API {$url}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Можно ли сейчас запускать полное обновление по источнику.
     * По ТЗ — не чаще одного раза в 3 часа.
     */
    public function canRefreshSource(string $source): bool
    {
        $settings = $this->sourceSettings($source);
        $interval = (int) ($settings['source_interval_minutes'] ?? 180);

        $last = ScrapeLog::query()
            ->where('source', $source)
            ->where('status', 'ok')
            ->latest('fetched_at')
            ->first();

        return ! $last || $last->fetched_at->lt(now()->subMinutes($interval));
    }

    /** Сколько запросов к источнику сделано за сегодня (для дневных квот API). */
    public function requestsToday(string $source): int
    {
        return ScrapeLog::query()
            ->where('source', $source)
            ->where('status', 'ok')
            ->whereDate('fetched_at', now()->toDateString())
            ->count();
    }

    /** Пауза, чтобы не превысить допустимую частоту обращений. */
    private function throttle(string $source, int $delaySeconds): void
    {
        if ($delaySeconds <= 0) {
            return;
        }

        $key = "scrape:last:{$source}";
        $last = Cache::get($key);

        if ($last) {
            $elapsed = microtime(true) - (float) $last;

            if ($elapsed < $delaySeconds) {
                usleep((int) (($delaySeconds - $elapsed) * 1_000_000));
            }
        }

        Cache::put($key, microtime(true), now()->addHour());
    }

    /** Простая проверка robots.txt: ищем Disallow для нашего User-agent и для *. */
    private function isAllowedByRobots(string $url, array $settings): bool
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $robotsUrl = "{$parts['scheme']}://{$parts['host']}/robots.txt";
        $path = $parts['path'] ?? '/';

        $rules = Cache::remember('robots:'.$parts['host'], now()->addDay(), function () use ($robotsUrl, $settings) {
            try {
                $body = (string) $this->client($settings)->get($robotsUrl)->getBody();
            } catch (GuzzleException) {
                return []; // robots.txt недоступен — считаем, что ограничений нет
            }

            return $this->parseRobots($body);
        });

        foreach ($rules as $rule) {
            if ($rule !== '' && str_starts_with($path, $rule)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> список запрещённых префиксов пути */
    private function parseRobots(string $body): array
    {
        $disallow = [];
        $applies = false;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                $applies = trim($m[1]) === '*';

                continue;
            }

            if ($applies && preg_match('/^Disallow:\s*(.*)$/i', $line, $m)) {
                $value = trim($m[1]);

                if ($value !== '') {
                    $disallow[] = $value;
                }
            }
        }

        return $disallow;
    }

    private function client(array $settings): Client
    {
        return $this->client ??= new Client([
            'timeout' => (int) ($settings['timeout'] ?? 20),
            'connect_timeout' => 10,
            'headers' => [
                'User-Agent' => $settings['user_agent'] ?? 'Mozilla/5.0 (compatible; UfcPredictBot/1.0)',
                'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
            ],
            'http_errors' => true,
            'allow_redirects' => ['max' => 5],
        ]);
    }

    /** @return array<string, mixed> */
    private function sourceSettings(string $source): array
    {
        return array_merge(
            $this->config['ufc'] ?? [],
            $this->config[$source] ?? []
        );
    }

    private function log(
        string $source,
        string $url,
        string $hash,
        string $status,
        ?int $httpStatus = null,
        ?string $body = null,
        ?string $error = null,
        float $ttlHours = 24,
        ?int $durationMs = null
    ): void {
        ScrapeLog::create([
            'source' => $source,
            'url' => mb_substr($url, 0, 1000),
            'url_hash' => $hash,
            'content_hash' => $body ? sha1($body) : null,
            'http_status' => $httpStatus,
            'status' => $status,
            'response_bytes' => $body ? strlen($body) : null,
            'duration_ms' => $durationMs,
            'error' => $error,
            'fetched_at' => now(),
            'expires_at' => $ttlHours > 0 ? now()->addMinutes((int) round($ttlHours * 60)) : null,
            'body' => $body,
        ]);
    }
}
