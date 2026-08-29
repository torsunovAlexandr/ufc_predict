<?php

namespace App\Services\Prediction;

/**
 * Оценка вероятностей метода победы и тотала раундов.
 *
 * Логика: у каждого бойца есть личное распределение методов побед
 * (из разбивки его рекорда). Оно смешивается с базовым распределением UFC
 * и корректируется на уязвимости соперника (кого чаще нокаутируют,
 * кто чаще попадается на сабмишен).
 */
class MethodEstimator
{
    /** @param array<string, mixed> $config config('ufc.method') */
    public function __construct(private readonly array $config) {}

    /**
     * @return array{
     *   fighter1: array{ko_tko: float, submission: float, decision: float},
     *   fighter2: array{ko_tko: float, submission: float, decision: float},
     *   markets: array{ko_tko: float, submission: float, decision: float},
     *   over_2_5: float,
     *   under_2_5: float
     * }
     */
    public function estimate(
        FighterProfile $f1,
        FighterProfile $f2,
        FightContext $context,
        float $probabilityFighter1
    ): array {
        $dist1 = $this->distributionFor($f1, $f2, $context);
        $dist2 = $this->distributionFor($f2, $f1, $context);

        $p1 = Math::clamp($probabilityFighter1, 0.0, 1.0);
        $p2 = 1.0 - $p1;

        // Безусловные вероятности рынков «метод победы» (любым бойцом)
        $markets = [
            'ko_tko' => $p1 * $dist1['ko_tko'] + $p2 * $dist2['ko_tko'],
            'submission' => $p1 * $dist1['submission'] + $p2 * $dist2['submission'],
            'decision' => $p1 * $dist1['decision'] + $p2 * $dist2['decision'],
        ];

        [$over, $under] = $this->totalRounds($markets, $context);

        return [
            'fighter1' => $dist1,
            'fighter2' => $dist2,
            'markets' => $this->round($markets),
            'over_2_5' => round($over, 5),
            'under_2_5' => round($under, 5),
        ];
    }

    /**
     * Распределение методов победы конкретного бойца над конкретным соперником.
     *
     * @return array{ko_tko: float, submission: float, decision: float}
     */
    private function distributionFor(FighterProfile $self, FighterProfile $rival, FightContext $context): array
    {
        $baseline = $this->config['baseline'];
        $personalWeight = (float) $this->config['personal_weight'];

        // Личное распределение по рекорду
        $wins = max($self->wins, 1);
        $personal = [
            'ko_tko' => $self->winsByKo / $wins,
            'submission' => $self->winsBySubmission / $wins,
            'decision' => $self->winsByDecision / $wins,
        ];

        // Если разбивки нет — опираемся только на базовое распределение
        if (array_sum($personal) < 0.01) {
            $personal = $baseline;
        } else {
            $personal = $this->normalize($personal);
        }

        $dist = [
            'ko_tko' => $personalWeight * $personal['ko_tko'] + (1 - $personalWeight) * $baseline['ko_tko'],
            'submission' => $personalWeight * $personal['submission'] + (1 - $personalWeight) * $baseline['submission'],
            'decision' => $personalWeight * $personal['decision'] + (1 - $personalWeight) * $baseline['decision'],
        ];

        // Уязвимости соперника: как часто он проигрывает нокаутом / сабмишеном
        $rivalLosses = max($rival->losses, 1);
        $rivalKoRate = $rival->lossesByKo / $rivalLosses;
        $rivalSubRate = $rival->lossesBySubmission / $rivalLosses;

        if ($rival->losses > 0) {
            // Сдвигаем распределение пропорционально «хрупкости» соперника
            $dist['ko_tko'] *= 1 + ($rivalKoRate - 0.33) * 0.6;
            $dist['submission'] *= 1 + ($rivalSubRate - 0.17) * 0.6;
        }

        // Соперник с сильной защитой от сабмишенов реже попадается на приём
        $dist['submission'] *= Math::clamp(2 - $rival->submissionDefense, 0.5, 1.5);

        // В пятираундовых боях чаще доходит до решения
        if ($context->isFiveRound()) {
            $dist['decision'] += (float) $this->config['five_round_decision_boost'];
        }

        $dist = array_map(fn (float $v): float => max($v, 0.001), $dist);

        return $this->normalize($dist);
    }

    /**
     * Тотал раундов «больше/меньше 2.5».
     *
     * Бой заканчивается раньше 2.5 раундов только при досрочной победе,
     * причём не любой — часть финишей приходится на третий и последующие
     * раунды. Доля ранних финишей оценивается эмпирически.
     *
     * @param  array{ko_tko: float, submission: float, decision: float}  $markets
     * @return array{0: float, 1: float}  [over, under]
     */
    private function totalRounds(array $markets, FightContext $context): array
    {
        $finishProbability = $markets['ko_tko'] + $markets['submission'];

        // Из всех досрочных окончаний примерно 62% приходятся на первые два раунда
        // в трёхраундовых боях и около 52% — в пятираундовых (бои длиннее).
        $earlyShare = $context->isFiveRound() ? 0.52 : 0.62;

        $under = Math::clamp($finishProbability * $earlyShare, 0.02, 0.95);

        return [1 - $under, $under];
    }

    /**
     * @param  array<string, float>  $dist
     * @return array<string, float>
     */
    private function normalize(array $dist): array
    {
        $sum = array_sum($dist);

        if ($sum <= 0) {
            return $this->config['baseline'];
        }

        return $this->round(array_map(fn (float $v): float => $v / $sum, $dist));
    }

    /**
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    private function round(array $values): array
    {
        return array_map(fn (float $v): float => round($v, 5), $values);
    }
}
