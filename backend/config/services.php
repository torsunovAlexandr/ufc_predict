<?php

return [
    // Агрегатор коэффициентов: https://the-odds-api.com
    'odds_api' => [
        'key' => env('ODDS_API_KEY'),
        'base_url' => env('ODDS_API_BASE_URL', 'https://api.the-odds-api.com/v4'),
        'sport' => env('ODDS_API_SPORT', 'mma_mixed_martial_arts'),
        'regions' => env('ODDS_API_REGIONS', 'eu,uk'),
        'daily_limit' => (int) env('ODDS_API_DAILY_LIMIT', 500),
    ],

    // Google Custom Search — запасной способ найти результат боя
    'google_cse' => [
        'key' => env('GOOGLE_CSE_KEY'),
        'cx' => env('GOOGLE_CSE_CX'),
        'daily_limit' => (int) env('GOOGLE_CSE_DAILY_LIMIT', 100),
    ],
];
