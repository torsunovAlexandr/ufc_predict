<?php

namespace App\Providers;

use App\Services\Betting\BankrollCalculator;
use App\Services\Betting\BankrollService;
use App\Services\Odds\BookmakerScraperProvider;
use App\Services\Odds\OddsService;
use App\Services\Odds\TheOddsApiProvider;
use App\Services\Prediction\FighterProfileBuilder;
use App\Services\Prediction\PredictionEngine;
use App\Services\Scraping\HttpFetcher;
use App\Services\Support\SettingsRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);

        // Конфигурация домена = config/ufc.php + переопределения из настроек.
        // Пока таблица настроек не создана (первый `migrate`), берём файл как есть.
        $this->app->singleton('ufc.config', function ($app) {
            try {
                if (Schema::hasTable('settings')) {
                    return $app->make(SettingsRepository::class)->domainConfig();
                }
            } catch (\Throwable) {
                // база ещё недоступна — работаем на значениях по умолчанию
            }

            return config('ufc');
        });

        $this->app->singleton(PredictionEngine::class, fn ($app) => new PredictionEngine($app->make('ufc.config')));

        $this->app->singleton(FighterProfileBuilder::class, fn ($app) => new FighterProfileBuilder(
            $app->make('ufc.config')['form']
        ));

        $this->app->singleton(BankrollCalculator::class, fn ($app) => new BankrollCalculator(
            $app->make('ufc.config')['bankroll']
        ));

        $this->app->singleton(BankrollService::class, fn ($app) => new BankrollService(
            $app->make(SettingsRepository::class)
        ));

        $this->app->singleton(HttpFetcher::class, fn ($app) => new HttpFetcher(config('ufc.sources')));

        // Поставщики коэффициентов — в порядке приоритета
        $this->app->singleton(OddsService::class, fn ($app) => new OddsService([
            $app->make(TheOddsApiProvider::class),
            $app->make(BookmakerScraperProvider::class),
        ]));
    }

    public function boot(): void
    {
        //
    }
}
