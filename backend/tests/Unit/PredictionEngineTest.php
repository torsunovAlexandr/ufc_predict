<?php

namespace Tests\Unit;

use App\Services\Prediction\FightContext;
use App\Services\Prediction\FighterProfile;
use App\Services\Prediction\PredictionEngine;
use Tests\TestCase;

class PredictionEngineTest extends TestCase
{
    private function engine(array $overrides = []): PredictionEngine
    {
        return new PredictionEngine(array_replace_recursive(config('ufc'), $overrides));
    }

    private function wrestler(array $overrides = []): FighterProfile
    {
        return FighterProfile::fromArray(array_merge([
            'id' => 1, 'name' => 'Борец', 'age' => 28, 'height_cm' => 180, 'reach_cm' => 183,
            'stance' => 'orthodox', 'takedowns_per_15' => 4.2, 'takedown_accuracy' => 0.45,
            'sig_strikes_per_min' => 3.1, 'striking_accuracy' => 0.44,
            'submission_attempts_per_15' => 1.2, 'takedown_defense' => 0.80,
            'striking_defense' => 0.58, 'sig_strikes_absorbed_per_min' => 2.6,
            'submission_defense' => 0.9, 'cardio_index' => 0.72,
            'wins' => 18, 'losses' => 3, 'ufc_fights' => 10, 'five_round_fights' => 2, 'title_fights' => 1,
            'wins_by_ko' => 4, 'wins_by_submission' => 6, 'wins_by_decision' => 8,
            'losses_by_ko' => 1, 'losses_by_submission' => 0, 'losses_by_decision' => 2,
            'recent_results' => ['win', 'win', 'win', 'loss', 'win'],
            'recent_loss_methods' => [null, null, null, 'decision', null],
            'style' => 'wrestler', 'data_completeness' => 1.0,
        ], $overrides));
    }

    private function striker(array $overrides = []): FighterProfile
    {
        return FighterProfile::fromArray(array_merge([
            'id' => 2, 'name' => 'Ударник', 'age' => 34, 'height_cm' => 178, 'reach_cm' => 180,
            'stance' => 'orthodox', 'takedowns_per_15' => 0.4, 'takedown_accuracy' => 0.25,
            'sig_strikes_per_min' => 5.4, 'striking_accuracy' => 0.51,
            'submission_attempts_per_15' => 0.2, 'takedown_defense' => 0.42,
            'striking_defense' => 0.62, 'sig_strikes_absorbed_per_min' => 3.4,
            'submission_defense' => 0.7, 'cardio_index' => 0.55,
            'wins' => 20, 'losses' => 6, 'ufc_fights' => 12, 'five_round_fights' => 1,
            'wins_by_ko' => 14, 'wins_by_submission' => 1, 'wins_by_decision' => 5,
            'losses_by_ko' => 3, 'losses_by_submission' => 1, 'losses_by_decision' => 2,
            'recent_results' => ['win', 'loss', 'win', 'win', 'loss'],
            'recent_loss_methods' => [null, 'ko_tko', null, null, 'decision'],
            'style' => 'striker', 'data_completeness' => 1.0,
        ], $overrides));
    }

    public function test_probabilities_sum_to_one(): void
    {
        $prediction = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $this->assertEqualsWithDelta(
            1.0,
            $prediction->probabilityFighter1 + $prediction->probabilityFighter2,
            1e-4
        );
    }

    public function test_identical_fighters_produce_even_odds(): void
    {
        $a = $this->wrestler();
        $b = $this->wrestler(['id' => 9, 'name' => 'Клон']);

        $prediction = $this->engine()->predict($a, $b, new FightContext);

        $this->assertEqualsWithDelta(0.5, $prediction->probabilityFighter1, 1e-6);
    }

    public function test_model_is_symmetric(): void
    {
        $direct = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);
        $mirrored = $this->engine()->predict($this->striker(), $this->wrestler(), new FightContext);

        $this->assertEqualsWithDelta($direct->probabilityFighter1, $mirrored->probabilityFighter2, 1e-3);
    }

    public function test_probability_stays_within_configured_bounds(): void
    {
        $monster = $this->wrestler([
            'takedowns_per_15' => 12, 'sig_strikes_per_min' => 12, 'takedown_defense' => 0.99,
            'striking_defense' => 0.95, 'cardio_index' => 1.0, 'submission_attempts_per_15' => 6,
        ]);

        $rookie = $this->striker([
            'takedowns_per_15' => 0, 'sig_strikes_per_min' => 0.4, 'takedown_defense' => 0.05,
            'striking_defense' => 0.1, 'cardio_index' => 0.0, 'submission_attempts_per_15' => 0,
        ]);

        $prediction = $this->engine()->predict($monster, $rookie, new FightContext);

        $this->assertLessThanOrEqual(config('ufc.probability_bounds.max'), $prediction->probabilityFighter1);
        $this->assertGreaterThanOrEqual(config('ufc.probability_bounds.min'), $prediction->probabilityFighter2);
    }

    public function test_wrestler_rule_fires_against_weak_takedown_defense(): void
    {
        $prediction = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $this->assertContains(
            'wrestler_vs_weak_td_defense',
            array_column($prediction->appliedRules, 'key')
        );
    }

    public function test_missing_five_round_experience_lowers_probability(): void
    {
        $withExperience = $this->engine()->predict(
            $this->wrestler(),
            $this->wrestler(['id' => 9, 'name' => 'Клон', 'five_round_fights' => 3]),
            new FightContext(scheduledRounds: 5)
        );

        $withoutExperience = $this->engine()->predict(
            $this->wrestler(['five_round_fights' => 0]),
            $this->wrestler(['id' => 9, 'name' => 'Клон', 'five_round_fights' => 3]),
            new FightContext(scheduledRounds: 5)
        );

        $this->assertLessThan($withExperience->probabilityFighter1, $withoutExperience->probabilityFighter1);
    }

    public function test_southpaw_gets_small_bonus(): void
    {
        $base = $this->engine()->predict(
            $this->wrestler(),
            $this->wrestler(['id' => 9, 'name' => 'Клон']),
            new FightContext
        );

        $southpaw = $this->engine()->predict(
            $this->wrestler(['stance' => 'southpaw']),
            $this->wrestler(['id' => 9, 'name' => 'Клон']),
            new FightContext
        );

        $this->assertGreaterThan($base->probabilityFighter1, $southpaw->probabilityFighter1);
        $this->assertLessThan(0.06, $southpaw->probabilityFighter1 - $base->probabilityFighter1);
    }

    public function test_two_consecutive_ko_losses_lower_probability(): void
    {
        $healthy = $this->wrestler(['id' => 9, 'name' => 'Клон']);

        $damaged = $this->wrestler([
            'recent_results' => ['loss', 'loss', 'win', 'win', 'win'],
            'recent_loss_methods' => ['ko_tko', 'ko_tko', null, null, null],
        ]);

        $prediction = $this->engine()->predict($damaged, $healthy, new FightContext);

        $this->assertContains('recent_ko_losses', array_column($prediction->appliedRules, 'key'));
        $this->assertLessThan(0.5, $prediction->probabilityFighter1);
    }

    public function test_head_to_head_shifts_probability(): void
    {
        $neutral = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $lostBefore = $this->engine()->predict(
            $this->wrestler(),
            $this->striker(),
            new FightContext(headToHead: ['fighter1' => 0, 'fighter2' => 2, 'draws' => 0])
        );

        $this->assertLessThan($neutral->probabilityFighter1, $lostBefore->probabilityFighter1);
    }

    public function test_method_probabilities_are_normalised(): void
    {
        $prediction = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $this->assertEqualsWithDelta(1.0, array_sum($prediction->methodProbabilities['markets']), 1e-3);
        $this->assertEqualsWithDelta(
            1.0,
            $prediction->probabilityOver25 + $prediction->probabilityUnder25,
            1e-4
        );
    }

    public function test_striker_is_more_likely_to_win_by_knockout(): void
    {
        $prediction = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $this->assertGreaterThan(
            $prediction->methodProbabilities['fighter2']['submission'],
            $prediction->methodProbabilities['fighter2']['ko_tko']
        );
    }

    public function test_explanation_is_in_russian_and_mentions_probability(): void
    {
        $prediction = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $this->assertStringContainsString('вероятность победы', $prediction->explanation);
        $this->assertMatchesRegularExpression('/\d+%/', $prediction->explanation);
    }

    public function test_confidence_drops_when_data_is_incomplete(): void
    {
        $blank = FighterProfile::fromArray(['id' => 5, 'name' => 'Без данных']);

        $prediction = $this->engine()->predict($blank, $this->striker(), new FightContext);

        $this->assertLessThan(0.6, $prediction->dataCompleteness);
    }

    public function test_factor_weights_sum_to_one(): void
    {
        $prediction = $this->engine()->predict($this->wrestler(), $this->striker(), new FightContext);

        $this->assertEqualsWithDelta(1.0, array_sum(array_column($prediction->factors, 'weight')), 1e-6);
    }
}
