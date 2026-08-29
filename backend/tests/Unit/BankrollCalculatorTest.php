<?php

namespace Tests\Unit;

use App\Services\Betting\BankrollCalculator;
use App\Services\Betting\OddsQuote;
use PHPUnit\Framework\TestCase;

class BankrollCalculatorTest extends TestCase
{
    private function calculator(array $overrides = []): BankrollCalculator
    {
        return new BankrollCalculator(array_merge([
            'starting' => 10000.0,
            'min_ev' => 0.05,
            'kelly_fraction' => 1.0,
            'max_stake_fraction' => 0.05,
            'max_stake_fraction_high_conf' => 0.10,
            'high_confidence_probability' => 0.80,
            'min_stake_fraction' => 0.005,
            'max_fraction_per_fight' => 0.10,
            'confidence_multipliers' => [
                'high' => ['above' => 0.65, 'factor' => 1.2],
                'low' => ['below' => 0.55, 'factor' => 0.8],
            ],
            'min_odds' => 1.10,
            'max_odds' => 15.0,
        ], $overrides));
    }

    public function test_expected_value_formula(): void
    {
        $this->assertEqualsWithDelta(0.2, $this->calculator()->expectedValue(0.6, 2.0), 1e-9);
        $this->assertEqualsWithDelta(-0.2, $this->calculator()->expectedValue(0.4, 2.0), 1e-9);
    }

    public function test_kelly_formula(): void
    {
        // f = (P*K - 1) / (K - 1) = (0.6*2 - 1) / 1 = 0.2
        $this->assertEqualsWithDelta(0.2, $this->calculator()->rawKelly(0.6, 2.0), 1e-9);
    }

    public function test_kelly_is_zero_for_impossible_odds(): void
    {
        $this->assertSame(0.0, $this->calculator()->rawKelly(0.9, 1.0));
    }

    public function test_bet_without_value_is_rejected(): void
    {
        $quote = new OddsQuote('moneyline', 'fighter1', 1.40);

        $this->assertNull($this->calculator()->evaluate($quote, 0.62, 10000));
    }

    public function test_stake_is_capped_at_five_percent(): void
    {
        $quote = new OddsQuote('moneyline', 'fighter1', 2.20);
        $recommendation = $this->calculator()->evaluate($quote, 0.62, 10000);

        $this->assertNotNull($recommendation);
        $this->assertSame(500.0, $recommendation->stake);
    }

    public function test_high_confidence_allows_ten_percent(): void
    {
        $quote = new OddsQuote('moneyline', 'fighter1', 1.60);
        $recommendation = $this->calculator()->evaluate($quote, 0.85, 10000);

        $this->assertNotNull($recommendation);
        $this->assertSame(1000.0, $recommendation->stake);
    }

    public function test_low_confidence_reduces_stake(): void
    {
        $quote = new OddsQuote('moneyline', 'fighter1', 4.00);

        $low = $this->calculator()->evaluate($quote, 0.30, 10000);
        $this->assertNotNull($low);

        // f = (0.3*4 - 1)/3 = 0.0667; множитель 0.8 -> 0.0533; потолок 0.05
        $this->assertSame(500.0, $low->stake);
    }

    public function test_odds_outside_range_are_rejected(): void
    {
        $this->assertNull($this->calculator()->evaluate(new OddsQuote('moneyline', 'fighter1', 1.05), 0.99, 10000));
        $this->assertNull($this->calculator()->evaluate(new OddsQuote('moneyline', 'fighter1', 40.0), 0.10, 10000));
    }

    public function test_total_stake_per_fight_is_capped(): void
    {
        $quotes = [
            [new OddsQuote('moneyline', 'fighter1', 2.20), 0.62],
            [new OddsQuote('method', 'ko_tko', 3.10), 0.42],
            [new OddsQuote('totals', 'under', 2.10, null, 2.5), 0.60],
        ];

        $portfolio = $this->calculator()->evaluateFight($quotes, 10000);
        $total = array_sum(array_map(fn ($r) => $r->stake, $portfolio));

        $this->assertLessThanOrEqual(1000.0, $total);
        $this->assertNotEmpty($portfolio);
    }

    public function test_recommendations_are_sorted_by_expected_value(): void
    {
        $quotes = [
            [new OddsQuote('totals', 'under', 2.10, null, 2.5), 0.60],
            [new OddsQuote('moneyline', 'fighter1', 2.20), 0.62],
        ];

        $portfolio = $this->calculator()->evaluateFight($quotes, 10000);

        $this->assertGreaterThanOrEqual(
            $portfolio[1]->expectedValue ?? 0,
            $portfolio[0]->expectedValue
        );
    }

    public function test_half_kelly_halves_the_stake(): void
    {
        $quote = new OddsQuote('moneyline', 'fighter1', 2.20);

        $full = $this->calculator(['max_stake_fraction' => 1.0])->evaluate($quote, 0.62, 10000);
        $half = $this->calculator(['max_stake_fraction' => 1.0, 'kelly_fraction' => 0.5])->evaluate($quote, 0.62, 10000);

        $this->assertEqualsWithDelta($full->stake / 2, $half->stake, 1.0);
    }
}
