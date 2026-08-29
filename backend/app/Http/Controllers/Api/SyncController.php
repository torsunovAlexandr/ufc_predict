<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Fighter;
use App\Models\ScrapeLog;
use App\Services\Odds\OddsService;
use App\Services\Scraping\HttpFetcher;
use App\Services\Scraping\UfcEventScraper;
use App\Services\Scraping\UfcFighterScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function events(Request $request, UfcEventScraper $scraper): JsonResponse
    {
        $force = $request->boolean('force');
        $stats = $scraper->syncEvents($force);

        $fights = 0;
        foreach (Event::upcoming()->orderBy('starts_at')->limit(5)->get() as $event) {
            $fights += count($scraper->syncFights($event, $force));
        }

        return response()->json([
            'message' => "Турниров новых: {$stats['created']}, обновлено: {$stats['updated']}. Боёв загружено: {$fights}.",
            'result' => $stats + ['fights' => $fights],
        ]);
    }

    public function fighters(Request $request, UfcFighterScraper $scraper): JsonResponse
    {
        $ids = Event::upcoming()
            ->with('fights')
            ->get()
            ->flatMap(fn (Event $e) => $e->fights->flatMap(fn ($f) => [$f->fighter1_id, $f->fighter2_id]))
            ->unique();

        $updated = 0;
        $force = $request->boolean('force');

        foreach (Fighter::whereIn('id', $ids)->get() as $fighter) {
            if ($force || ! $fighter->last_scraped_at || $fighter->last_scraped_at->lt(now()->subDay())) {
                $updated += $scraper->sync($fighter, $force) ? 1 : 0;
            }
        }

        return response()->json([
            'message' => "Обновлено карточек бойцов: {$updated}",
            'result' => ['updated' => $updated, 'total' => $ids->count()],
        ]);
    }

    public function odds(OddsService $odds): JsonResponse
    {
        $stored = 0;
        $events = Event::upcoming()->orderBy('starts_at')->limit(3)->get();

        foreach ($events as $event) {
            $stored += $odds->refreshForEvent($event)['stored'];
        }

        return response()->json([
            'message' => "Загружено котировок: {$stored}",
            'result' => ['stored' => $stored, 'events' => $events->count()],
        ]);
    }

    /** Состояние источников: когда последний раз обращались, сколько запросов за сутки. */
    public function status(HttpFetcher $fetcher): JsonResponse
    {
        $sources = ['ufc', 'sherdog', 'results', 'the_odds_api', 'google_cse'];
        $status = [];

        foreach ($sources as $source) {
            $last = ScrapeLog::where('source', $source)->latest('fetched_at')->first();

            $status[] = [
                'source' => $source,
                'last_fetch' => $last?->fetched_at?->toIso8601String(),
                'last_status' => $last?->status,
                'requests_today' => $fetcher->requestsToday($source),
                'can_refresh' => $fetcher->canRefreshSource($source),
            ];
        }

        return response()->json(['data' => $status]);
    }
}
