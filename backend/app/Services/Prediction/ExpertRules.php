<?php

namespace App\Services\Prediction;

/**
 * Шаг 3 гибридной модели — экспертные правила.
 *
 * Каждое правило возвращает корректировку в «пунктах вероятности» в пользу
 * первого бойца (положительное значение) или второго (отрицательное).
 * Корректировки суммируются и применяются к вероятности, полученной
 * из сигмоиды: так формулировка ТЗ («вероятность повышается на 5–10%»)
 * означает ровно то, что написано, и результат гарантированно
 * остаётся в допустимых границах.
 */
class ExpertRules
{
    /** @param array<string, array<string, mixed>> $rules */
    public function __construct(private readonly array $rules) {}

    /**
     * @return array<int, array{key:string, label:string, adjustment:float, target:int, description:string}>
     */
    public function apply(FighterProfile $f1, FighterProfile $f2, FightContext $context): array
    {
        $applied = [];

        foreach ([[$f1, $f2, 1], [$f2, $f1, 2]] as [$self, $rival, $side]) {
            $sign = $side === 1 ? 1.0 : -1.0;

            foreach ($this->evaluateForFighter($self, $rival, $context, $side) as $hit) {
                $hit['adjustment'] *= $sign;
                $applied[] = $hit;
            }
        }

        // Правила, симметричные по своей природе (сравнивают бойцов между собой)
        foreach ($this->evaluateSymmetric($f1, $f2, $context) as $hit) {
            $applied[] = $hit;
        }

        return $applied;
    }

    /**
     * @return array<int, array{key:string,label:string,adjustment:float,target:int,description:string}>
     */
    private function evaluateForFighter(
        FighterProfile $self,
        FighterProfile $rival,
        FightContext $context,
        int $side
    ): array {
        $hits = [];

        // Борец против бойца со слабой защитой от тейкдаунов
        $rule = $this->rule('wrestler_vs_weak_td_defense');
        if ($rule
            && $self->takedownsPer15 > (float) $rule['min_takedowns']
            && $rival->takedownDefense > 0
            && $rival->takedownDefense < (float) $rule['max_opponent_td_defense']
            && $rival->style !== 'wrestler'
            && $rival->style !== 'grappler'
        ) {
            $hits[] = [
                'key' => 'wrestler_vs_weak_td_defense',
                'label' => 'Борец против слабой защиты от тейкдаунов',
                'adjustment' => (float) $rule['adjustment'],
                'target' => $side,
                'description' => sprintf(
                    '%s проходит в ноги %.1f раза за бой, а %s защищается лишь на %d%%.',
                    $self->name,
                    $self->takedownsPer15,
                    $rival->name,
                    (int) round($rival->takedownDefense * 100)
                ),
            ];
        }

        // Ударник с хорошей защитой от тейкдаунов против борца
        $rule = $this->rule('striker_vs_wrestler');
        if ($rule
            && $self->style === 'striker'
            && $self->takedownDefense > (float) $rule['min_td_defense']
            && $rival->takedownsPer15 >= (float) $rule['opponent_min_takedowns']
        ) {
            $hits[] = [
                'key' => 'striker_vs_wrestler',
                'label' => 'Ударник нейтрализует борьбу',
                'adjustment' => (float) $rule['adjustment'],
                'target' => $side,
                'description' => sprintf(
                    'Защита от тейкдаунов у %s — %d%%, что обесценивает главное оружие соперника.',
                    $self->name,
                    (int) round($self->takedownDefense * 100)
                ),
            ];
        }

        // Два поражения нокаутом подряд
        $rule = $this->rule('recent_ko_losses');
        if ($rule && $self->consecutiveKoLosses() >= (int) $rule['losses_in_a_row']) {
            $hits[] = [
                'key' => 'recent_ko_losses',
                'label' => 'Серия поражений нокаутом',
                'adjustment' => (float) $rule['adjustment'],
                'target' => $side,
                'description' => sprintf(
                    '%s проиграл нокаутом %d боя подряд — вопрос к состоянию.',
                    $self->name,
                    $self->consecutiveKoLosses()
                ),
            ];
        }

        // Нет опыта пятираундовых боёв
        $rule = $this->rule('no_five_round_experience');
        if ($rule && $context->isFiveRound() && ! $self->hasFiveRoundExperience()) {
            $hits[] = [
                'key' => 'no_five_round_experience',
                'label' => 'Нет опыта пяти раундов',
                'adjustment' => (float) $rule['adjustment'],
                'target' => $side,
                'description' => sprintf('%s ни разу не проводил бой в пять раундов.', $self->name),
            ];
        }

        return $hits;
    }

    /**
     * @return array<int, array{key:string,label:string,adjustment:float,target:int,description:string}>
     */
    private function evaluateSymmetric(FighterProfile $f1, FighterProfile $f2, FightContext $context): array
    {
        $hits = [];

        // Левша против правши
        $rule = $this->rule('southpaw_advantage');
        if ($rule && $f1->isSouthpaw() !== $f2->isSouthpaw()) {
            $southpawIsFirst = $f1->isSouthpaw();
            $southpaw = $southpawIsFirst ? $f1 : $f2;
            $orthodox = $southpawIsFirst ? $f2 : $f1;

            // Преимущество получает левша, но только если соперник — классический правша
            if ($orthodox->stance === 'orthodox') {
                $hits[] = [
                    'key' => 'southpaw_advantage',
                    'label' => 'Преимущество левши',
                    'adjustment' => (float) $rule['adjustment'] * ($southpawIsFirst ? 1 : -1),
                    'target' => $southpawIsFirst ? 1 : 2,
                    'description' => sprintf('%s — левша, что неудобно для правши.', $southpaw->name),
                ];
            }
        }

        // Возраст: разница более 5 лет в пользу молодого (если ему до 35)
        $rule = $this->rule('age_advantage');
        if ($rule && $f1->age !== null && $f2->age !== null) {
            $gap = abs($f1->age - $f2->age);

            if ($gap > (int) $rule['min_years_gap']) {
                $youngerIsFirst = $f1->age < $f2->age;
                $younger = $youngerIsFirst ? $f1 : $f2;

                if ($younger->age <= (int) $rule['max_age_for_bonus']) {
                    $hits[] = [
                        'key' => 'age_advantage',
                        'label' => 'Преимущество в возрасте',
                        'adjustment' => (float) $rule['adjustment'] * ($youngerIsFirst ? 1 : -1),
                        'target' => $youngerIsFirst ? 1 : 2,
                        'description' => sprintf(
                            '%s моложе на %d лет (%d против %d).',
                            $younger->name,
                            $gap,
                            $younger->age,
                            $youngerIsFirst ? $f2->age : $f1->age
                        ),
                    ];
                }
            }
        }

        // Высота над уровнем моря — работает в пользу более выносливого
        $rule = $this->rule('altitude');
        if ($rule
            && $context->altitudeMeters !== null
            && $context->altitudeMeters >= (int) $rule['threshold_meters']
        ) {
            $cardioGap = $f1->cardioIndex - $f2->cardioIndex;

            if (abs($cardioGap) > 0.05) {
                $betterIsFirst = $cardioGap > 0;
                $hits[] = [
                    'key' => 'altitude',
                    'label' => 'Высота над уровнем моря',
                    'adjustment' => (float) $rule['adjustment'] * ($betterIsFirst ? 1 : -1),
                    'target' => $betterIsFirst ? 1 : 2,
                    'description' => sprintf(
                        'Турнир проходит на высоте %d м — выигрывает боец с лучшим кардио (%s).',
                        $context->altitudeMeters,
                        $betterIsFirst ? $f1->name : $f2->name
                    ),
                ];
            }
        }

        // Титульный бой — опыт титульников
        $rule = $this->rule('title_fight');
        if ($rule && $context->isTitleFight && $f1->titleFights !== $f2->titleFights) {
            $moreExperiencedIsFirst = $f1->titleFights > $f2->titleFights;
            $hits[] = [
                'key' => 'title_fight',
                'label' => 'Опыт титульных боёв',
                'adjustment' => (float) $rule['adjustment'] * ($moreExperiencedIsFirst ? 1 : -1),
                'target' => $moreExperiencedIsFirst ? 1 : 2,
                'description' => sprintf(
                    'Титульных боёв в активе: %d против %d.',
                    $f1->titleFights,
                    $f2->titleFights
                ),
            ];
        }

        return $hits;
    }

    /** @return array<string, mixed>|null */
    private function rule(string $key): ?array
    {
        $rule = $this->rules[$key] ?? null;

        if (! $rule || ($rule['enabled'] ?? true) === false) {
            return null;
        }

        return $rule;
    }

    /**
     * Итоговая корректировка вероятности первого бойца.
     *
     * @param  array<int, array{adjustment: float}>  $applied
     */
    public function netAdjustment(array $applied): float
    {
        return array_sum(array_column($applied, 'adjustment'));
    }
}
