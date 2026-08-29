<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Presenter;
use App\Models\Fight;
use App\Services\Statistics\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function summary(Request $request, StatisticsService $statistics): JsonResponse
    {
        return response()->json([
            'data' => $statistics->summary($request->only(['from', 'to', 'event_id', 'fighter_id'])),
        ]);
    }

    public function bankroll(Request $request, StatisticsService $statistics): JsonResponse
    {
        return response()->json([
            'data' => $statistics->bankrollChart($request->query('from'), $request->query('to')),
        ]);
    }

    public function benchmarks(StatisticsService $statistics): JsonResponse
    {
        return response()->json(['data' => $statistics->benchmarks()]);
    }

    public function accuracy(StatisticsService $statistics): JsonResponse
    {
        return response()->json(['data' => $statistics->accuracy()]);
    }

    /** История прошедших боёв: прогноз против факта. */
    public function history(Request $request): JsonResponse
    {
        // Запрос идёт с join на events ради сортировки по дате турнира,
        // поэтому все колонки квалифицированы: `status` есть в обеих таблицах.
        $query = Fight::query()
            ->select('fights.*')
            ->join('events', 'events.id', '=', 'fights.event_id')
            ->where('fights.status', 'completed')
            ->whereHas('result')
            ->with(['fighter1', 'fighter2', 'event', 'result', 'currentPrediction']);

        if ($eventId = $request->query('event_id')) {
            $query->where('fights.event_id', $eventId);
        }

        if ($fighterId = $request->query('fighter_id')) {
            $query->where(function ($q) use ($fighterId) {
                $q->where('fights.fighter1_id', $fighterId)
                    ->orWhere('fights.fighter2_id', $fighterId);
            });
        }

        $fights = $query
            ->orderByDesc('events.starts_at')
            ->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => collect($fights->items())->map(function (Fight $fight) {
                $data = Presenter::fight($fight, true);

                $prediction = $fight->currentPrediction;
                $result = $fight->result;

                $data['prediction_correct'] = null;

                if ($prediction && $result && $result->winner_id && ! $result->is_draw) {
                    $predicted = $prediction->probability_fighter1 >= 0.5
                        ? $fight->fighter1_id
                        : $fight->fighter2_id;

                    $data['prediction_correct'] = $predicted === $result->winner_id;
                }

                return $data;
            })->all(),
            'meta' => [
                'total' => $fights->total(),
                'per_page' => $fights->perPage(),
                'current_page' => $fights->currentPage(),
                'last_page' => $fights->lastPage(),
            ],
        ]);
    }
}
