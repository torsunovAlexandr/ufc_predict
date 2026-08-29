<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Расписание фоновых задач
|--------------------------------------------------------------------------
| Частота подобрана под ограничения из раздела 2 ТЗ: обращение к одному
| источнику не чаще раза в 3 часа, котировки — за 1–2 дня до боя и в день боя.
*/

// Список турниров и карды боёв — раз в 6 часов
Schedule::command('ufc:sync-events --fights')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

// Карточки бойцов ближайших турниров — раз в сутки ночью
Schedule::command('ufc:sync-fighters')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Коэффициенты — четыре раза в день (бесплатная квота это выдерживает)
Schedule::command('ufc:sync-odds')
    ->cron('0 */6 * * *')
    ->withoutOverlapping();

// Прогнозы и рекомендации — после обновления котировок
Schedule::command('ufc:predict --recommend')
    ->cron('30 */6 * * *')
    ->withoutOverlapping();

// Результаты прошедших турниров и расчёт ставок — по утрам
Schedule::command('ufc:results')
    ->dailyAt('09:00')
    ->withoutOverlapping();

// Чистка кэша страниц старше 30 дней
Schedule::call(function () {
    \App\Models\ScrapeLog::where('fetched_at', '<', now()->subDays(30))->delete();
})->weekly();
