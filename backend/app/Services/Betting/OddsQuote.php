<?php

namespace App\Services\Betting;

/**
 * Котировка на конкретный исход — то, с чем работает калькулятор ставок.
 * Отвязана от Eloquent, чтобы модуль можно было прогонять в тестах
 * и в бэктестинге на исторических коэффициентах.
 */
class OddsQuote
{
    public function __construct(
        public readonly string $market,      // moneyline | draw | totals | method
        public readonly string $selection,   // fighter1 | fighter2 | draw | over | under | ko_tko | submission | decision
        public readonly float $price,        // десятичный коэффициент
        public readonly ?string $bookmaker = null,
        public readonly ?float $line = null, // напр. 2.5 для тотала раундов
        public readonly ?int $fighterId = null,
        public readonly ?int $oddId = null,  // ссылка на строку в таблице odds
    ) {}

    /** Вероятность, заложенная букмекером (с маржой). */
    public function impliedProbability(): float
    {
        return $this->price > 0 ? 1 / $this->price : 0.0;
    }

    public function key(): string
    {
        return $this->line !== null
            ? "{$this->market}:{$this->selection}:{$this->line}"
            : "{$this->market}:{$this->selection}";
    }

    public function label(): string
    {
        return match (true) {
            $this->market === 'moneyline' && $this->selection === 'fighter1' => 'Победа первого бойца',
            $this->market === 'moneyline' && $this->selection === 'fighter2' => 'Победа второго бойца',
            $this->market === 'draw' => 'Ничья',
            $this->market === 'totals' && $this->selection === 'over' => 'Тотал раундов больше '.$this->line,
            $this->market === 'totals' && $this->selection === 'under' => 'Тотал раундов меньше '.$this->line,
            $this->market === 'method' && $this->selection === 'ko_tko' => 'Победа нокаутом',
            $this->market === 'method' && $this->selection === 'submission' => 'Победа сабмишеном',
            $this->market === 'method' && $this->selection === 'decision' => 'Победа решением',
            default => $this->market.' / '.$this->selection,
        };
    }
}
