<?php

namespace App\Console\Commands;

use App\Services\Statistics\BacktestService;
use Illuminate\Console\Command;

class BacktestCommand extends Command
{
    protected $signature = 'ufc:backtest
                            {--from= : Дата начала периода (ГГГГ-ММ-ДД)}
                            {--to= : Дата конца периода}
                            {--bankroll=10000 : Стартовый банк для симуляции}
                            {--store : Сохранить смоделированные ставки в БД с пометкой benchmark}';

    protected $description = 'Прогон стратегии на исторических данных';

    public function handle(BacktestService $backtest): int
    {
        $this->info('Запускаю бэктест…');

        $report = $backtest->run(
            $this->option('from'),
            $this->option('to'),
            (float) $this->option('bankroll'),
            (bool) $this->option('store'),
        );

        if ($report['fights_analysed'] === 0) {
            $this->warn('Нет исторических боёв с результатами и котировками. Сначала наполните базу.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Показатель', 'Значение'], [
            ['Период', $report['period']['from'].' — '.$report['period']['to']],
            ['Боёв проанализировано', $report['fights_analysed']],
            ['Точность прогнозов', $report['prediction_accuracy'].'% ('.$report['predictions_checked'].' боёв)'],
            ['Ставок сделано', $report['bets']],
            ['Выигрышных', $report['wins'].' ('.$report['win_rate'].'%)'],
            ['Поставлено всего', $report['staked'].' ₽'],
            ['Профит', $report['profit'].' ₽'],
            ['ROI', $report['roi'].'%'],
            ['Банк: старт → финиш', $report['starting_bankroll'].' → '.$report['final_bankroll'].' ₽'],
            ['Максимальная просадка', $report['max_drawdown'].'%'],
        ]);

        return self::SUCCESS;
    }
}
