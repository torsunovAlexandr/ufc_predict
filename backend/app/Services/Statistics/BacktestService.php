<?php

namespace App\Services\Statistics;

use App\Models\Bet;
use App\Models\Fight;
use App\Services\Betting\BankrollCalculator;
use App\Services\Betting\OddsQuote;
use App\Services\Prediction\PredictionService;
use Illuminate\Support\Carbon;

/**
 * Бэктестинг на исторических данных (раздел 9 ТЗ).
 *
 * Для каждого прошедшего боя прогноз пересчитывается «на дату боя» —
 * профили бойцов собираются только из боёв, состоявшихся ДО него,
 * чтобы не было подглядывания в будущее. Затем по сохранённым котировкам
 * моделируются ставки и считается итоговая доходность.
 */
class BacktestService
{
    public function __construct(
        private readonly PredictionService $predictions,
        private readonly BankrollCalculator $calculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $from = null, ?string $to = null, float $startingBankroll = 10000, bool $store = false): array
    {
        $from = $from ? Carbon::parse($from) : now()->subYears(2);
        $to = $to ? Carbon::parse($to) : now();

        // Бои перебираются строго в хронологическом порядке, поэтому нужен join
        // с events. Колонка `status` есть и в fights, и в events — квалифицируем
        // все условия, иначе MySQL не сможет их различить.
        $fights = Fight::query()
            ->select('fights.*')
            ->join('events', 'events.id', '=', 'fights.event_id')
            ->where('fights.status', 'completed')
            ->whereBetween('events.starts_at', [$from, $to])
            ->whereHas('result')
            ->with(['result', 'fighter1', 'fighter2', 'event', 'odds' => fn ($q) => $q->where('market', 'moneyline')])
            ->orderBy('events.starts_at')
            ->get();

        $bankroll = $startingBankroll;
        $peak = $startingBankroll;
        $maxDrawdown = 0.0;

        $staked = 0.0;
        $profit = 0.0;
        $wins = 0;
        $placed = 0;
        $correct = 0;
        $predicted = 0;

        $curve = [];
        $log = [];

        foreach ($fights as $fight) {
            $asOf = $fight->event?->starts_at ?? now();
            $prediction = $this->predictions->predict($fight, $asOf);
            $result = $fight->result;

            if ($result->is_no_contest) {
                continue;
            }

            // Точность прогноза
            if ($result->winner_id && ! $result->is_draw) {
                $predicted++;
                $predictedWinner = $prediction->probabilityFighter1 >= 0.5 ? $fight->fighter1_id : $fight->fighter2_id;

                if ($predictedWinner === $result->winner_id) {
                    $correct++;
                }
            }

            // Ставки по сохранённым котировкам
            $quotes = $this->quotesFor($fight, $prediction->probabilityFighter1, $prediction->probabilityFighter2);

            if ($quotes === []) {
                continue;
            }

            foreach ($this->calculator->evaluateFight($quotes, $bankroll) as $recommendation) {
                $placed++;
                $staked += $recommendation->stake;
                $bankroll -= $recommendation->stake;

                $won = $this->isWinner($recommendation->selection, $fight, $result);
                $payout = 0.0;

                if ($won === null) {
                    $payout = $recommendation->stake; // возврат
                } elseif ($won) {
                    $payout = $recommendation->stake * $recommendation->odds;
                    $wins++;
                }

                $bankroll += $payout;
                $profit += $payout - $recommendation->stake;

                $log[] = [
                    'date' => $asOf->toDateString(),
                    'fight' => $fight->title(),
                    'selection' => $recommendation->selection,
                    'odds' => round($recommendation->odds, 2),
                    'probability' => round($recommendation->modelProbability, 3),
                    'stake' => $recommendation->stake,
                    'result' => $won === null ? 'возврат' : ($won ? 'выигрыш' : 'проигрыш'),
                    'bankroll' => round($bankroll, 2),
                ];

                if ($store) {
                    $this->storeBenchmarkBet($fight, $recommendation, $won);
                }
            }

            $peak = max($peak, $bankroll);
            $maxDrawdown = max($maxDrawdown, $peak > 0 ? ($peak - $bankroll) / $peak : 0);

            $curve[] = ['date' => $asOf->toDateString(), 'balance' => round($bankroll, 2)];
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'fights_analysed' => $fights->count(),
            'prediction_accuracy' => $predicted > 0 ? round($correct / $predicted * 100, 2) : 0.0,
            'predictions_checked' => $predicted,
            'bets' => $placed,
            'wins' => $wins,
            'win_rate' => $placed > 0 ? round($wins / $placed * 100, 2) : 0.0,
            'staked' => round($staked, 2),
            'profit' => round($profit, 2),
            'roi' => $staked > 0 ? round($profit / $staked * 100, 2) : 0.0,
            'starting_bankroll' => $startingBankroll,
            'final_bankroll' => round($bankroll, 2),
            'max_drawdown' => round($maxDrawdown * 100, 2),
            'curve' => $curve,
            'log' => array_slice($log, -200),
        ];
    }

    /**
     * Исторические котировки на победу. Берётся лучший коэффициент
     * из сохранённых для каждого бойца.
     *
     * @return array<int, array{0: OddsQuote, 1: float}>
     */
    private function quotesFor(Fight $fight, float $p1, float $p2): array
    {
        $best = [];

        foreach ($fight->odds as $odd) {
            if (! in_array($odd->selection, ['fighter1', 'fighter2'], true)) {
                continue;
            }

            if (! isset($best[$odd->selection]) || $odd->price > $best[$odd->selection]->price) {
                $best[$odd->selection] = $odd;
            }
        }

        $quotes = [];

        foreach ($best as $selection => $odd) {
            $quotes[] = [
                new OddsQuote(
                    market: 'moneyline',
                    selection: $selection,
                    price: (float) $odd->price,
                    bookmaker: $odd->bookmaker,
                ),
                $selection === 'fighter1' ? $p1 : $p2,
            ];
        }

        return $quotes;
    }

    /** true — выигрыш, false — проигрыш, null — возврат. */
    private function isWinner(string $selection, Fight $fight, $result): ?bool
    {
        if ($result->is_draw || ! $result->winner_id) {
            return null;
        }

        $backed = $selection === 'fighter1' ? $fight->fighter1_id : $fight->fighter2_id;

        return $result->winner_id === $backed;
    }

    private function storeBenchmarkBet(Fight $fight, $recommendation, ?bool $won): void
    {
        Bet::create([
            'fight_id' => $fight->id,
            'fighter_id' => $recommendation->fighterId,
            'market' => $recommendation->market,
            'selection' => $recommendation->selection,
            'line' => $recommendation->line,
            'bookmaker' => $recommendation->bookmaker,
            'odds' => $recommendation->odds,
            'model_probability' => $recommendation->modelProbability,
            'implied_probability' => $recommendation->impliedProbability,
            'expected_value' => $recommendation->expectedValue,
            'kelly_fraction' => $recommendation->kellyFraction,
            'stake_fraction' => $recommendation->stakeFraction,
            'stake' => $recommendation->stake,
            'status' => $won === null ? 'void' : ($won ? 'won' : 'lost'),
            'payout' => $won ? $recommendation->stake * $recommendation->odds : ($won === null ? $recommendation->stake : 0),
            'profit' => $won ? $recommendation->stake * ($recommendation->odds - 1) : ($won === null ? 0 : -$recommendation->stake),
            'is_benchmark' => true,
            'benchmark_strategy' => 'backtest',
            'reason' => $recommendation->reason,
            'settled_at' => now(),
        ]);
    }
}
