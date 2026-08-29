<?php

namespace App\Services\Prediction;

/**
 * Результат работы модели по одному бою.
 */
class PredictionResult
{
    /**
     * @param  array<int, array<string, mixed>>  $factors
     * @param  array<int, array<string, mixed>>  $appliedRules
     * @param  array<string, mixed>  $methodProbabilities
     */
    public function __construct(
        public readonly float $probabilityFighter1,
        public readonly float $probabilityFighter2,
        public readonly float $scoreFighter1,
        public readonly float $baseProbability,
        public readonly array $factors,
        public readonly array $appliedRules,
        public readonly array $methodProbabilities,
        public readonly float $probabilityOver25,
        public readonly float $probabilityUnder25,
        public readonly float $confidence,
        public readonly float $dataCompleteness,
        public readonly string $explanation,
        public readonly string $modelVersion = '1.0',
    ) {}

    public function toArray(): array
    {
        return [
            'probability_fighter1' => $this->probabilityFighter1,
            'probability_fighter2' => $this->probabilityFighter2,
            'probability_draw' => 0.0,
            'score_fighter1' => $this->scoreFighter1,
            'score_fighter2' => -$this->scoreFighter1,
            'base_probability' => $this->baseProbability,
            'method_probabilities' => $this->methodProbabilities,
            'probability_over_2_5' => $this->probabilityOver25,
            'probability_under_2_5' => $this->probabilityUnder25,
            'factors' => $this->factors,
            'applied_rules' => $this->appliedRules,
            'confidence' => $this->confidence,
            'data_completeness' => $this->dataCompleteness,
            'explanation' => $this->explanation,
            'model_version' => $this->modelVersion,
        ];
    }
}
