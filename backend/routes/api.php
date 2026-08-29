<?php

use App\Http\Controllers\Api\BacktestController;
use App\Http\Controllers\Api\BetController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FighterController;
use App\Http\Controllers\Api\FightController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API
|--------------------------------------------------------------------------
| Приложение персональное и работает без авторизации (см. README).
| Если понадобится выставить его наружу — достаточно навесить на группу
| middleware 'auth.basic' или собственный токен.
*/

Route::get('dashboard', [DashboardController::class, 'index']);

// Турниры и бои
Route::get('events', [EventController::class, 'index']);
Route::get('events/{event}', [EventController::class, 'show']);
Route::post('events/{event}/predict', [EventController::class, 'predict']);
Route::post('events/{event}/odds', [EventController::class, 'refreshOdds']);
Route::post('events/{event}/results', [EventController::class, 'fetchResults']);

Route::get('fights/{fight}', [FightController::class, 'show']);
Route::post('fights/{fight}/predict', [FightController::class, 'predict']);
Route::post('fights/{fight}/odds', [FightController::class, 'storeOdds']);
Route::post('fights/{fight}/result', [FightController::class, 'storeResult']);
Route::get('fights/{fight}/search-result', [FightController::class, 'searchResult']);

// Бойцы
Route::get('fighters', [FighterController::class, 'index']);
Route::get('fighters/{fighter}', [FighterController::class, 'show']);
Route::post('fighters/{fighter}/refresh', [FighterController::class, 'refresh']);

// Ставки
Route::get('bets', [BetController::class, 'index']);
Route::post('bets/place', [BetController::class, 'place']);
Route::post('bets/{bet}/skip', [BetController::class, 'skip']);
Route::post('bets/{bet}/settle', [BetController::class, 'settle']);

// Статистика
Route::get('statistics/summary', [StatisticsController::class, 'summary']);
Route::get('statistics/bankroll', [StatisticsController::class, 'bankroll']);
Route::get('statistics/benchmarks', [StatisticsController::class, 'benchmarks']);
Route::get('statistics/accuracy', [StatisticsController::class, 'accuracy']);
Route::get('statistics/history', [StatisticsController::class, 'history']);

// Настройки
Route::get('settings', [SettingsController::class, 'index']);
Route::put('settings', [SettingsController::class, 'update']);
Route::post('settings/bankroll/reset', [SettingsController::class, 'resetBankroll']);

// Служебные операции обновления данных
Route::post('sync/events', [SyncController::class, 'events']);
Route::post('sync/fighters', [SyncController::class, 'fighters']);
Route::post('sync/odds', [SyncController::class, 'odds']);
Route::get('sync/status', [SyncController::class, 'status']);

// Бэктест
Route::post('backtest', [BacktestController::class, 'run']);
