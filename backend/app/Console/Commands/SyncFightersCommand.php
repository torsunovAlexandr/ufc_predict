<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Fighter;
use App\Services\Scraping\UfcFighterScraper;
use Illuminate\Console\Command;

class SyncFightersCommand extends Command
{
    protected $signature = 'ufc:sync-fighters
                            {--force : Игнорировать кэш страниц}
                            {--all : Обновить всех бойцов в базе, а не только участников ближайших турниров}
                            {--stale=7 : Обновлять карточки, которым больше указанного числа дней}';

    protected $description = 'Обновить карточки бойцов с ufc.com';

    public function handle(UfcFighterScraper $scraper): int
    {
        $fighters = $this->option('all')
            ? Fighter::query()->get()
            : $this->upcomingFighters();

        $staleDays = (int) $this->option('stale');
        $force = (bool) $this->option('force');

        $fighters = $fighters->filter(
            fn (Fighter $f) => $force || ! $f->last_scraped_at || $f->last_scraped_at->lt(now()->subDays($staleDays))
        );

        if ($fighters->isEmpty()) {
            $this->info('Все карточки уже актуальны.');

            return self::SUCCESS;
        }

        $this->info("Обновляю карточек: {$fighters->count()}");
        $bar = $this->output->createProgressBar($fighters->count());
        $updated = 0;

        foreach ($fighters as $fighter) {
            if ($scraper->sync($fighter, $force)) {
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->line("  Успешно обновлено: {$updated} из {$fighters->count()}");

        return self::SUCCESS;
    }

    private function upcomingFighters()
    {
        $ids = Event::upcoming()
            ->with('fights')
            ->get()
            ->flatMap(fn (Event $e) => $e->fights->flatMap(fn ($f) => [$f->fighter1_id, $f->fighter2_id]))
            ->unique();

        return Fighter::whereIn('id', $ids)->get();
    }
}
