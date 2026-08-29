<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Presenter;
use App\Models\Fighter;
use App\Services\Prediction\FighterProfileBuilder;
use App\Services\Scraping\UfcFighterScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FighterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Fighter::query();

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($weightClass = $request->query('weight_class')) {
            $query->where('weight_class', $weightClass);
        }

        $fighters = $query->orderBy('name')->paginate((int) $request->query('per_page', 40));

        return response()->json([
            'data' => collect($fighters->items())->map(fn (Fighter $f) => Presenter::fighter($f))->all(),
            'meta' => [
                'total' => $fighters->total(),
                'per_page' => $fighters->perPage(),
                'current_page' => $fighters->currentPage(),
                'last_page' => $fighters->lastPage(),
            ],
        ]);
    }

    public function show(Fighter $fighter, FighterProfileBuilder $builder): JsonResponse
    {
        $profile = $builder->build($fighter);

        return response()->json([
            'data' => Presenter::fighter($fighter),
            'profile' => [
                'takedowns_per_15' => round($profile->takedownsPer15, 2),
                'takedown_accuracy' => round($profile->takedownAccuracy, 3),
                'takedown_defense' => round($profile->takedownDefense, 3),
                'sig_strikes_per_min' => round($profile->sigStrikesPerMin, 2),
                'striking_accuracy' => round($profile->strikingAccuracy, 3),
                'striking_defense' => round($profile->strikingDefense, 3),
                'sig_strikes_absorbed_per_min' => round($profile->sigStrikesAbsorbedPerMin, 2),
                'submission_attempts_per_15' => round($profile->submissionAttemptsPer15, 2),
                'submission_defense' => round($profile->submissionDefense, 3),
                'cardio_index' => round($profile->cardioIndex, 3),
                'style' => $profile->style,
                'data_completeness' => round($profile->dataCompleteness, 3),
                'recent_results' => $profile->recentResults,
            ],
            'recent_fights' => $fighter->stats()->limit(10)->get(),
        ]);
    }

    public function refresh(Fighter $fighter, UfcFighterScraper $scraper): JsonResponse
    {
        $ok = $scraper->sync($fighter, force: true);

        return response()->json([
            'message' => $ok
                ? 'Карточка бойца обновлена.'
                : 'Не удалось получить данные с ufc.com — попробуйте позже.',
            'data' => Presenter::fighter($fighter->refresh()),
        ], $ok ? 200 : 502);
    }
}
