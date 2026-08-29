<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Presenter;
use App\Models\Bet;
use App\Services\Betting\BankrollService;
use App\Services\Betting\BettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bet::query()->real()->with('fight.fighter1', 'fight.fighter2');

        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        $bets = $query->latest('id')->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => collect($bets->items())->map(fn (Bet $b) => Presenter::bet($b))->all(),
            'meta' => [
                'total' => $bets->total(),
                'per_page' => $bets->perPage(),
                'current_page' => $bets->currentPage(),
                'last_page' => $bets->lastPage(),
            ],
        ]);
    }

    public function place(Request $request, BettingService $betting, BankrollService $bankroll): JsonResponse
    {
        $validated = $request->validate([
            'bet_ids' => ['required', 'array', 'min:1'],
            'bet_ids.*' => ['integer', 'exists:bets,id'],
        ]);

        $placed = $betting->placeBets($validated['bet_ids']);

        return response()->json([
            'message' => 'Размещено ставок: '.count($placed),
            'data' => collect($placed)->map(fn (Bet $b) => Presenter::bet($b))->all(),
            'bankroll' => $bankroll->current(),
        ]);
    }

    public function skip(Bet $bet): JsonResponse
    {
        if ($bet->status !== 'recommended') {
            return response()->json(['message' => 'Пропустить можно только нерасмещённую рекомендацию.'], 422);
        }

        $bet->update(['status' => 'skipped']);

        return response()->json(['message' => 'Рекомендация отклонена.', 'data' => Presenter::bet($bet)]);
    }

    /** Ручной расчёт отдельной ставки (если результат уже есть). */
    public function settle(Bet $bet, BankrollService $bankroll): JsonResponse
    {
        $bet->load('fight.result');

        if ($bet->status !== 'placed' || ! $bet->fight?->result) {
            return response()->json(['message' => 'Ставка не размещена либо результат боя ещё не известен.'], 422);
        }

        $settled = $bankroll->settleBet($bet, $bet->fight, $bet->fight->result);

        return response()->json([
            'message' => 'Ставка рассчитана.',
            'data' => Presenter::bet($settled),
            'bankroll' => $bankroll->current(),
        ]);
    }
}
