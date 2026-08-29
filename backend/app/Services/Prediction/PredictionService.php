<?php

namespace App\Services\Prediction;

use App\Models\Fight;
use App\Models\Prediction;
use Illuminate\Support\Facades\DB;

/**
 * Оркестратор прогнозирования: собирает профили бойцов и контекст боя,
 * запускает модель и сохраняет результат в БД.
 */
class PredictionService
{
    public function __construct(
        private readonly PredictionEngine $engine,
        private readonly FighterProfileBuilder $profileBuilder,
    ) {}

    /**
     * Рассчитать прогноз по бою и сохранить его.
     * Предыдущие прогнозы по этому бою помечаются как неактуальные.
     */
    public function predictAndStore(Fight $fight): Prediction
    {
        $result = $this->predict($fight);

        return DB::transaction(function () use ($fight, $result) {
            Prediction::where('fight_id', $fight->id)->update(['is_current' => false]);

            $data = $result->toArray();
            unset($data['base_probability']);

            return Prediction::create(array_merge($data, [
                'fight_id' => $fight->id,
                'is_current' => true,
            ]));
        });
    }

    /** Рассчитать прогноз без сохранения (используется в бэктестинге). */
    public function predict(Fight $fight, ?\DateTimeInterface $asOf = null): PredictionResult
    {
        $fight->loadMissing(['fighter1', 'fighter2', 'event']);

        $asOf = $asOf ?? $fight->event?->starts_at?->toDateTimeImmutable() ?? new \DateTimeImmutable;

        $profile1 = $this->profileBuilder->build($fight->fighter1, $asOf);
        $profile2 = $this->profileBuilder->build($fight->fighter2, $asOf);

        $context = new FightContext(
            scheduledRounds: (int) $fight->scheduled_rounds,
            isTitleFight: (bool) $fight->is_title_fight,
            isMainEvent: (bool) $fight->is_main_event,
            weightClass: $fight->weight_class,
            altitudeMeters: $fight->event?->altitude_meters,
            headToHead: $this->headToHead($fight, $asOf),
        );

        return $this->engine->predict($profile1, $profile2, $context);
    }

    /**
     * Счёт очных встреч до указанной даты.
     *
     * @return array{fighter1:int, fighter2:int, draws:int}|null
     */
    private function headToHead(Fight $fight, \DateTimeInterface $asOf): ?array
    {
        $ids = [$fight->fighter1_id, $fight->fighter2_id];

        $previous = Fight::query()
            ->where('id', '!=', $fight->id)
            ->where(function ($query) use ($ids) {
                $query->where(fn ($q) => $q->where('fighter1_id', $ids[0])->where('fighter2_id', $ids[1]))
                    ->orWhere(fn ($q) => $q->where('fighter1_id', $ids[1])->where('fighter2_id', $ids[0]));
            })
            ->whereHas('event', fn ($q) => $q->where('starts_at', '<', $asOf))
            ->with('result')
            ->get();

        if ($previous->isEmpty()) {
            return null;
        }

        $tally = ['fighter1' => 0, 'fighter2' => 0, 'draws' => 0];

        foreach ($previous as $past) {
            $result = $past->result;

            if (! $result || $result->is_no_contest) {
                continue;
            }

            if ($result->is_draw || ! $result->winner_id) {
                $tally['draws']++;

                continue;
            }

            if ($result->winner_id === $fight->fighter1_id) {
                $tally['fighter1']++;
            } elseif ($result->winner_id === $fight->fighter2_id) {
                $tally['fighter2']++;
            }
        }

        return array_sum($tally) > 0 ? $tally : null;
    }

    /**
     * Прогнозы для всех боёв турнира (или для всех боёв без актуального прогноза).
     *
     * @param  iterable<Fight>  $fights
     * @return array<int, Prediction>
     */
    public function predictMany(iterable $fights): array
    {
        $predictions = [];

        foreach ($fights as $fight) {
            $predictions[] = $this->predictAndStore($fight);
        }

        return $predictions;
    }
}
