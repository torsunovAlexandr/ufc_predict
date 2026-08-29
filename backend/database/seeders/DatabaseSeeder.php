<?php

namespace Database\Seeders;

use App\Services\Betting\BankrollService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Стартовый банкролл
        app(BankrollService::class)->initialise();

        // Демонстрационные данные — чтобы интерфейс был живым
        // до первой успешной загрузки с ufc.com
        if (config('app.env') !== 'production' || env('SEED_DEMO_DATA', true)) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
