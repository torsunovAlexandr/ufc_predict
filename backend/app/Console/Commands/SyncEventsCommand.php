<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Scraping\UfcEventScraper;
use Illuminate\Console\Command;

class SyncEventsCommand extends Command
{
    protected $signature = 'ufc:sync-events
                            {--force : Игнорировать кэш страниц}
                            {--fights : Сразу загрузить карды предстоящих турниров}';

    protected $description = 'Загрузить список турниров UFC и, при необходимости, карты боёв';

    public function handle(UfcEventScraper $scraper): int
    {
        $this->info('Загружаю список турниров с ufc.com…');

        $stats = $scraper->syncEvents((bool) $this->option('force'));

        $this->line("  Новых турниров: {$stats['created']}, обновлено: {$stats['updated']}");

        if ($stats['created'] + $stats['updated'] === 0) {
            $this->warn('Ничего не найдено. Возможно, изменилась вёрстка ufc.com — проверьте селекторы в UfcEventScraper.');

            return self::FAILURE;
        }

        if ($this->option('fights')) {
            $events = Event::upcoming()->orderBy('starts_at')->get();

            foreach ($events as $event) {
                $fights = $scraper->syncFights($event, (bool) $this->option('force'));
                $this->line("  {$event->name}: боёв загружено — ".count($fights));
            }
        }

        return self::SUCCESS;
    }
}
