<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Этот тест не проверяет ни одной функции продукта — и в этом весь смысл.
 *
 * В первой версии тесты существовали, но ни разу не выполнялись: два дефекта
 * из двенадцати сидели именно в них. Пока набор `Unit` зелёный, известно,
 * что PHPUnit запускается, автозагрузка работает, а окружение соответствует
 * тому, на которое рассчитан код.
 *
 * Набор `Unit` не поднимает приложение Laravel — наследование от базового
 * PHPUnit\Framework\TestCase здесь намеренное. Это тот же приём «ядро без
 * фреймворка», на который опирается этап 1.
 */
final class SmokeTest extends TestCase
{
    /**
     * Дефект №4 первой версии — тест, который требовал от double точности,
     * которой у него нет. Прежде чем писать математику на этапе 1, стоит
     * убедиться, что платформа именно та, на которую эта математика рассчитана:
     * 64-битные целые и стандартный IEEE 754 double.
     */
    #[Test]
    public function runtime_is_a_64_bit_ieee754_platform(): void
    {
        $this->assertSame(8, PHP_INT_SIZE, 'Ожидается 64-битная сборка PHP.');
        $this->assertSame(15, PHP_FLOAT_DIG, 'Ожидается стандартный double IEEE 754.');
        $this->assertSame(1.0, 0.1 + 0.9, 'Базовая арифметика с плавающей точкой ведёт себя не как обычно.');
    }

    #[Test]
    public function php_version_matches_the_one_the_project_is_pinned_to(): void
    {
        $this->assertGreaterThanOrEqual(
            0,
            version_compare(PHP_VERSION, '8.3.0'),
            'Laravel 13 требует PHP 8.3–8.5.'
        );

        $this->assertSame(
            '8.4',
            PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'Проект зафиксирован на PHP 8.4 (docs/stage-0.md, раздел 0.1). '
                .'Если версия другая — окружение разошлось с образом docker/php.'
        );
    }

    #[Test]
    public function required_php_extensions_are_present(): void
    {
        foreach (['pdo', 'mbstring', 'intl', 'json'] as $extension) {
            $this->assertTrue(
                extension_loaded($extension),
                "Расширение PHP «{$extension}» не загружено."
            );
        }
    }
}
