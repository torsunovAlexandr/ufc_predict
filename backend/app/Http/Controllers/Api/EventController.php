<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Presenter;
use App\Models\Event;
use App\Services\Betting\BankrollService;
use App\Services\Betting\BettingService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionService;
use App\Services\Scraping\ResultsScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'upcoming');

        $query = Event::query()->withCount('fights');

        $query = match ($scope) {
            'past' => $query->past()->orderByDesc('starts_at'),
            'all' => $query->orderByDesc('starts_at'),
            default => $query->upcoming()->orderBy('starts_at'),
        };

        $events = $query->limit((int) $request->query('limit', 50))->get();

        return response()->json([
            'data' => $events->map(fn (Event $e) => Presenter::event($e))->all(),
        ]);
    }

    public function show(Event $event): JsonResponse
    {
        $event->load([
            'fights.fighter1',
            'fights.fighter2',
            'fights.currentPrediction',
            'fights.result',
        ]);

        return response()->json([
            'data' => Presenter::event($event, true),
        ]);
    }

    public function predict(Event $event, PredictionService $predictions, BettingService $betting): JsonResponse
    {
        $event->load('fights.fighter1', 'fights.fighter2');
        $count = 0;

        foreach ($event->fights()->where('status', 'scheduled')->get() as $fight) {
            $prediction = $predictions->predictAndStore($fight);
            $betting->buildRecommendations($fight, $prediction);
            $count++;
        }

        return response()->json(['message' => "Пересчитано прогнозов: {$count}", 'count' => $count]);
    }

    public function refreshOdds(Event $event, OddsService $odds): JsonResponse
    {
        $result = $odds->refreshForEvent($event);

        return response()->json([
            'message' => $result['stored'] > 0
                ? "Загружено котировок: {$result['stored']}"
                : 'Котировки получить не удалось — проверьте ключ API или введите коэффициенты вручную.',
            'result' => $result,
        ]);
    }

    public function fetchResults(Event $event, ResultsScraper $scraper, BankrollService $bankroll): JsonResponse
    {
        $outcome = $scraper->syncEventResults($event);

        $settled = 0;
        foreach ($event->fights()->with('result')->get() as $fight) {
            $settled += count($bankroll->settleFight($fight));
        }

        return response()->json([
            'message' => "Найдено результатов: {$outcome['found']}. Рассчитано ставок: {$settled}.",
            'result' => $outcome,
            'bankroll' => $bankroll->current(),
        ]);
    }
}
