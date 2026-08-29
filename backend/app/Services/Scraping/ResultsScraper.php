<?php

namespace App\Services\Scraping;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Result;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Получение фактических результатов боёв (раздел 2.3 ТЗ).
 *
 * Порядок попыток:
 *   1. страница турнира на ufc.com;
 *   2. Sherdog (если включён);
 *   3. поиск через Google Custom Search API;
 *   4. ручной ввод пользователем.
 *
 * Частота запросов ограничена 5 в минуту — соблюдается через HttpFetcher.
 */
class ResultsScraper
{
    public function __construct(private readonly HttpFetcher $fetcher) {}

    /**
     * Получить и сохранить результаты всех боёв турнира.
     *
     * @return array{found: int, missing: array<int, string>, source: string|null}
     */
    public function syncEventResults(Event $event, bool $force = true): array
    {
        $event->loadMissing('fights.fighter1', 'fights.fighter2');

        $parsed = $this->fromUfcEventPage($event, $force);
        $source = $parsed ? 'ufc.com' : null;

        if (! $parsed && config('ufc.sources.sherdog.enabled')) {
            $parsed = $this->fromSherdog($event, $force);
            $source = $parsed ? 'sherdog.com' : $source;
        }

        $found = 0;
        $missing = [];

        foreach ($event->fights as $fight) {
            $match = $parsed ? $this->matchFight($fight, $parsed) : null;

            if (! $match) {
                $missing[] = $fight->title();

                continue;
            }

            $this->storeResult($fight, $match, $source ?? 'unknown');
            $found++;
        }

        if ($found > 0 && $missing === []) {
            $event->update(['status' => 'completed']);
        }

        return ['found' => $found, 'missing' => $missing, 'source' => $source];
    }

    /**
     * Разбор страницы турнира на ufc.com — там у завершённых боёв
     * проставлены победитель, метод, раунд и время.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fromUfcEventPage(Event $event, bool $force): ?array
    {
        if (! $event->ufc_url) {
            return null;
        }

        // Для результатов кэш короткий: страница обновляется по ходу турнира
        $html = $this->fetcher->fetch('results', $event->ufc_url, 1, $force);

        if (! $html) {
            return null;
        }

        $crawler = new Crawler($html, config('ufc.sources.ufc.base_url'));
        $results = [];

        try {
            $crawler->filter('.c-listing-fight')->each(function (Crawler $node) use (&$results) {
                $names = Dom::texts($node, '.c-listing-fight__corner-name');

                if (count($names) < 2) {
                    $given = Dom::texts($node, '.c-listing-fight__corner-given-name');
                    $family = Dom::texts($node, '.c-listing-fight__corner-family-name');

                    if (count($given) >= 2 && count($family) >= 2) {
                        $names = [trim($given[0].' '.$family[0]), trim($given[1].' '.$family[1])];
                    }
                }

                if (count($names) < 2) {
                    return;
                }

                $outcomes = array_map('mb_strtolower', Dom::texts($node, '.c-listing-fight__outcome-wrapper'));

                if ($outcomes === []) {
                    $outcomes = array_map('mb_strtolower', Dom::texts($node, '.c-listing-fight__outcome'));
                }

                // Метод, раунд и время лежат в блоке результата
                $labels = array_map('mb_strtolower', Dom::texts($node, '.c-listing-fight__result-label'));
                $values = Dom::texts($node, '.c-listing-fight__result-text');
                $details = array_combine(
                    array_slice($labels, 0, count($values)),
                    array_slice($values, 0, count($labels))
                ) ?: [];

                $winnerIndex = null;
                foreach ($outcomes as $index => $outcome) {
                    if (str_contains($outcome, 'win')) {
                        $winnerIndex = $index;
                        break;
                    }
                }

                $isDraw = (bool) array_filter($outcomes, fn ($o) => str_contains($o, 'draw'));
                $isNc = (bool) array_filter($outcomes, fn ($o) => str_contains($o, 'nc') || str_contains($o, 'no contest'));

                if ($winnerIndex === null && ! $isDraw && ! $isNc) {
                    return; // бой ещё не состоялся
                }

                $results[] = [
                    'fighter1' => Dom::clean($names[0]),
                    'fighter2' => Dom::clean($names[1]),
                    'winner_name' => $winnerIndex !== null ? Dom::clean($names[$winnerIndex]) : null,
                    'is_draw' => $isDraw,
                    'is_no_contest' => $isNc,
                    'method' => Dom::normalizeMethod($details['method'] ?? null),
                    'method_detail' => $details['method'] ?? null,
                    'end_round' => Dom::integer($details['round'] ?? null),
                    'end_time_seconds' => Dom::timeToSeconds($details['time'] ?? null),
                ];
            });
        } catch (\Throwable $e) {
            Log::channel('scraping')->error('Ошибка разбора результатов: '.$e->getMessage());

            return null;
        }

        return $results ?: null;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function fromSherdog(Event $event, bool $force): ?array
    {
        $url = config('ufc.sources.sherdog.base_url').'/events/'.str_replace(' ', '-', $event->slug);
        $html = $this->fetcher->fetch('sherdog', $url, 1, $force);

        if (! $html) {
            return null;
        }

        $crawler = new Crawler($html);
        $results = [];

        try {
            $crawler->filter('.new_table.event tr')->each(function (Crawler $row) use (&$results) {
                $names = Dom::texts($row, '.fighter_result_data a, td .name a');

                if (count($names) < 2) {
                    return;
                }

                $cells = Dom::texts($row, 'td');
                $winners = Dom::texts($row, '.final_result');

                $results[] = [
                    'fighter1' => Dom::clean($names[0]),
                    'fighter2' => Dom::clean($names[1]),
                    'winner_name' => ($winners[0] ?? '') === 'win' ? Dom::clean($names[0]) : Dom::clean($names[1]),
                    'is_draw' => in_array('draw', array_map('mb_strtolower', $winners), true),
                    'is_no_contest' => in_array('nc', array_map('mb_strtolower', $winners), true),
                    'method' => Dom::normalizeMethod($cells[3] ?? null),
                    'method_detail' => $cells[3] ?? null,
                    'end_round' => Dom::integer($cells[4] ?? null),
                    'end_time_seconds' => Dom::timeToSeconds($cells[5] ?? null),
                ];
            });
        } catch (\Throwable) {
            return null;
        }

        return $results ?: null;
    }

    /**
     * Поиск результата конкретного боя через Google Custom Search —
     * запасной вариант, когда страницы турнира разобрать не удалось.
     *
     * @return array<int, array{title: string, link: string, snippet: string}>
     */
    public function searchResult(Fight $fight): array
    {
        $key = config('services.google_cse.key');
        $cx = config('services.google_cse.cx');

        if (! $key || ! $cx) {
            return [];
        }

        if ($this->fetcher->requestsToday('google_cse') >= (int) config('services.google_cse.daily_limit')) {
            Log::channel('scraping')->warning('Дневной лимит Google Custom Search исчерпан.');

            return [];
        }

        $query = sprintf('%s vs %s результат боя UFC', $fight->fighter1?->name, $fight->fighter2?->name);

        $response = $this->fetcher->fetchJson('google_cse', 'https://www.googleapis.com/customsearch/v1', [
            'key' => $key,
            'cx' => $cx,
            'q' => $query,
            'num' => 5,
        ], 60);

        return array_map(fn (array $item) => [
            'title' => $item['title'] ?? '',
            'link' => $item['link'] ?? '',
            'snippet' => $item['snippet'] ?? '',
        ], $response['items'] ?? []);
    }

    /**
     * Сопоставление разобранного результата с боем в БД по именам.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<string, mixed>|null
     */
    private function matchFight(Fight $fight, array $parsed): ?array
    {
        $name1 = $this->normalizeName($fight->fighter1?->name ?? '');
        $name2 = $this->normalizeName($fight->fighter2?->name ?? '');

        foreach ($parsed as $row) {
            $a = $this->normalizeName($row['fighter1']);
            $b = $this->normalizeName($row['fighter2']);

            if (($a === $name1 && $b === $name2) || ($a === $name2 && $b === $name1)) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function storeResult(Fight $fight, array $data, string $source, bool $manual = false): Result
    {
        $winnerId = null;

        if (! empty($data['winner_name'])) {
            $winner = $this->normalizeName($data['winner_name']);

            if ($winner === $this->normalizeName($fight->fighter1?->name ?? '')) {
                $winnerId = $fight->fighter1_id;
            } elseif ($winner === $this->normalizeName($fight->fighter2?->name ?? '')) {
                $winnerId = $fight->fighter2_id;
            }
        }

        $winnerId = $data['winner_id'] ?? $winnerId;

        $endRound = $data['end_round'] ?? null;
        $endTime = $data['end_time_seconds'] ?? null;
        $totalSeconds = $endRound ? ($endRound - 1) * 300 + (int) $endTime : null;

        $result = Result::updateOrCreate(
            ['fight_id' => $fight->id],
            [
                'winner_id' => $winnerId,
                'is_draw' => (bool) ($data['is_draw'] ?? false),
                'is_no_contest' => (bool) ($data['is_no_contest'] ?? false),
                'method' => $data['method'] ?? null,
                'method_detail' => $data['method_detail'] ?? null,
                'end_round' => $endRound,
                'end_time_seconds' => $endTime,
                'total_seconds' => $totalSeconds,
                'source' => $source,
                'source_url' => $data['source_url'] ?? null,
                'entered_manually' => $manual,
            ]
        );

        $fight->update(['status' => 'completed']);

        return $result;
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(Dom::clean($name));
        $name = preg_replace('/[^\p{L}\s]/u', '', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
