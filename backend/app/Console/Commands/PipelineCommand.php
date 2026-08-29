<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PipelineCommand extends Command
{
    protected $signature = 'ufc:pipeline {--force : Игнорировать кэш}';

    protected $description = 'Полный цикл: турниры → бойцы → коэффициенты → прогнозы';

    public function handle(): int
    {
        $force = $this->option('force') ? ['--force' => true] : [];

        $this->components->task('Турниры и карды боёв', function () use ($force) {
            return $this->call('ufc:sync-events', $force + ['--fights' => true]) === self::SUCCESS;
        });

        $this->components->task('Карточки бойцов', function () use ($force) {
            return $this->call('ufc:sync-fighters', $force) === self::SUCCESS;
        });

        $this->components->task('Коэффициенты', function () {
            return $this->call('ufc:sync-odds') === self::SUCCESS;
        });

        $this->components->task('Прогнозы и рекомендации', function () {
            return $this->call('ufc:predict', ['--recommend' => true]) === self::SUCCESS;
        });

        return self::SUCCESS;
    }
}
