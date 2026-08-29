<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Presenter;
use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Services\Statistics\StatisticsService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(StatisticsService $statistics): JsonResponse
    {
        $upcomingEvents = Event::upcoming()
            ->withCount('fights')
            ->orderBy('starts_at')
            ->limit(3)
            ->get();

        // Сортировка по дате турнира требует join с events. Обе таблицы имеют
        // колонку `status`, поэтому все условия здесь квалифицированы именем
        // таблицы — иначе MySQL вернёт «Column 'status' is ambiguous».
        $nextFights = Fight::query()
            ->select('fights.*')
            ->join('events', 'events.id', '=', 'fights.event_id')
            ->where('fights.status', 'scheduled')
            ->where('events.status', 'scheduled')
            ->where('events.starts_at', '>=', now()->subHours(12))
            ->with(['fighter1', 'fighter2', 'event', 'currentPrediction'])
            ->orderBy('events.starts_at')
            ->orderBy('fights.bout_order')
            ->limit(8)
            ->get();

        $recommended = Bet::query()
            ->where('status', 'recommended')
            ->with('fight.fighter1', 'fight.fighter2')
            ->orderByDesc('expected_value')
            ->limit(10)
            ->get();

        $recentResults = Fight::query()
            ->where('status', 'completed')
            ->whereHas('result')
            ->with(['fighter1', 'fighter2', 'event', 'result', 'currentPrediction'])
            ->latest('updated_at')
            ->limit(6)
            ->get();

        return response()->json([
            'summary' => $statistics->summary(),
            'upcoming_events' => $upcomingEvents->map(fn (Event $e) => Presenter::event($e))->all(),
            'next_fights' => $nextFights->map(fn (Fight $f) => Presenter::fight($f))->all(),
            'recommended_bets' => $recommended->map(fn (Bet $b) => Presenter::bet($b))->all(),
            'recent_results' => $recentResults->map(fn (Fight $f) => Presenter::fight($f, true))->all(),
        ]);
    }
}
