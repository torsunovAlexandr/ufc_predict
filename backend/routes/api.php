<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Маршруты API
|--------------------------------------------------------------------------
|
| На этапе 0 маршрут ровно один. Предметные маршруты появляются с этапа 5,
| и до этого файл остаётся таким же коротким.
|
| Префикс /api и группа middleware 'api' навешиваются в bootstrap/app.php,
| поэтому здесь пути пишутся без префикса.
|
*/

Route::get('/health', HealthController::class)->name('api.health');
