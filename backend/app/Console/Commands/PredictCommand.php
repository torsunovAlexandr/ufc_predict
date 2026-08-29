<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Fight;
use App\Services\Betting\BettingService;
use App\Services\Prediction\PredictionService;
use Illuminate\Console\Command;

class PredictCommand extends Command
{
    protected $signature = 'ufc:predict
                            {event? : ID или slug турнира}
                            {--recommend : Сразу сформировать рекомендации по ставкам}
                            {--place : Разместить рекомендованные ставки (списать с банка)}';

    protected $description = 'Рассчитать прогнозы по предстоящим боям';

    public function handle(PredictionService $predictions, BettingService $betting): int
    {
        $fights = $this->resolveFights();

        if ($fights->isEmpty()) {
            $this->warn('Нет боёв для расчёта.');

            return self::SUCCESS;
        }

        $this->info("Рассчитываю прогнозы: {$fights->count()} боёв");

        $rows = [];

        foreach ($fights as $fight) {
            $prediction = $predictions->predictAndStore($fight);

            $bets = $this->option('recommend') || $this->option('place')
                ? $betting->buildRecommendations($fight, $prediction)
                : [];

            if ($this->option('place') && $bets) {
                $betting->placeAllForFight($fight);
            }

            $rows[] = [
                $fight->title(),
                round($prediction->probability_fighter1 * 100).'%',
                round($prediction->probability_fighter2 * 100).'%',
                round($prediction->confidence * 100).'%',
                count($bets),
            ];
        }

        $this->table(['Бой', 'P1', 'P2', 'Уверенность', 'Ставок'], $rows);

        return self::SUCCESS;
    }

    private function resolveFights()
    {
        $key = $this->argument('event');

        if ($key) {
            $event = Event::where('id', $key)->orWhere('slug', $key)->firstOrFail();

            return $event->fights()->where('status', 'scheduled')->with('fighter1', 'fighter2', 'event')->get();
        }

        return Fight::query()
            ->where('status', 'scheduled')
            ->whereHas('event', fn ($q) => $q->upcoming())
            ->with('fighter1', 'fighter2', 'event')
            ->get();
    }
}
