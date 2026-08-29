<?php

namespace Tests\Unit;

use App\Services\Prediction\Math;
use PHPUnit\Framework\TestCase;

class MathTest extends TestCase
{
    public function test_advantage_is_zero_for_equal_values(): void
    {
        $this->assertSame(0.0, round(Math::advantage(4.0, 4.0), 6));
    }

    public function test_advantage_is_bounded(): void
    {
        $this->assertLessThanOrEqual(1.0, Math::advantage(100, 0));
        $this->assertGreaterThanOrEqual(-1.0, Math::advantage(0, 100));
    }

    public function test_advantage_returns_zero_when_no_data(): void
    {
        $this->assertSame(0.0, Math::advantage(0, 0));
    }

    public function test_advantage_formula_matches_specification(): void
    {
        // (5 - 3) / (5 + 3 + eps) ≈ 0.25
        $this->assertEqualsWithDelta(0.25, Math::advantage(5, 3), 1e-4);
    }

    public function test_sigmoid_and_logit_are_inverse(): void
    {
        foreach ([-3.0, -0.5, 0.0, 0.7, 2.4] as $x) {
            $this->assertEqualsWithDelta($x, Math::logit(Math::sigmoid($x)), 1e-9);
        }
    }

    public function test_sigmoid_never_returns_nan_or_infinity(): void
    {
        foreach ([-800.0, -709.0, -50.0, 0.0, 50.0, 709.0, 800.0] as $x) {
            $value = Math::sigmoid($x);

            $this->assertFalse(is_nan($value), "sigmoid({$x}) вернул NAN");
            $this->assertFalse(is_infinite($value), "sigmoid({$x}) вернул бесконечность");
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThanOrEqual(1.0, $value);
        }
    }

    public function test_sigmoid_saturates_at_the_limits_of_double_precision(): void
    {
        // exp(800) не представим в double, поэтому обе границы недостижимы
        // для любой реализации: функция насыщается, а не ломается.
        $this->assertSame(0.0, Math::sigmoid(-800));
        $this->assertSame(1.0, Math::sigmoid(800));

        // Устойчивая форма exp(x)/(1+exp(x)) сохраняет дальний хвост там,
        // где наивная 1/(1+exp(-x)) уже обнулилась бы из-за переполнения.
        $this->assertGreaterThan(0.0, Math::sigmoid(-709));
        $this->assertLessThan(1.0, Math::sigmoid(36));
    }

    public function test_sigmoid_on_the_range_the_model_actually_uses(): void
    {
        // Балл модели лежит в [-1, 1], score_scale = 3.5,
        // поэтому аргумент никогда не выходит за [-3.5, 3.5].
        $low = Math::sigmoid(-3.5);
        $high = Math::sigmoid(3.5);

        $this->assertEqualsWithDelta(0.0293, $low, 1e-4);
        $this->assertEqualsWithDelta(0.9707, $high, 1e-4);
        $this->assertEqualsWithDelta(1.0, $low + $high, 1e-12);
    }

    public function test_remove_vig_normalises_probabilities(): void
    {
        $fair = Math::removeVig(['a' => 1.80, 'b' => 2.10]);

        $this->assertEqualsWithDelta(1.0, array_sum($fair), 1e-9);
        $this->assertGreaterThan($fair['b'], $fair['a']);
    }
}
