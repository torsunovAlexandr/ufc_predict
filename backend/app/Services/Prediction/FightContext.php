<?php

namespace App\Services\Prediction;

/**
 * Контекст боя: всё, что относится не к бойцам по отдельности,
 * а к условиям конкретного поединка (раздел 4.2 ТЗ).
 */
class FightContext
{
    /**
     * @param  array{fighter1:int, fighter2:int, draws:int}|null  $headToHead
     *         Число побед каждого бойца в очных встречах.
     */
    public function __construct(
        public readonly int $scheduledRounds = 3,
        public readonly bool $isTitleFight = false,
        public readonly bool $isMainEvent = false,
        public readonly ?string $weightClass = null,
        public readonly ?int $altitudeMeters = null,
        public readonly ?array $headToHead = null,
    ) {}

    public function isFiveRound(): bool
    {
        return $this->scheduledRounds >= 5;
    }

    public function hasHeadToHead(): bool
    {
        if (! $this->headToHead) {
            return false;
        }

        return array_sum([
            $this->headToHead['fighter1'] ?? 0,
            $this->headToHead['fighter2'] ?? 0,
            $this->headToHead['draws'] ?? 0,
        ]) > 0;
    }

    /**
     * Лёгкие весовые категории: физические данные (рост, размах) значат меньше,
     * тяжёлые — больше, поскольку одна ошибка чаще заканчивает бой.
     */
    public function physicalityMultiplier(): float
    {
        $class = mb_strtolower((string) $this->weightClass);

        return match (true) {
            str_contains($class, 'heavyweight') || str_contains($class, 'тяжёл') || str_contains($class, 'тяжел') => 1.3,
            str_contains($class, 'light heavyweight') || str_contains($class, 'полутяж') => 1.2,
            str_contains($class, 'flyweight') || str_contains($class, 'наилегч') => 0.8,
            str_contains($class, 'bantamweight') || str_contains($class, 'легчайш') => 0.85,
            default => 1.0,
        };
    }

    public static function fromArray(array $data): self
    {
        return new self(
            scheduledRounds: (int) ($data['scheduled_rounds'] ?? 3),
            isTitleFight: (bool) ($data['is_title_fight'] ?? false),
            isMainEvent: (bool) ($data['is_main_event'] ?? false),
            weightClass: $data['weight_class'] ?? null,
            altitudeMeters: isset($data['altitude_meters']) ? (int) $data['altitude_meters'] : null,
            headToHead: $data['head_to_head'] ?? null,
        );
    }
}
