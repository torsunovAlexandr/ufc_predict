<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Betting\BankrollService;
use App\Services\Scraping\ResultsScraper;
use Illuminate\Console\Command;

class FetchResultsCommand extends Command
{
    protected $signature = 'ufc:results
                            {event? : ID или slug турнира}
                            {--settle=1 : Рассчитать ставки по полученным результатам}';

    protected $description = 'Получить фактические результаты боёв и рассчитать ставки';

    public function handle(ResultsScraper $scraper, BankrollService $bankroll): int
    {
        $events = $this->resolveEvents();

        if ($events->isEmpty()) {
            $this->warn('Нет завершившихся турниров без результатов.');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $this->info("Ищу результаты: {$event->name}");

            $outcome = $scraper->syncEventResults($event);

            $this->line("  Найдено результатов: {$outcome['found']} (источник: ".($outcome['source'] ?? 'нет').')');

            if ($outcome['missing']) {
                $this->warn('  Без результата: '.implode('; ', $outcome['missing']));
                $this->line('  Их можно ввести вручную через интерфейс или POST /api/fights/{id}/result');
            }

            if ($this->option('settle')) {
                $settled = 0;

                foreach ($event->fights()->with('result')->get() as $fight) {
                    $settled += count($bankroll->settleFight($fight));
                }

                $this->line("  Рассчитано ставок: {$settled}. Банкролл: ".round($bankroll->current(), 2).' ₽');
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

        return Event::query()
            ->where('starts_at', '<', now())
            ->where('starts_at', '>', now()->subDays(30))
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('starts_at')
            ->get()
            ->filter(fn (Event $e) => $e->fights()->whereDoesntHave('result')->exists());
    }
}
