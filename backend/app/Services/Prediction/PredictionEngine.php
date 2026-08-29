<?php

namespace App\Services\Prediction;

/**
 * Гибридная экспертно-статистическая модель прогнозирования (раздел 4 ТЗ).
 *
 * Порядок расчёта:
 *   1. Сравнение бойцов по каждому показателю     -> FactorCalculator
 *   2. Применение весов и свёртка в один балл     -> FactorCalculator::score()
 *   3. Сигмоида: балл -> базовая вероятность
 *   4. Экспертные правила -> корректировка вероятности
 *   5. Учёт очной встречи (вес 0.15)
 *   6. Ограничение диапазона и расчёт уверенности
 *   7. Оценка метода победы и тотала раундов      -> MethodEstimator
 *   8. Текстовое объяснение                       -> ExplanationBuilder
 *
 * Класс не зависит от Laravel: на вход подаётся массив конфигурации,
 * что позволяет прогонять его в юнит-тестах и в бэктестинге
 * с изменёнными весами.
 */
class PredictionEngine
{
    public const VERSION = '1.0';

    private FactorCalculator $factors;

    private ExpertRules $rules;

    private MethodEstimator $methods;

    private ExplanationBuilder $explanations;

    /** @param array<string, mixed> $config содержимое config('ufc') */
    public function __construct(private readonly array $config)
    {
        $this->factors = new FactorCalculator($config['weights']);
        $this->rules = new ExpertRules($config['expert_rules']);
        $this->methods = new MethodEstimator($config['method']);
        $this->explanations = new ExplanationBuilder;
    }

    public function predict(FighterProfile $f1, FighterProfile $f2, FightContext $context): PredictionResult
    {
        // Шаги 1–2: показатели, веса, итоговый балл в [-1, 1]
        $factors = $this->factors->calculate($f1, $f2, $context);
        $score = $this->factors->score($factors);

        // Шаг 3: сигмоида
        $scale = (float) $this->config['score_scale'];
        $baseProbability = Math::sigmoid($score * $scale);

        // Шаг 4: экспертные правила
        $appliedRules = $this->rules->apply($f1, $f2, $context);
        $probability = $baseProbability + $this->rules->netAdjustment($appliedRules);

        // Шаг 5: очная встреча
        $probability = $this->applyHeadToHead($probability, $context);

        // Шаг 6: границы
        $bounds = $this->config['probability_bounds'];
        $probability = Math::clamp($probability, (float) $bounds['min'], (float) $bounds['max']);

        $completeness = $this->dataCompleteness($f1, $f2);
        $confidence = $this->confidence($probability, $completeness);

        // Шаг 7: метод победы и тотал раундов
        $methodProbabilities = $this->methods->estimate($f1, $f2, $context, $probability);

        $draft = [
            'probability_fighter1' => $probability,
            'factors' => $factors,
            'applied_rules' => $appliedRules,
            'data_completeness' => $completeness,
        ];

        // Шаг 8: объяснение
        $explanation = $this->explanations->build($f1, $f2, $context, $draft);

        return new PredictionResult(
            probabilityFighter1: round($probability, 5),
            probabilityFighter2: round(1 - $probability, 5),
            scoreFighter1: round(Math::logit($probability) / $scale, 5),
            baseProbability: round($baseProbability, 5),
            factors: $factors,
            appliedRules: $appliedRules,
            methodProbabilities: $methodProbabilities,
            probabilityOver25: $methodProbabilities['over_2_5'],
            probabilityUnder25: $methodProbabilities['under_2_5'],
            confidence: round($confidence, 4),
            dataCompleteness: round($completeness, 4),
            explanation: $explanation,
            modelVersion: self::VERSION,
        );
    }

    /**
     * Очная встреча корректирует вероятность с весом 0.15:
     * P = (1 - w) * P_model + w * P_h2h.
     */
    private function applyHeadToHead(float $probability, FightContext $context): float
    {
        $rule = $this->config['expert_rules']['head_to_head'] ?? null;

        if (! $rule || ($rule['enabled'] ?? true) === false || ! $context->hasHeadToHead()) {
            return $probability;
        }

        $h2h = $context->headToHead;
        $wins1 = (int) ($h2h['fighter1'] ?? 0);
        $wins2 = (int) ($h2h['fighter2'] ?? 0);
        $draws = (int) ($h2h['draws'] ?? 0);
        $total = $wins1 + $wins2 + $draws;

        if ($total === 0) {
            return $probability;
        }

        // Сырое соотношение сжимается к 0.5: одна победа в прошлом — не приговор
        $raw = ($wins1 + 0.5 * $draws) / $total;
        $shrinkage = $total / ($total + 1.5);
        $h2hProbability = 0.5 + ($raw - 0.5) * $shrinkage;

        $weight = (float) $rule['weight'];

        return (1 - $weight) * $probability + $weight * $h2hProbability;
    }

    /**
     * Полнота данных: доля ключевых показателей, которые реально заполнены.
     * Используется как множитель уверенности и как предупреждение в объяснении.
     */
    private function dataCompleteness(FighterProfile $f1, FighterProfile $f2): float
    {
        $filled = 0;
        $total = 0;

        foreach ([$f1, $f2] as $fighter) {
            $checks = [
                $fighter->takedownsPer15 > 0,
                $fighter->takedownDefense > 0,
                $fighter->sigStrikesPerMin > 0,
                $fighter->strikingAccuracy > 0,
                $fighter->strikingDefense > 0,
                $fighter->sigStrikesAbsorbedPerMin > 0,
                $fighter->age !== null,
                $fighter->reachCm !== null,
                $fighter->stance !== 'unknown',
                $fighter->totalFights() > 0,
                $fighter->winsByKo + $fighter->winsBySubmission + $fighter->winsByDecision > 0,
                $fighter->recentResults !== [],
            ];

            $total += count($checks);
            $filled += count(array_filter($checks));
        }

        return Math::divide((float) $filled, (float) $total);
    }

    /**
     * Уверенность = насколько прогноз далёк от 50/50, с поправкой на полноту данных.
     * При полностью отсутствующих данных уверенность падает, даже если
     * вероятность получилась высокой.
     */
    private function confidence(float $probability, float $completeness): float
    {
        $decisiveness = abs($probability - 0.5) * 2;

        return Math::clamp($decisiveness * (0.4 + 0.6 * $completeness), 0.0, 1.0);
    }
}
