<?php

namespace App\Services\Prediction;

/**
 * Готовый к расчёту профиль бойца: набор показателей, приведённых к единым
 * единицам измерения. Класс намеренно не зависит от Laravel и Eloquent —
 * это позволяет тестировать модель изолированно и переиспользовать её
 * в бэктестинге на исторических данных.
 *
 * Единицы измерения:
 *  - все доли (точность, защита) — в диапазоне 0..1;
 *  - тейкдауны и сабмишены — в среднем за 15 минут боя;
 *  - удары — за минуту.
 */
class FighterProfile
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,

        // Физика
        public readonly ?int $age = null,
        public readonly ?int $heightCm = null,
        public readonly ?int $reachCm = null,
        public readonly string $stance = 'unknown',

        // Атака
        public readonly float $takedownsPer15 = 0.0,
        public readonly float $takedownAccuracy = 0.0,
        public readonly float $sigStrikesPerMin = 0.0,
        public readonly float $strikingAccuracy = 0.0,
        public readonly float $submissionAttemptsPer15 = 0.0,
        public readonly float $finishRate = 0.0,

        // Защита
        public readonly float $takedownDefense = 0.0,
        public readonly float $strikingDefense = 0.0,
        public readonly float $sigStrikesAbsorbedPerMin = 0.0,
        public readonly float $submissionDefense = 1.0,

        // Кардио: 1.0 — спада в поздних раундах нет, 0.0 — полный провал
        public readonly float $cardioIndex = 0.5,

        // Опыт
        public readonly int $wins = 0,
        public readonly int $losses = 0,
        public readonly int $draws = 0,
        public readonly int $ufcFights = 0,
        public readonly int $fiveRoundFights = 0,
        public readonly int $titleFights = 0,

        // Разбивка побед и поражений по методам
        public readonly int $winsByKo = 0,
        public readonly int $winsBySubmission = 0,
        public readonly int $winsByDecision = 0,
        public readonly int $lossesByKo = 0,
        public readonly int $lossesBySubmission = 0,
        public readonly int $lossesByDecision = 0,

        /** Результаты последних боёв, от свежего к старому: 'win' | 'loss' | 'draw' | 'nc' */
        public readonly array $recentResults = [],

        /** Методы поражений в последних боях, от свежего к старому */
        public readonly array $recentLossMethods = [],

        // Классификация стиля: wrestler | striker | grappler | balanced | unknown
        public readonly string $style = 'unknown',

        /** Полнота данных 0..1 — сколько ключевых показателей удалось заполнить */
        public readonly float $dataCompleteness = 0.0,

        /** Средний уровень соперников в последних боях 0..1 */
        public readonly float $opponentQuality = 0.5,
    ) {}

    /** Число поражений нокаутом подряд в самых последних боях. */
    public function consecutiveKoLosses(): int
    {
        $count = 0;

        foreach ($this->recentResults as $index => $result) {
            if ($result !== 'loss') {
                break;
            }

            if (($this->recentLossMethods[$index] ?? null) !== 'ko_tko') {
                break;
            }

            $count++;
        }

        return $count;
    }

    public function totalFights(): int
    {
        return $this->wins + $this->losses + $this->draws;
    }

    /** Доля побед досрочно — по факту разбивки, а не по полю finishRate. */
    public function finishShare(): float
    {
        if ($this->wins <= 0) {
            return $this->finishRate;
        }

        return ($this->winsByKo + $this->winsBySubmission) / $this->wins;
    }

    public function isSouthpaw(): bool
    {
        return $this->stance === 'southpaw';
    }

    public function hasFiveRoundExperience(): bool
    {
        return $this->fiveRoundFights > 0;
    }

    /** Создание профиля из ассоциативного массива (удобно для тестов и бэктеста). */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? 'Боец'),
            age: isset($data['age']) ? (int) $data['age'] : null,
            heightCm: isset($data['height_cm']) ? (int) $data['height_cm'] : null,
            reachCm: isset($data['reach_cm']) ? (int) $data['reach_cm'] : null,
            stance: (string) ($data['stance'] ?? 'unknown'),
            takedownsPer15: (float) ($data['takedowns_per_15'] ?? 0),
            takedownAccuracy: (float) ($data['takedown_accuracy'] ?? 0),
            sigStrikesPerMin: (float) ($data['sig_strikes_per_min'] ?? 0),
            strikingAccuracy: (float) ($data['striking_accuracy'] ?? 0),
            submissionAttemptsPer15: (float) ($data['submission_attempts_per_15'] ?? 0),
            finishRate: (float) ($data['finish_rate'] ?? 0),
            takedownDefense: (float) ($data['takedown_defense'] ?? 0),
            strikingDefense: (float) ($data['striking_defense'] ?? 0),
            sigStrikesAbsorbedPerMin: (float) ($data['sig_strikes_absorbed_per_min'] ?? 0),
            submissionDefense: (float) ($data['submission_defense'] ?? 1),
            cardioIndex: (float) ($data['cardio_index'] ?? 0.5),
            wins: (int) ($data['wins'] ?? 0),
            losses: (int) ($data['losses'] ?? 0),
            draws: (int) ($data['draws'] ?? 0),
            ufcFights: (int) ($data['ufc_fights'] ?? 0),
            fiveRoundFights: (int) ($data['five_round_fights'] ?? 0),
            titleFights: (int) ($data['title_fights'] ?? 0),
            winsByKo: (int) ($data['wins_by_ko'] ?? 0),
            winsBySubmission: (int) ($data['wins_by_submission'] ?? 0),
            winsByDecision: (int) ($data['wins_by_decision'] ?? 0),
            lossesByKo: (int) ($data['losses_by_ko'] ?? 0),
            lossesBySubmission: (int) ($data['losses_by_submission'] ?? 0),
            lossesByDecision: (int) ($data['losses_by_decision'] ?? 0),
            recentResults: (array) ($data['recent_results'] ?? []),
            recentLossMethods: (array) ($data['recent_loss_methods'] ?? []),
            style: (string) ($data['style'] ?? 'unknown'),
            dataCompleteness: (float) ($data['data_completeness'] ?? 0),
            opponentQuality: (float) ($data['opponent_quality'] ?? 0.5),
        );
    }
}
