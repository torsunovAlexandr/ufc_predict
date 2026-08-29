<?php

namespace App\Services\Prediction;

/**
 * Математические примитивы модели. Вынесены отдельно, чтобы покрыть тестами
 * и не дублировать формулы в разных местах.
 */
final class Math
{
    public const EPSILON = 1e-9;

    /**
     * Относительное преимущество первого значения над вторым.
     * advantage = (v1 - v2) / (v1 + v2 + epsilon), результат в [-1, 1].
     *
     * Если оба значения нулевые (нет данных) — преимущества нет.
     */
    public static function advantage(float $v1, float $v2, float $epsilon = 1e-6): float
    {
        $sum = $v1 + $v2;

        if (abs($sum) < self::EPSILON) {
            return 0.0;
        }

        // Отрицательные значения формулой не предусмотрены — сдвигаем в неотрицательную область
        if ($v1 < 0 || $v2 < 0) {
            $shift = abs(min($v1, $v2));
            $v1 += $shift;
            $v2 += $shift;
            $sum = $v1 + $v2;
        }

        return self::clamp(($v1 - $v2) / ($sum + $epsilon), -1.0, 1.0);
    }

    /**
     * Логистическая функция в устойчивой форме.
     *
     * Для отрицательных аргументов считается как exp(x) / (1 + exp(x)):
     * наивная 1 / (1 + exp(-x)) требует exp(-x), который при x < -709
     * переполняется до бесконечности и обнуляет весь хвост. Эта форма
     * сохраняет значения примерно до x = -745.
     *
     * За этой границей результат насыщается: sigmoid(-800) равен ровно 0.0,
     * sigmoid(800) — ровно 1.0. Это предел двойной точности, а не ошибка;
     * модель работает в диапазоне [-3.5, 3.5] и до насыщения не доходит.
     */
    public static function sigmoid(float $x): float
    {
        if ($x >= 0) {
            return 1.0 / (1.0 + exp(-$x));
        }

        $e = exp($x);

        return $e / (1.0 + $e);
    }

    /** Обратная к сигмоиде. */
    public static function logit(float $p): float
    {
        $p = self::clamp($p, 1e-9, 1 - 1e-9);

        return log($p / (1 - $p));
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /** Безопасное деление: вернёт $default вместо деления на ноль. */
    public static function divide(float $numerator, float $denominator, float $default = 0.0): float
    {
        return abs($denominator) < self::EPSILON ? $default : $numerator / $denominator;
    }

    /**
     * Убирает маржу букмекера из набора коэффициентов и возвращает
     * «честные» вероятности (метод пропорционального нормирования).
     *
     * @param  array<string, float>  $prices  десятичные коэффициенты
     * @return array<string, float>
     */
    public static function removeVig(array $prices): array
    {
        $implied = [];
        foreach ($prices as $key => $price) {
            if ($price > 0) {
                $implied[$key] = 1 / $price;
            }
        }

        $overround = array_sum($implied);

        if ($overround <= 0) {
            return [];
        }

        return array_map(fn (float $p): float => $p / $overround, $implied);
    }
}
