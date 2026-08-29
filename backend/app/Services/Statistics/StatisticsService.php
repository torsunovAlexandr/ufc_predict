<?php

namespace App\Services\Statistics;

use App\Models\BankrollEntry;
use App\Models\Bet;
use App\Models\Fight;
use App\Services\Betting\BankrollService;
use App\Services\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Метрики виртуального заработка (раздел 6 ТЗ).
 */
class StatisticsService
{
    public function __construct(
        private readonly BankrollService $bankroll,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Сводные показатели по ставкам.
     *
     * @param  array{from?: string, to?: string, event_id?: int, fighter_id?: int}  $filters
     */
    public function summary(array $filters = []): array
    {
        $settled = $this->query($filters)->settled()->get();
        $placed = $this->query($filters)->whereIn('status', ['placed', 'won', 'lost', 'void'])->get();

        $staked = (float) $settled->sum('stake');
        $profit = (float) $settled->sum('profit');
        $wins = $settled->where('status', 'won')->count();
        $losses = $settled->where('status', 'lost')->count();
        $voids = $settled->where('status', 'void')->count();
        $decided = $wins + $losses;

        $startingBankroll = (float) $this->settings->get('starting_bankroll', 10000);
        $current = $this->bankroll->current();

        return [
            'bankroll' => round($current, 2),
            'starting_bankroll' => round($startingBankroll, 2),
            'profit' => round($profit, 2),
            'profit_percent' => $startingBankroll > 0 ? round($profit / $startingBankroll * 100, 2) : 0.0,
            'roi' => $staked > 0 ? round($profit / $staked * 100, 2) : 0.0,
            'total_staked' => round($staked, 2),
            'bets_total' => $placed->count(),
            'bets_settled' => $settled->count(),
            'bets_pending' => $placed->where('status', 'placed')->count(),
            'wins' => $wins,
            'losses' => $losses,
            'voids' => $voids,
            'win_rate' => $decided > 0 ? round($wins / $decided * 100, 2) : 0.0,
            'average_stake' => $settled->count() > 0 ? round($staked / $settled->count(), 2) : 0.0,
            'average_odds' => $settled->count() > 0 ? round((float) $settled->avg('odds'), 3) : 0.0,
            'average_ev' => $settled->count() > 0 ? round((float) $settled->avg('expected_value'), 4) : 0.0,
            'best_bet' => $this->betSummary($settled->sortByDesc('profit')->first()),
            'worst_bet' => $this->betSummary($settled->sortBy('profit')->first()),
            'streaks' => $this->streaks($settled),
        ];
    }

    /** Текущая и максимальная серии выигрышей/проигрышей. */
    private function streaks(iterable $settled): array
    {
        $ordered = collect($settled)
            ->filter(fn (Bet $bet) => in_array($bet->status, ['won', 'lost'], true))
            ->sortBy('settled_at')
            ->values();

        $current = 0;
        $currentType = null;
        $bestWin = 0;
        $bestLoss = 0;

        foreach ($ordered as $bet) {
            if ($bet->status === $currentType) {
                $current++;
            } else {
                $current = 1;
                $currentType = $bet->status;
            }

            if ($currentType === 'won') {
                $bestWin = max($bestWin, $current);
            } else {
                $bestLoss = max($bestLoss, $current);
            }
        }

        return [
            'current' => $current,
            'current_type' => $currentType,
            'longest_win' => $bestWin,
            'longest_loss' => $bestLoss,
        ];
    }

    private function betSummary(?Bet $bet): ?array
    {
        if (! $bet) {
            return null;
        }

        $bet->loadMissing('fight.fighter1', 'fight.fighter2');

        return [
            'id' => $bet->id,
            'fight' => $bet->fight?->title(),
            'selection' => $bet->selection,
            'odds' => $bet->odds,
            'stake' => $bet->stake,
            'profit' => $bet->profit,
        ];
    }

    /**
     * График изменения банкролла.
     *
     * @return array<int, array{date: string, balance: float}>
     */
    public function bankrollChart(?string $from = null, ?string $to = null): array
    {
        $query = BankrollEntry::query()->orderBy('occurred_at')->orderBy('id');

        if ($from) {
            $query->where('occurred_at', '>=', Carbon::parse($from));
        }

        if ($to) {
            $query->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        return $query->get()->map(fn (BankrollEntry $entry) => [
            'date' => $entry->occurred_at->toIso8601String(),
            'balance' => round((float) $entry->balance_after, 2),
            'type' => $entry->type,
            'amount' => round((float) $entry->amount, 2),
            'description' => $entry->description,
        ])->all();
    }

    /**
     * Сравнение с простыми стратегиями (раздел 6.3 ТЗ).
     *
     * Обе стратегии считаются «на бумаге» по тем же завершённым боям,
     * по которым есть котировки: плоская ставка 2% стартового банка.
     */
    public function benchmarks(): array
    {
        $flatStake = round((float) $this->settings->get('starting_bankroll', 10000) * 0.02);

        $fights = Fight::query()
            ->where('status', 'completed')
            ->whereHas('result')
            ->with(['result', 'odds' => fn ($q) => $q->where('market', 'moneyline')->where('is_latest', true)])
            ->get();

        $favourite = ['name' => 'Ставка на фаворита по коэффициентам', 'bets' => 0, 'wins' => 0, 'staked' => 0.0, 'profit' => 0.0];
        $random = ['name' => 'Случайный выбор победителя', 'bets' => 0, 'wins' => 0, 'staked' => 0.0, 'profit' => 0.0];

        foreach ($fights as $fight) {
            $prices = [];
            foreach ($fight->odds as $odd) {
                if (in_array($odd->selection, ['fighter1', 'fighter2'], true)) {
                    $prices[$odd->selection] = min($prices[$odd->selection] ?? INF, (float) $odd->price);
                }
            }

            if (count($prices) < 2) {
                continue;
            }

            $result = $fight->result;

            if ($result->is_no_contest || $result->is_draw || ! $result->winner_id) {
                continue;
            }

            $winnerSelection = $result->winner_id === $fight->fighter1_id ? 'fighter1' : 'fighter2';

            // Фаворит — меньший коэффициент
            $favouriteSelection = $prices['fighter1'] <= $prices['fighter2'] ? 'fighter1' : 'fighter2';
            $this->applyBenchmark($favourite, $favouriteSelection, $winnerSelection, $prices, $flatStake);

            // Псевдослучайный, но воспроизводимый выбор — зависит только от id боя
            $randomSelection = (crc32('fight-'.$fight->id) % 2 === 0) ? 'fighter1' : 'fighter2';
            $this->applyBenchmark($random, $randomSelection, $winnerSelection, $prices, $flatStake);
        }

        $system = $this->summary();

        return [
            'flat_stake' => $flatStake,
            'strategies' => [
                [
                    'name' => 'Модель (фактические ставки)',
                    'key' => 'model',
                    'bets' => $system['bets_settled'],
                    'wins' => $system['wins'],
                    'win_rate' => $system['win_rate'],
                    'staked' => $system['total_staked'],
                    'profit' => $system['profit'],
                    'roi' => $system['roi'],
                ],
                $this->finaliseBenchmark($favourite, 'favourite'),
                $this->finaliseBenchmark($random, 'random'),
            ],
        ];
    }

    /** @param array<string, mixed> $strategy */
    private function applyBenchmark(array &$strategy, string $selection, string $winner, array $prices, float $stake): void
    {
        $strategy['bets']++;
        $strategy['staked'] += $stake;

        if ($selection === $winner) {
            $strategy['wins']++;
            $strategy['profit'] += $stake * ($prices[$selection] - 1);
        } else {
            $strategy['profit'] -= $stake;
        }
    }

    /** @param array<string, mixed> $strategy */
    private function finaliseBenchmark(array $strategy, string $key): array
    {
        return [
            'name' => $strategy['name'],
            'key' => $key,
            'bets' => $strategy['bets'],
            'wins' => $strategy['wins'],
            'win_rate' => $strategy['bets'] > 0 ? round($strategy['wins'] / $strategy['bets'] * 100, 2) : 0.0,
            'staked' => round($strategy['staked'], 2),
            'profit' => round($strategy['profit'], 2),
            'roi' => $strategy['staked'] > 0 ? round($strategy['profit'] / $strategy['staked'] * 100, 2) : 0.0,
        ];
    }

    /**
     * Точность прогнозов: как часто фаворит модели действительно побеждал,
     * и калибровка по интервалам вероятности.
     */
    public function accuracy(): array
    {
        $fights = Fight::query()
            ->where('status', 'completed')
            ->whereHas('result')
            ->whereHas('predictions', fn ($q) => $q->where('is_current', true))
            ->with(['result', 'currentPrediction'])
            ->get();

        $correct = 0;
        $total = 0;
        $buckets = [];

        foreach ($fights as $fight) {
            $prediction = $fight->currentPrediction;
            $result = $fight->result;

            if (! $prediction || ! $result || $result->is_no_contest || $result->is_draw || ! $result->winner_id) {
                continue;
            }

            $total++;

            $predictedWinner = $prediction->probability_fighter1 >= 0.5 ? $fight->fighter1_id : $fight->fighter2_id;
            $probability = max($prediction->probability_fighter1, $prediction->probability_fighter2);
            $hit = $predictedWinner === $result->winner_id;

            if ($hit) {
                $correct++;
            }

            $bucket = min(9, (int) floor($probability * 10));
            $buckets[$bucket] ??= ['from' => $bucket * 10, 'to' => $bucket * 10 + 10, 'count' => 0, 'hits' => 0];
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['hits'] += $hit ? 1 : 0;
        }

        ksort($buckets);

        return [
            'fights' => $total,
            'correct' => $correct,
            'accuracy' => $total > 0 ? round($correct / $total * 100, 2) : 0.0,
            'calibration' => array_values(array_map(function (array $bucket) {
                $bucket['actual'] = $bucket['count'] > 0 ? round($bucket['hits'] / $bucket['count'] * 100, 1) : 0.0;

                return $bucket;
            }, $buckets)),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters): Builder
    {
        $query = Bet::query()->real();

        if (! empty($filters['from'])) {
            $query->where('placed_at', '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->where('placed_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['event_id'])) {
            $query->whereHas('fight', fn ($q) => $q->where('event_id', $filters['event_id']));
        }

        if (! empty($filters['fighter_id'])) {
            $query->whereHas('fight', function ($q) use ($filters) {
                $q->where('fighter1_id', $filters['fighter_id'])->orWhere('fighter2_id', $filters['fighter_id']);
            });
        }

        return $query;
    }
}
