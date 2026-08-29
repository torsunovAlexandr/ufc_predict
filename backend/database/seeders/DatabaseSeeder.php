<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Пусто намеренно.
 *
 * На этапе 0 предметных таблиц нет, наполнять нечего. Класс оставлен, потому что
 * entrypoint контейнера вызывает `db:seed` при первом запуске, и отсутствие
 * файла превратило бы штатный запуск в ошибку.
 *
 * Стартовые данные появятся на этапе 2 вместе со схемой.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
