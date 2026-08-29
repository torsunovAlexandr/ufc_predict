<?php

namespace App\Services\Betting;

use App\Models\Bet;
use App\Models\Fight;
use App\Models\Odd;
use App\Models\Prediction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Связывает прогноз модели с котировками букмекеров и превращает их
 * в конкретные рекомендации по ставкам.
 */
class BettingService
{
    public function __construct(
        private readonly BankrollCalculator $calculator,
        private readonly BankrollService $bankroll,
    ) {}

    /**
     * Сформировать рекомендации по бою и сохранить их как ставки
     * со статусом `recommended`.
     *
     * @return array<int, Bet>
     */
    public function buildRecommendations(Fight $fight, ?Prediction $prediction = null): array
    {
        $prediction = $prediction ?? $fight->currentPrediction;

        if (! $prediction) {
            return [];
        }

        $quotes = $this->quotesFor($fight, $prediction);

        if ($quotes === []) {
            return [];
        }

        $bankroll = $this->bankroll->current();
        $recommendations = $this->calculator->evaluateFight($quotes, $bankroll);

        return DB::transaction(function () use ($fight, $prediction, $recommendations) {
            // Старые нереализованные рекомендации по этому бою заменяем новыми
            Bet::where('fight_id', $fight->id)
                ->where('status', 'recommended')
                ->delete();

            $bets = [];

            foreach ($recommendations as $recommendation) {
                $bets[] = Bet::create([
                    'prediction_id' => $prediction->id,
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
                    'status' => 'recommended',
                    'reason' => $recommendation->reason,
                ]);
            }

            // Сводка лучшей рекомендации — в прогноз
            $best = $recommendations[0] ?? null;
            $prediction->update([
                'recommended_selection' => $best?->selection,
                'recommended_odds' => $best?->odds,
                'recommended_stake' => $best?->stake,
                'recommended_ev' => $best?->expectedValue,
            ]);

            return $bets;
        });
    }

    /**
     * Сопоставление рынков букмекера с вероятностями модели.
     *
     * @return array<int, array{0: OddsQuote, 1: float}>
     */
    public function quotesFor(Fight $fight, Prediction $prediction): array
    {
        /** @var Collection<int, Odd> $odds */
        $odds = $fight->latestOdds()->get();

        if ($odds->isEmpty()) {
            return [];
        }

        // Для каждого рынка берём лучший (максимальный) коэффициент среди букмекеров
        $best = [];
        foreach ($odds as $odd) {
            $key = $odd->marketKey();

            if (! isset($best[$key]) || $odd->price > $best[$key]->price) {
                $best[$key] = $odd;
            }
        }

        $quotes = [];

        foreach ($best as $odd) {
            $probability = $this->probabilityForMarket($odd, $prediction);

            if ($probability === null) {
                continue;
            }

            $quotes[] = [
                new OddsQuote(
                    market: $odd->market,
                    selection: $odd->selection,
                    price: (float) $odd->price,
                    bookmaker: $odd->bookmaker,
                    line: $odd->line,
                    fighterId: $odd->fighter_id,
                    oddId: $odd->id,
                ),
                $probability,
            ];
        }

        return $quotes;
    }

    /** Вероятность конкретного исхода по мнению модели. */
    private function probabilityForMarket(Odd $odd, Prediction $prediction): ?float
    {
        $methods = $prediction->method_probabilities['markets'] ?? [];

        return match (true) {
            $odd->market === 'moneyline' && $odd->selection === 'fighter1' => $prediction->probability_fighter1,
            $odd->market === 'moneyline' && $odd->selection === 'fighter2' => $prediction->probability_fighter2,
            $odd->market === 'draw' => $prediction->probability_draw > 0 ? $prediction->probability_draw : null,
            $odd->market === 'totals' && $odd->selection === 'over' && (float) $odd->line === 2.5 => $prediction->probability_over_2_5,
            $odd->market === 'totals' && $odd->selection === 'under' && (float) $odd->line === 2.5 => $prediction->probability_under_2_5,
            $odd->market === 'method' => isset($methods[$odd->selection]) ? (float) $methods[$odd->selection] : null,
            default => null,
        };
    }

    /**
     * Подтвердить рекомендации и «разместить» ставки — списать сумму с банка.
     *
     * @param  array<int, int>  $betIds
     * @return array<int, Bet>
     */
    public function placeBets(array $betIds): array
    {
        $bets = Bet::whereIn('id', $betIds)->where('status', 'recommended')->get();
        $placed = [];

        foreach ($bets as $bet) {
            $placed[] = $this->bankroll->placeBet($bet);
        }

        return $placed;
    }

    /** Разместить все рекомендации по бою. */
    public function placeAllForFight(Fight $fight): array
    {
        $ids = Bet::where('fight_id', $fight->id)->where('status', 'recommended')->pluck('id')->all();

        return $this->placeBets($ids);
    }
}
