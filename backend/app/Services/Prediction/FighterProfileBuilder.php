<?php

namespace App\Services\Prediction;

use App\Models\Fighter;
use App\Models\FighterStat;
use Illuminate\Support\Collection;

/**
 * Сборка расчётного профиля бойца из данных БД (раздел 4.1 ТЗ).
 *
 * Показатели считаются по последним 5–10 боям с затуханием веса старых боёв
 * и поправкой на уровень соперников. Если детальной статистики по боям
 * не хватает, используются карьерные показатели из карточки бойца.
 */
class FighterProfileBuilder
{
    /** @param array<string, mixed> $config config('ufc.form') */
    public function __construct(private readonly array $config) {}

    public function build(Fighter $fighter, ?\DateTimeInterface $asOf = null): FighterProfile
    {
        $asOf = $asOf ?? new \DateTimeImmutable;

        $stats = $this->recentStats($fighter, $asOf);
        $weights = $this->weights($stats);

        $form = $stats->isNotEmpty()
            ? $this->fromFightStats($stats, $weights)
            : [];

        // Там, где данных по боям нет, подставляем карьерные показатели
        $career = $this->fromCareer($fighter);
        $merged = array_merge($career, array_filter($form, fn ($v) => $v !== null));

        $recent = $this->recentResults($stats);

        return new FighterProfile(
            id: (int) $fighter->id,
            name: (string) $fighter->name,
            age: $fighter->ageAt($asOf),
            heightCm: $fighter->height_cm,
            reachCm: $fighter->reach_cm,
            stance: $fighter->stance ?: 'unknown',
            takedownsPer15: (float) ($merged['takedowns_per_15'] ?? 0),
            takedownAccuracy: (float) ($merged['takedown_accuracy'] ?? 0),
            sigStrikesPerMin: (float) ($merged['sig_strikes_per_min'] ?? 0),
            strikingAccuracy: (float) ($merged['striking_accuracy'] ?? 0),
            submissionAttemptsPer15: (float) ($merged['submission_attempts_per_15'] ?? 0),
            finishRate: $fighter->finishRate(),
            takedownDefense: (float) ($merged['takedown_defense'] ?? 0),
            strikingDefense: (float) ($merged['striking_defense'] ?? 0),
            sigStrikesAbsorbedPerMin: (float) ($merged['sig_strikes_absorbed_per_min'] ?? 0),
            submissionDefense: $this->submissionDefense($fighter),
            cardioIndex: (float) ($merged['cardio_index'] ?? 0.5),
            wins: (int) $fighter->wins,
            losses: (int) $fighter->losses,
            draws: (int) $fighter->draws,
            ufcFights: (int) ($fighter->ufc_wins + $fighter->ufc_losses + $fighter->ufc_draws),
            fiveRoundFights: (int) $fighter->five_round_fights,
            titleFights: (int) $fighter->title_fights,
            winsByKo: (int) $fighter->wins_by_ko,
            winsBySubmission: (int) $fighter->wins_by_submission,
            winsByDecision: (int) $fighter->wins_by_decision,
            lossesByKo: (int) $fighter->losses_by_ko,
            lossesBySubmission: (int) $fighter->losses_by_submission,
            lossesByDecision: (int) $fighter->losses_by_decision,
            recentResults: $recent['results'],
            recentLossMethods: $recent['loss_methods'],
            style: $this->classifyStyle($fighter, $merged),
            dataCompleteness: $this->completeness($fighter, $merged, $stats->count()),
            opponentQuality: (float) ($merged['opponent_quality'] ?? 0.5),
        );
    }

    /** @return Collection<int, FighterStat> */
    private function recentStats(Fighter $fighter, \DateTimeInterface $asOf): Collection
    {
        return FighterStat::query()
            ->where('fighter_id', $fighter->id)
            ->where(function ($query) use ($asOf) {
                $query->whereNull('fight_date')->orWhere('fight_date', '<', $asOf);
            })
            ->orderByDesc('fight_date')
            ->limit((int) $this->config['recent_fights_max'])
            ->get();
    }

    /**
     * Вес боя: чем свежее, тем больше. Дополнительно усиливаются бои
     * против сильных соперников.
     *
     * @param  Collection<int, FighterStat>  $stats
     * @return array<int, float>
     */
    private function weights(Collection $stats): array
    {
        $decay = (float) $this->config['recency_decay'];
        $qualityWeight = (float) $this->config['opponent_quality_weight'];

        $weights = [];

        foreach ($stats->values() as $index => $stat) {
            $quality = $stat->opponent_quality ?? 0.5;
            $weights[$index] = pow($decay, $index) * (1 - $qualityWeight + $qualityWeight * 2 * $quality);
        }

        return $weights;
    }

    /**
     * @param  Collection<int, FighterStat>  $stats
     * @param  array<int, float>  $weights
     * @return array<string, float|null>
     */
    private function fromFightStats(Collection $stats, array $weights): array
    {
        $sum = [
            'time' => 0.0, 'td_landed' => 0.0, 'td_attempted' => 0.0,
            'td_conceded' => 0.0, 'td_faced' => 0.0,
            'ss_landed' => 0.0, 'ss_attempted' => 0.0, 'ss_absorbed' => 0.0,
            'ss_faced_attempts' => 0.0, 'sub_attempts' => 0.0,
            'ss_early' => 0.0, 'ss_late' => 0.0, 'time_early' => 0.0, 'time_late' => 0.0,
            'quality' => 0.0, 'weight' => 0.0,
        ];

        foreach ($stats->values() as $index => $stat) {
            $w = $weights[$index] ?? 1.0;

            $sum['time'] += $stat->fight_time_seconds * $w;
            $sum['td_landed'] += $stat->takedowns_landed * $w;
            $sum['td_attempted'] += $stat->takedowns_attempted * $w;
            $sum['td_conceded'] += $stat->takedowns_conceded * $w;
            $sum['td_faced'] += $stat->takedowns_faced * $w;
            $sum['ss_landed'] += $stat->sig_strikes_landed * $w;
            $sum['ss_attempted'] += $stat->sig_strikes_attempted * $w;
            $sum['ss_absorbed'] += $stat->sig_strikes_absorbed * $w;
            $sum['ss_faced_attempts'] += $stat->opponent_sig_strikes_attempted * $w;
            $sum['sub_attempts'] += $stat->submission_attempts * $w;
            $sum['ss_early'] += $stat->sig_strikes_landed_early * $w;
            $sum['ss_late'] += $stat->sig_strikes_landed_late * $w;
            $sum['time_early'] += $stat->fight_time_seconds_early * $w;
            $sum['time_late'] += $stat->fight_time_seconds_late * $w;
            $sum['quality'] += ($stat->opponent_quality ?? 0.5) * $w;
            $sum['weight'] += $w;
        }

        if ($sum['time'] <= 0) {
            return [];
        }

        return [
            'takedowns_per_15' => $sum['td_landed'] / $sum['time'] * 900,
            'takedown_accuracy' => $sum['td_attempted'] > 0 ? $sum['td_landed'] / $sum['td_attempted'] : null,
            'takedown_defense' => $sum['td_faced'] > 0 ? 1 - $sum['td_conceded'] / $sum['td_faced'] : null,
            'sig_strikes_per_min' => $sum['ss_landed'] / $sum['time'] * 60,
            'striking_accuracy' => $sum['ss_attempted'] > 0 ? $sum['ss_landed'] / $sum['ss_attempted'] : null,
            'striking_defense' => $sum['ss_faced_attempts'] > 0
                ? 1 - $sum['ss_absorbed'] / $sum['ss_faced_attempts']
                : null,
            'sig_strikes_absorbed_per_min' => $sum['ss_absorbed'] / $sum['time'] * 60,
            'submission_attempts_per_15' => $sum['sub_attempts'] / $sum['time'] * 900,
            'cardio_index' => $this->cardioIndex($sum),
            'opponent_quality' => $sum['weight'] > 0 ? $sum['quality'] / $sum['weight'] : 0.5,
        ];
    }

    /**
     * Кардио: во сколько раз падает плотность значимых ударов в поздних
     * раундах по сравнению с ранними. 1.0 — спада нет, 0.0 — полный провал.
     *
     * @param  array<string, float>  $sum
     */
    private function cardioIndex(array $sum): ?float
    {
        if ($sum['time_early'] <= 0 || $sum['time_late'] <= 0) {
            return null;
        }

        $earlyRate = $sum['ss_early'] / $sum['time_early'];
        $lateRate = $sum['ss_late'] / $sum['time_late'];

        if ($earlyRate <= 0) {
            return null;
        }

        $ratio = $lateRate / $earlyRate;

        // ratio = 1 -> 0.5 (норма); ratio = 1.5 -> 1.0; ratio = 0.5 -> 0.0
        return Math::clamp(0.5 + ($ratio - 1) * 1.0, 0.0, 1.0);
    }

    /** @return array<string, float|null> */
    private function fromCareer(Fighter $fighter): array
    {
        $avgTime = $fighter->avg_fight_time_seconds ?: 0;

        return [
            'takedowns_per_15' => $fighter->takedown_avg_per_15min,
            'takedown_accuracy' => $fighter->takedown_accuracy,
            'takedown_defense' => $fighter->takedown_defense,
            'sig_strikes_per_min' => $fighter->sig_strikes_landed_per_min,
            'striking_accuracy' => $fighter->striking_accuracy,
            'striking_defense' => $fighter->striking_defense,
            'sig_strikes_absorbed_per_min' => $fighter->sig_strikes_absorbed_per_min,
            'submission_attempts_per_15' => $fighter->submission_avg_per_15min,
            // Без поединочной разбивки кардио оцениваем по средней длительности боя:
            // те, кто регулярно доходит до решения, обычно выносливее.
            'cardio_index' => $avgTime > 0 ? Math::clamp($avgTime / 900, 0.2, 0.85) : 0.5,
            'opponent_quality' => 0.5,
        ];
    }

    /** Способность избегать сабмишенов: доля боёв без поражения приёмом. */
    private function submissionDefense(Fighter $fighter): float
    {
        $total = $fighter->wins + $fighter->losses + $fighter->draws;

        if ($total <= 0) {
            return 0.9;
        }

        return Math::clamp(1 - $fighter->losses_by_submission / $total, 0.3, 1.0);
    }

    /**
     * @param  Collection<int, FighterStat>  $stats
     * @return array{results: array<int, string>, loss_methods: array<int, string|null>}
     */
    private function recentResults(Collection $stats): array
    {
        $results = [];
        $methods = [];

        foreach ($stats->values() as $stat) {
            $results[] = (string) ($stat->result ?? 'unknown');
            $methods[] = $stat->result === 'loss' ? $stat->method : null;
        }

        return ['results' => $results, 'loss_methods' => $methods];
    }

    /**
     * Классификация стиля на основе статистики (раздел 4.1 ТЗ).
     *
     * @param  array<string, float|null>  $metrics
     */
    private function classifyStyle(Fighter $fighter, array $metrics): string
    {
        $takedowns = (float) ($metrics['takedowns_per_15'] ?? 0);
        $strikes = (float) ($metrics['sig_strikes_per_min'] ?? 0);
        $submissions = (float) ($metrics['submission_attempts_per_15'] ?? 0);

        $wins = max($fighter->wins, 1);
        $subShare = $fighter->wins_by_submission / $wins;
        $koShare = $fighter->wins_by_ko / $wins;

        if ($submissions >= 1.0 && $subShare >= 0.35) {
            return 'grappler';
        }

        if ($takedowns >= 2.5 && $strikes < 4.5) {
            return 'wrestler';
        }

        if ($takedowns < 1.5 && ($strikes >= 3.5 || $koShare >= 0.5)) {
            return 'striker';
        }

        if ($takedowns > 0 || $strikes > 0) {
            return 'balanced';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, float|null>  $metrics
     */
    private function completeness(Fighter $fighter, array $metrics, int $fightsWithStats): float
    {
        $checks = [
            ! empty($metrics['takedowns_per_15']),
            ! empty($metrics['takedown_defense']),
            ! empty($metrics['sig_strikes_per_min']),
            ! empty($metrics['striking_accuracy']),
            ! empty($metrics['striking_defense']),
            $fighter->reach_cm !== null,
            $fighter->date_of_birth !== null || $fighter->age !== null,
            $fighter->stance !== 'unknown',
            $fighter->wins + $fighter->losses > 0,
            $fightsWithStats >= (int) $this->config['recent_fights_min'],
        ];

        return count(array_filter($checks)) / count($checks);
    }
}
