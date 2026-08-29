<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Единственный эндпоинт этапа 0.
 *
 * Его дёргают `make doctor`, сборка `stack` в CI и вы сами из браузера.
 * Он отвечает на один вопрос: приложение действительно поднялось и видит базу,
 * или мы смотрим на страницу, которую отдал nginx из кэша.
 *
 * Ответ намеренно не зависит ни от одной таблицы: на этапе 0 предметной схемы
 * ещё нет, а проверка соединения должна работать уже сейчас.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseIsReachable();

        return response()->json([
            'status' => $database ? 'ok' : 'degraded',
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'environment' => app()->environment(),
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], $database ? 200 : 503);
    }

    /**
     * Соединение проверяется самым дешёвым запросом, который понимают
     * и MySQL, и SQLite. Исключение не пробрасывается наружу: эндпоинт
     * здоровья обязан отвечать даже тогда, когда база лежит, — иначе
     * по его ответу нельзя отличить «база недоступна» от «приложение не поднялось».
     */
    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
