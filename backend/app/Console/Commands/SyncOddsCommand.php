<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Odds\OddsService;
use Illuminate\Console\Command;

class SyncOddsCommand extends Command
{
    protected $signature = 'ufc:sync-odds {event? : ID или slug турнира}';

    protected $description = 'Загрузить букмекерские коэффициенты по предстоящим турнирам';

    public function handle(OddsService $odds): int
    {
        $events = $this->resolveEvents();

        if ($events->isEmpty()) {
            $this->warn('Нет предстоящих турниров.');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $result = $odds->refreshForEvent($event);

            $this->line(sprintf(
                '  %s: сохранено котировок %d по %d боям (источник: %s)',
                $event->name,
                $result['stored'],
                $result['matched'],
                $result['provider'] ?? 'нет'
            ));

            if ($result['unmatched']) {
                $this->warn('    Не удалось сопоставить: '.implode('; ', array_slice($result['unmatched'], 0, 5)));
            }
        }

        return self::SUCCESS;
    }

    private function resolveEvents()
    {
        $key = $this->argument('event');

        if ($key) {
            return Event::where('id', $key)->orWhere('slug', $key)->get();
        }

        $days = (int) config('ufc.odds.refresh_before_event_days', 2);

        return Event::upcoming()
            ->where('starts_at', '<=', now()->addDays(max($days, 7)))
            ->orderBy('starts_at')
            ->get();
    }
}
