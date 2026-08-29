<?php

namespace App\Services\Betting;

/**
 * Рекомендация по ставке: что ставить, по какому коэффициенту и сколько.
 */
class BetRecommendation
{
    public function __construct(
        public readonly string $market,
        public readonly string $selection,
        public readonly ?float $line,
        public readonly ?string $bookmaker,
        public readonly float $odds,
        public readonly float $modelProbability,
        public readonly float $impliedProbability,
        public readonly float $expectedValue,
        public readonly float $kellyFraction,   // «сырая» доля по Келли
        public readonly float $stakeFraction,   // доля после всех ограничений
        public readonly float $stake,           // сумма в рублях
        public readonly ?int $fighterId = null,
        public readonly ?int $oddId = null,
        public readonly string $reason = '',
    ) {}

    public function toArray(): array
    {
        return [
            'market' => $this->market,
            'selection' => $this->selection,
            'line' => $this->line,
            'bookmaker' => $this->bookmaker,
            'odds' => round($this->odds, 3),
            'model_probability' => round($this->modelProbability, 5),
            'implied_probability' => round($this->impliedProbability, 5),
            'expected_value' => round($this->expectedValue, 5),
            'kelly_fraction' => round($this->kellyFraction, 5),
            'stake_fraction' => round($this->stakeFraction, 5),
            'stake' => round($this->stake, 2),
            'fighter_id' => $this->fighterId,
            'reason' => $this->reason,
        ];
    }
}
