<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_STORE', 'database'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'database' => [
            'driver' => 'database',
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'connection' => env('DB_CACHE_CONNECTION'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Классы, которые разрешено хранить в кэше
    |--------------------------------------------------------------------------
    | Начиная с Laravel 13 десериализация объектов из кэша запрещена по умолчанию
    | (защита от атак через PHP-десериализацию). Приложение кладёт в кэш только
    | массивы и скаляры — настройки, robots.txt, отметки времени, — поэтому
    | список пуст. Добавляйте сюда класс, только если действительно кэшируете
    | его объекты.
    */
    'serializable_classes' => [],
];
