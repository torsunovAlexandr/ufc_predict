<?php

use Illuminate\Support\Facades\Route;

// Фронтенд собирается отдельно (Vite) и раздаётся nginx.
// Бекенд отвечает только за API и служебные маршруты.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api'),
    'health' => url('/up'),
]));
