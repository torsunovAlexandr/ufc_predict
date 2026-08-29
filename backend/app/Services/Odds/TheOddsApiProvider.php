<?php

namespace App\Services\Odds;

use App\Models\Event;
use App\Services\Scraping\HttpFetcher;
use Illuminate\Support\Facades\Log;

/**
 * Поставщик коэффициентов через The Odds API (https://the-odds-api.com).
 *
 * Бесплатный тариф — ограниченное число запросов, поэтому:
 *  - за один запрос забираются котировки сразу по всем боям вида спорта;
 *  - ответ кэшируется на 60 минут;
 *  - счётчик запросов за сутки сверяется с лимитом из конфигурации.
 */
class TheOddsApiProvider implements OddsProvider
{
    public function __construct(private readonly HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'the_odds_api';
    }

    public function isAvailable(): bool
    {
        if (! config('services.odds_api.key')) {
            return false;
        }

        return $this->fetcher->requestsToday($this->name()) < (int) config('services.odds_api.daily_limit');
    }

    public function fetchForEvent(Event $event): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $base = rtrim((string) config('services.odds_api.base_url'), '/');
        $sport = config('services.odds_api.sport');
        
        $response = $this->fetcher->fetchJson(
            $this->name(),
            "{$base}/sports/{$sport}/odds",
            [
                'apiKey' => config('services.odds_api.key'),
                'regions' => config('services.odds_api.regions'),
                'markets' => 'h2h,totals',
                'oddsFormat' => 'decimal',
                'dateFormat' => 'iso',
            ],
            ttlMinutes: 60
        );

        if (! is_array($response)) {
            return [];
        }

        if (isset($response['message'])) {
            Log::channel('scraping')->error('The Odds API вернул ошибку: '.$response['message']);

            return [];
        }

        $quotes = [];

        foreach ($response as $game) {
            $home = $game['home_team'] ?? null;
            $away = $game['away_team'] ?? null;

            if (! $home || ! $away) {
                continue;
            }

            foreach ($game['bookmakers'] ?? [] as $bookmaker) {
                foreach ($bookmaker['markets'] ?? [] as $market) {
                    foreach ($market['outcomes'] ?? [] as $outcome) {
                        $quote = $this->mapOutcome($market['key'] ?? '', $outcome, $home, $away);

                        if (! $quote || $quote['selection'] === '') {
                            continue;
                        }

                        $quotes[] = array_merge($quote, [
                            'fighter1' => $home,
                            'fighter2' => $away,
                            'commence_time' => $game['commence_time'] ?? null,
                            'bookmaker' => $bookmaker['key'] ?? 'unknown',
                        ]);
                    }
                }
            }
        }

        return $quotes;
    }

    /**
     * @param  array<string, mixed>  $outcome
     * @return array{market: string, selection: string, line: float|null, price: float}|null
     */
    private function mapOutcome(string $marketKey, array $outcome, string $home, string $away): ?array
    {
        $price = (float) ($outcome['price'] ?? 0);

        if ($price <= 1) {
            return null;
        }

        $name = (string) ($outcome['name'] ?? '');

        return match ($marketKey) {
            'h2h' => [
                'market' => 'moneyline',
                'selection' => match (true) {
                    $name === $home => 'fighter1',
                    $name === $away => 'fighter2',
                    mb_strtolower($name) === 'draw' => 'draw',
                    default => '',
                },
                'line' => null,
                'price' => $price,
            ],
            'totals' => [
                'market' => 'totals',
                'selection' => mb_strtolower($name) === 'over' ? 'over' : 'under',
                'line' => isset($outcome['point']) ? (float) $outcome['point'] : null,
                'price' => $price,
            ],
            default => null,
        };
    }
}
