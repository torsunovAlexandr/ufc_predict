<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Betting\BankrollService;
use App\Services\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(SettingsRepository $settings): JsonResponse
    {
        $definitions = [];

        foreach ($settings->definitions() as $key => [$type, $group, $label, $default]) {
            $definitions[$key] = [
                'type' => $type,
                'group' => $group,
                'label' => $label,
                'default' => $default,
            ];
        }

        return response()->json([
            'data' => $settings->all(),
            'definitions' => $definitions,
        ]);
    }

    public function update(Request $request, SettingsRepository $settings): JsonResponse
    {
        $validated = $request->validate([
            'starting_bankroll' => ['sometimes', 'numeric', 'min:100', 'max:100000000'],
            'min_ev' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'kelly_fraction' => ['sometimes', 'numeric', 'min:0.05', 'max:1'],
            'max_stake_fraction' => ['sometimes', 'numeric', 'min:0.001', 'max:0.5'],
            'max_stake_fraction_high_conf' => ['sometimes', 'numeric', 'min:0.001', 'max:0.5'],
            'max_fraction_per_fight' => ['sometimes', 'numeric', 'min:0.001', 'max:1'],
            'min_stake_fraction' => ['sometimes', 'numeric', 'min:0.0001', 'max:0.1'],
            'min_odds' => ['sometimes', 'numeric', 'min:1.01', 'max:5'],
            'max_odds' => ['sometimes', 'numeric', 'min:1.5', 'max:1000'],
            'auto_place_bets' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'in:light,dark'],
            'odds_provider' => ['sometimes', 'in:the_odds_api,scraper,manual'],
            'score_scale' => ['sometimes', 'numeric', 'min:0.5', 'max:10'],
            'model_weights' => ['sometimes', 'array'],
            'model_weights.*' => ['numeric', 'min:0', 'max:1'],
        ]);

        if (isset($validated['model_weights'])) {
            $validated['model_weights'] = $settings->normalizeWeights($validated['model_weights']);
        }

        $settings->setMany($validated);

        return response()->json([
            'message' => 'Настройки сохранены.',
            'data' => $settings->all(),
        ]);
    }

    public function resetBankroll(Request $request, BankrollService $bankroll, SettingsRepository $settings): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:100', 'max:100000000'],
        ]);

        $amount = (float) ($validated['amount'] ?? $settings->get('starting_bankroll', 10000));

        if (isset($validated['amount'])) {
            $settings->set('starting_bankroll', $amount);
        }

        $balance = $bankroll->reset($amount);

        return response()->json([
            'message' => 'Банкролл сброшен. История ставок очищена.',
            'bankroll' => $balance,
        ]);
    }
}
