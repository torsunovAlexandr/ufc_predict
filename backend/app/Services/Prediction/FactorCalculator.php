<?php

namespace App\Services\Prediction;

/**
 * Шаги 1–2 гибридной модели: сравнение бойцов по каждому показателю
 * и применение весов.
 *
 * Каждая группа показателей («тейкдауны в атаке», «защита от ударов» и т.д.)
 * имеет свой вес из config/ufc.php. Внутри группы может быть несколько
 * сырых метрик — они складываются с внутренними долями.
 */
class FactorCalculator
{
    /** Человекочитаемые названия групп для объяснения прогноза. */
    public const LABELS = [
        'takedowns_offense' => 'Борьба в атаке',
        'takedown_defense' => 'Защита от тейкдаунов',
        'striking_offense' => 'Ударная техника',
        'striking_defense' => 'Защита в стойке',
        'submission_offense' => 'Сабмишены в атаке',
        'submission_defense' => 'Защита от сабмишенов',
        'cardio' => 'Кардио',
        'physical_experience' => 'Физика и опыт',
    ];

    /** @param array<string, float> $weights */
    public function __construct(private readonly array $weights) {}

    /**
     * @return array<int, array{
     *     key: string, label: string, weight: float, advantage: float,
     *     contribution: float, values: array{0: float, 1: float}, unit: string
     * }>
     */
    public function calculate(FighterProfile $f1, FighterProfile $f2, FightContext $context): array
    {
        $factors = [];

        // --- Тейкдауны в атаке: объём (70%) + реализация (30%) ---
        $factors[] = $this->factor(
            'takedowns_offense',
            0.70 * Math::advantage($f1->takedownsPer15, $f2->takedownsPer15)
            + 0.30 * Math::advantage($f1->takedownAccuracy, $f2->takedownAccuracy),
            [$f1->takedownsPer15, $f2->takedownsPer15],
            'тейкдаунов за бой'
        );

        // --- Защита от тейкдаунов ---
        $factors[] = $this->factor(
            'takedown_defense',
            Math::advantage($f1->takedownDefense, $f2->takedownDefense),
            [$f1->takedownDefense, $f2->takedownDefense],
            'защита от тейкдаунов'
        );

        // --- Ударная техника: объём (60%) + точность (40%) ---
        $factors[] = $this->factor(
            'striking_offense',
            0.60 * Math::advantage($f1->sigStrikesPerMin, $f2->sigStrikesPerMin)
            + 0.40 * Math::advantage($f1->strikingAccuracy, $f2->strikingAccuracy),
            [$f1->sigStrikesPerMin, $f2->sigStrikesPerMin],
            'значимых ударов в минуту'
        );

        // --- Защита в стойке: процент защиты (50%) + сколько пропускает (50%, чем меньше — тем лучше) ---
        $factors[] = $this->factor(
            'striking_defense',
            0.50 * Math::advantage($f1->strikingDefense, $f2->strikingDefense)
            + 0.50 * (-Math::advantage($f1->sigStrikesAbsorbedPerMin, $f2->sigStrikesAbsorbedPerMin)),
            [$f1->strikingDefense, $f2->strikingDefense],
            'защита от ударов'
        );

        // --- Сабмишены в атаке: попытки (60%) + доля побед сабмишеном (40%) ---
        $subRate1 = Math::divide((float) $f1->winsBySubmission, (float) max($f1->wins, 1));
        $subRate2 = Math::divide((float) $f2->winsBySubmission, (float) max($f2->wins, 1));

        $factors[] = $this->factor(
            'submission_offense',
            0.60 * Math::advantage($f1->submissionAttemptsPer15, $f2->submissionAttemptsPer15)
            + 0.40 * Math::advantage($subRate1, $subRate2),
            [$f1->submissionAttemptsPer15, $f2->submissionAttemptsPer15],
            'попыток сабмишена за бой'
        );

        // --- Защита от сабмишенов ---
        $factors[] = $this->factor(
            'submission_defense',
            Math::advantage($f1->submissionDefense, $f2->submissionDefense),
            [$f1->submissionDefense, $f2->submissionDefense],
            'устойчивость к сабмишенам'
        );

        // --- Кардио (в пятираундовых боях вес группы увеличивается) ---
        $cardioAdvantage = Math::advantage($f1->cardioIndex, $f2->cardioIndex);
        $factors[] = $this->factor(
            'cardio',
            $cardioAdvantage,
            [$f1->cardioIndex, $f2->cardioIndex],
            'индекс выносливости',
            $context->isFiveRound() ? 1.4 : 1.0
        );

        // --- Физика и опыт ---
        $factors[] = $this->factor(
            'physical_experience',
            $this->physicalAdvantage($f1, $f2, $context),
            [(float) ($f1->reachCm ?? 0), (float) ($f2->reachCm ?? 0)],
            'размах рук, см'
        );

        return $factors;
    }

    /**
     * Композит из размаха рук, роста, возраста и опыта в UFC.
     * Значимость физических данных зависит от весовой категории.
     */
    private function physicalAdvantage(FighterProfile $f1, FighterProfile $f2, FightContext $context): float
    {
        $physicality = $context->physicalityMultiplier();

        $reach = Math::advantage((float) ($f1->reachCm ?? 0), (float) ($f2->reachCm ?? 0)) * 8.0;
        $height = Math::advantage((float) ($f1->heightCm ?? 0), (float) ($f2->heightCm ?? 0)) * 8.0;

        // Разница в росте и размахе мала в относительных величинах,
        // поэтому усиливаем её множителем, а затем ограничиваем.
        $reach = Math::clamp($reach, -1, 1);
        $height = Math::clamp($height, -1, 1);

        // Возраст: преимущество у более молодого, но только до 35 лет
        $age = 0.0;
        if ($f1->age !== null && $f2->age !== null) {
            $gap = $f2->age - $f1->age; // положительно — первый боец моложе
            $age = Math::clamp($gap / 10.0, -1, 1);

            // После 35 «молодость» перестаёт быть преимуществом сама по себе
            if ($f1->age > 35 && $f2->age > 35) {
                $age *= 0.5;
            }
        }

        // Опыт в UFC
        $experience = Math::advantage((float) $f1->ufcFights, (float) $f2->ufcFights);

        return Math::clamp(
            0.30 * $reach * $physicality
            + 0.15 * $height * $physicality
            + 0.35 * $age
            + 0.20 * $experience,
            -1,
            1
        );
    }

    /**
     * @param  array{0: float, 1: float}  $values
     * @return array{key:string,label:string,weight:float,advantage:float,contribution:float,values:array{0:float,1:float},unit:string}
     */
    private function factor(
        string $key,
        float $advantage,
        array $values,
        string $unit,
        float $weightMultiplier = 1.0
    ): array {
        $advantage = Math::clamp($advantage, -1.0, 1.0);
        $weight = ($this->weights[$key] ?? 0.0) * $weightMultiplier;

        return [
            'key' => $key,
            'label' => self::LABELS[$key] ?? $key,
            'weight' => round($weight, 4),
            'advantage' => round($advantage, 5),
            'contribution' => round($advantage * $weight, 5),
            'values' => [round($values[0], 3), round($values[1], 3)],
            'unit' => $unit,
        ];
    }

    /**
     * Итоговый балл — взвешенная сумма преимуществ, нормированная на сумму
     * фактически использованных весов (важно, если веса группы менялись
     * множителем: score должен остаться в [-1, 1]).
     *
     * @param  array<int, array{weight: float, contribution: float}>  $factors
     */
    public function score(array $factors): float
    {
        $totalWeight = array_sum(array_column($factors, 'weight'));
        $sum = array_sum(array_column($factors, 'contribution'));

        return Math::divide($sum, $totalWeight);
    }
}
