<?php

namespace App\Services\Prediction;

/**
 * Формирование текстового объяснения прогноза на русском языке (раздел 4.4 ТЗ).
 *
 * Берутся три самых весомых фактора в пользу фаворита, один самый весомый
 * контраргумент и все сработавшие экспертные правила.
 */
class ExplanationBuilder
{
    /** Как называть показатель в предложении и как форматировать значения. */
    private const PHRASES = [
        'takedowns_offense' => ['в борьбе', '%.1f тейкдауна за бой против %.1f'],
        'takedown_defense' => ['в защите от тейкдаунов', '%d%% против %d%%', 'percent'],
        'striking_offense' => ['в ударной технике', '%.2f значимых удара в минуту против %.2f'],
        'striking_defense' => ['в защите в стойке', '%d%% против %d%%', 'percent'],
        'submission_offense' => ['в борьбе за сабмишены', '%.1f попытки за бой против %.1f'],
        'submission_defense' => ['в защите от сабмишенов', '%d%% против %d%%', 'percent'],
        'cardio' => ['по кардио', 'индекс выносливости %.2f против %.2f'],
        'physical_experience' => ['по физике и опыту', 'размах рук %d см против %d см', 'int'],
    ];

    public function build(
        FighterProfile $f1,
        FighterProfile $f2,
        FightContext $context,
        PredictionResult|array $data
    ): string {
        $factors = is_array($data) ? $data['factors'] : $data->factors;
        $rules = is_array($data) ? $data['applied_rules'] : $data->appliedRules;
        $p1 = is_array($data) ? $data['probability_fighter1'] : $data->probabilityFighter1;
        $completeness = is_array($data) ? $data['data_completeness'] : $data->dataCompleteness;

        $favouriteIsFirst = $p1 >= 0.5;
        $favourite = $favouriteIsFirst ? $f1 : $f2;
        $underdog = $favouriteIsFirst ? $f2 : $f1;
        $favouriteProbability = $favouriteIsFirst ? $p1 : 1 - $p1;

        // Фактор «в пользу фаворита» — тот, у которого вклад того же знака
        $sign = $favouriteIsFirst ? 1 : -1;

        $pro = array_values(array_filter($factors, fn ($f) => $f['contribution'] * $sign > 0.005));
        $contra = array_values(array_filter($factors, fn ($f) => $f['contribution'] * $sign < -0.005));

        usort($pro, fn ($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));
        usort($contra, fn ($a, $b) => abs($b['contribution']) <=> abs($a['contribution']));

        $sentences = [];

        // Первое предложение — главные преимущества фаворита
        if ($pro) {
            $parts = [];
            foreach (array_slice($pro, 0, 3) as $factor) {
                $parts[] = $this->describeFactor($factor, $favouriteIsFirst);
            }

            $sentences[] = sprintf(
                '%s имеет преимущество %s.',
                $favourite->name,
                $this->joinRu($parts)
            );
        } else {
            $sentences[] = sprintf(
                'Бойцы близки по статистике, явного преимущества нет ни у кого — %s выглядит чуть предпочтительнее.',
                $favourite->name
            );
        }

        // Второе предложение — сильная сторона аутсайдера
        if ($contra) {
            $best = $contra[0];
            $sentences[] = sprintf(
                'Однако %s превосходит соперника %s (%s).',
                $underdog->name,
                self::PHRASES[$best['key']][0] ?? $best['label'],
                $this->formatValues($best, ! $favouriteIsFirst)
            );
        }

        // Экспертные правила
        $ruleSentences = [];
        foreach ($rules as $rule) {
            $ruleSentences[] = $rule['description'];
        }

        if ($ruleSentences) {
            $sentences[] = 'Экспертные правила: '.implode(' ', array_slice($ruleSentences, 0, 4));
        }

        // Контекст боя
        $contextParts = [];
        if ($context->isFiveRound()) {
            $contextParts[] = 'бой рассчитан на пять раундов, поэтому вес кардио и опыта длинных боёв повышен';
        }
        if ($context->isTitleFight) {
            $contextParts[] = 'бой титульный';
        }
        if ($context->altitudeMeters !== null && $context->altitudeMeters >= 1000) {
            $contextParts[] = sprintf('турнир проходит на высоте %d м', $context->altitudeMeters);
        }
        if ($context->hasHeadToHead()) {
            $contextParts[] = 'учтён результат прошлой очной встречи';
        }
        if ($contextParts) {
            $sentences[] = 'Контекст: '.implode('; ', $contextParts).'.';
        }

        // Итог
        $sentences[] = sprintf(
            'Итоговая вероятность победы: %s — %d%%.',
            $favourite->name,
            (int) round($favouriteProbability * 100)
        );

        if ($completeness < 0.6) {
            $sentences[] = sprintf(
                'Внимание: статистика заполнена лишь на %d%%, к прогнозу стоит относиться осторожно.',
                (int) round($completeness * 100)
            );
        }

        return implode(' ', $sentences);
    }

    /** @param array<string, mixed> $factor */
    private function describeFactor(array $factor, bool $favouriteIsFirst): string
    {
        $phrase = self::PHRASES[$factor['key']][0] ?? mb_strtolower((string) $factor['label']);

        return $phrase.' ('.$this->formatValues($factor, $favouriteIsFirst).')';
    }

    /**
     * Значения показателя в порядке «сначала тот, о ком речь».
     *
     * @param  array<string, mixed>  $factor
     */
    private function formatValues(array $factor, bool $firstIsSubject): string
    {
        $definition = self::PHRASES[$factor['key']] ?? null;

        if (! $definition) {
            return sprintf('%.2f против %.2f', $factor['values'][0], $factor['values'][1]);
        }

        [$a, $b] = $firstIsSubject
            ? [$factor['values'][0], $factor['values'][1]]
            : [$factor['values'][1], $factor['values'][0]];

        $format = $definition[1];
        $type = $definition[2] ?? 'float';

        return match ($type) {
            'percent' => sprintf($format, (int) round($a * 100), (int) round($b * 100)),
            'int' => sprintf($format, (int) round($a), (int) round($b)),
            default => sprintf($format, $a, $b),
        };
    }

    /** @param array<int, string> $parts */
    private function joinRu(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' и '.$last;
    }
}
