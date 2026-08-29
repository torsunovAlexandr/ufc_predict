<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Statistics\BacktestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BacktestController extends Controller
{
    public function run(Request $request, BacktestService $backtest): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'bankroll' => ['nullable', 'numeric', 'min:100', 'max:100000000'],
        ]);

        return response()->json([
            'data' => $backtest->run(
                $validated['from'] ?? null,
                $validated['to'] ?? null,
                (float) ($validated['bankroll'] ?? 10000),
            ),
        ]);
    }
}
