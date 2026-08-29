<?php

namespace App\Services\Betting;

use App\Services\Prediction\Math;

/**
 * Поиск value-ставок и расчёт суммы по модифицированному критерию Келли
 * (разделы 5.3–5.5 ТЗ).
 *
 * Класс чистый: никаких обращений к БД и фасадам Laravel, только числа.
 */
class BankrollCalculator
{
    /** @param array<string, mixed> $config config('ufc.bankroll') */
    public function __construct(private readonly array $config) {}

    /** EV = P * K - 1. */
    public function expectedValue(float $probability, float $odds): float
    {
        return $probability * $odds - 1;
    }

    /** Доля банка по Келли: f = (P * K - 1) / (K - 1). */
    public function rawKelly(float $probability, float $odds): float
    {
        if ($odds <= 1.0) {
            return 0.0;
        }

        return ($probability * $odds - 1) / ($odds - 1);
    }

    /**
     * Оценка одной котировки. Возвращает null, если ставка не проходит
     * хотя бы по одному из фильтров.
     */
    public function evaluate(OddsQuote $quote, float $modelProbability, float $bankroll): ?BetRecommendation
    {
        $odds = $quote->price;
        $probability = Math::clamp($modelProbability, 0.0, 1.0);

        // Фильтр по диапазону коэффициентов
        if ($odds < (float) $this->config['min_odds'] || $odds > (float) $this->config['max_odds']) {
            return null;
        }

        // Фильтр по value
        $ev = $this->expectedValue($probability, $odds);
        if ($ev <= (float) $this->config['min_ev']) {
            return null;
        }

        $rawKelly = $this->rawKelly($probability, $odds);
        if ($rawKelly <= 0) {
            return null;
        }

        $fraction = $rawKelly * (float) $this->config['kelly_fraction'];

        // Коэффициент уверенности
        $multipliers = $this->config['confidence_multipliers'];
        if ($probability > (float) $multipliers['high']['above']) {
            $fraction *= (float) $multipliers['high']['factor'];
        } elseif ($probability < (float) $multipliers['low']['below']) {
            $fraction *= (float) $multipliers['low']['factor'];
        }

        // Потолок доли банка
        $maxFraction = $probability > (float) $this->config['high_confidence_probability']
            ? (float) $this->config['max_stake_fraction_high_conf']
            : (float) $this->config['max_stake_fraction'];

        $fraction = min($fraction, $maxFraction);

        // Пол доли банка
        if ($fraction < (float) $this->config['min_stake_fraction']) {
            return null;
        }

        $stake = round($bankroll * $fraction);

        if ($stake < 1) {
            return null;
        }

        return new BetRecommendation(
            market: $quote->market,
            selection: $quote->selection,
            line: $quote->line,
            bookmaker: $quote->bookmaker,
            odds: $odds,
            modelProbability: $probability,
            impliedProbability: $quote->impliedProbability(),
            expectedValue: $ev,
            kellyFraction: $rawKelly,
            stakeFraction: $fraction,
            stake: (float) $stake,
            fighterId: $quote->fighterId,
            oddId: $quote->oddId,
            reason: $this->reason($quote, $probability, $odds, $ev),
        );
    }

    /**
     * Оценка всех рынков одного боя с общим лимитом на бой.
     *
     * @param  array<int, array{0: OddsQuote, 1: float}>  $quotes  пары «котировка, вероятность по модели»
     * @return array<int, BetRecommendation>  отсортированы по убыванию EV
     */
    public function evaluateFight(array $quotes, float $bankroll): array
    {
        $candidates = [];

        foreach ($quotes as [$quote, $probability]) {
            $recommendation = $this->evaluate($quote, $probability, $bankroll);

            if ($recommendation !== null) {
                $candidates[] = $recommendation;
            }
        }

        // Приоритет — у ставки с наибольшим EV
        usort($candidates, fn (BetRecommendation $a, BetRecommendation $b) => $b->expectedValue <=> $a->expectedValue);

        $cap = (float) $this->config['max_fraction_per_fight'];
        $minFraction = (float) $this->config['min_stake_fraction'];
        $used = 0.0;
        $selected = [];

        foreach ($candidates as $candidate) {
            $remaining = $cap - $used;

            if ($remaining < $minFraction) {
                break;
            }

            $fraction = min($candidate->stakeFraction, $remaining);
            $stake = round($bankroll * $fraction);

            if ($stake < 1) {
                continue;
            }

            $selected[] = new BetRecommendation(
                market: $candidate->market,
                selection: $candidate->selection,
                line: $candidate->line,
                bookmaker: $candidate->bookmaker,
                odds: $candidate->odds,
                modelProbability: $candidate->modelProbability,
                impliedProbability: $candidate->impliedProbability,
                expectedValue: $candidate->expectedValue,
                kellyFraction: $candidate->kellyFraction,
                stakeFraction: $fraction,
                stake: (float) $stake,
                fighterId: $candidate->fighterId,
                oddId: $candidate->oddId,
                reason: $candidate->reason,
            );

            $used += $fraction;
        }

        return $selected;
    }

    /** Короткое пояснение, почему ставка рекомендована. */
    private function reason(OddsQuote $quote, float $probability, float $odds, float $ev): string
    {
        return sprintf(
            '%s: модель даёт %d%%, букмекер закладывает %d%% (коэффициент %.2f). Ожидаемая доходность %+.1f%%.',
            $quote->label(),
            (int) round($probability * 100),
            (int) round($quote->impliedProbability() * 100),
            $odds,
            $ev * 100
        );
    }
}
