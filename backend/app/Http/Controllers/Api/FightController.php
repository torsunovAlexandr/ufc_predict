<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Presenter;
use App\Models\Fight;
use App\Services\Betting\BankrollService;
use App\Services\Betting\BettingService;
use App\Services\Odds\OddsService;
use App\Services\Prediction\PredictionService;
use App\Services\Scraping\ResultsScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FightController extends Controller
{
    public function show(Fight $fight): JsonResponse
    {
        $fight->load(['fighter1', 'fighter2', 'event', 'currentPrediction', 'result']);

        return response()->json(['data' => Presenter::fight($fight, true)]);
    }

    public function predict(Fight $fight, PredictionService $predictions, BettingService $betting): JsonResponse
    {
        $prediction = $predictions->predictAndStore($fight);
        $bets = $betting->buildRecommendations($fight, $prediction);

        $fight->refresh()->load(['fighter1', 'fighter2', 'event', 'currentPrediction']);

        return response()->json([
            'message' => 'Прогноз пересчитан. Рекомендаций: '.count($bets),
            'data' => Presenter::fight($fight, true),
        ]);
    }

    /** Ручной ввод коэффициентов. */
    public function storeOdds(Fight $fight, Request $request, OddsService $odds, BettingService $betting): JsonResponse
    {
        $validated = $request->validate([
            'odds' => ['required', 'array', 'min:1'],
            'odds.*.market' => ['required', 'in:moneyline,draw,totals,method'],
            'odds.*.selection' => ['required', 'string', 'max:30'],
            'odds.*.line' => ['nullable', 'numeric', 'between:0,20'],
            'odds.*.price' => ['required', 'numeric', 'between:1.01,1000'],
            'odds.*.bookmaker' => ['nullable', 'string', 'max:60'],
        ]);

        $stored = $odds->storeManual($fight, $validated['odds']);

        if ($fight->currentPrediction) {
            $betting->buildRecommendations($fight, $fight->currentPrediction);
        }

        $fight->refresh()->load(['fighter1', 'fighter2', 'event', 'currentPrediction']);

        return response()->json([
            'message' => "Сохранено котировок: {$stored}",
            'data' => Presenter::fight($fight, true),
        ]);
    }

    /** Ручной ввод результата боя. */
    public function storeResult(
        Fight $fight,
        Request $request,
        ResultsScraper $scraper,
        BankrollService $bankroll
    ): JsonResponse {
        $validated = $request->validate([
            'winner_id' => ['nullable', 'integer', 'exists:fighters,id'],
            'is_draw' => ['boolean'],
            'is_no_contest' => ['boolean'],
            'method' => ['nullable', 'in:ko_tko,submission,decision,dq,other'],
            'method_detail' => ['nullable', 'string', 'max:255'],
            'end_round' => ['nullable', 'integer', 'between:1,5'],
            'end_time_seconds' => ['nullable', 'integer', 'between:0,300'],
        ]);

        if (! empty($validated['winner_id'])
            && ! in_array($validated['winner_id'], [$fight->fighter1_id, $fight->fighter2_id], true)) {
            return response()->json(['message' => 'Победитель не участвует в этом бою.'], 422);
        }

        $scraper->storeResult($fight, $validated, 'ручной ввод', manual: true);

        $settled = $bankroll->settleFight($fight->refresh());

        $fight->load(['fighter1', 'fighter2', 'event', 'currentPrediction', 'result']);

        return response()->json([
            'message' => 'Результат сохранён. Рассчитано ставок: '.count($settled),
            'data' => Presenter::fight($fight, true),
            'bankroll' => $bankroll->current(),
        ]);
    }

    /** Поисковые подсказки по результату боя (Google Custom Search). */
    public function searchResult(Fight $fight, ResultsScraper $scraper): JsonResponse
    {
        $fight->load('fighter1', 'fighter2');

        return response()->json(['data' => $scraper->searchResult($fight)]);
    }
}
