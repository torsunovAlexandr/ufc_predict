<?php

namespace App\Http\Resources;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Fighter;
use App\Models\Odd;
use App\Models\Prediction;

/**
 * Приведение моделей к структурам, которые ждёт фронтенд.
 * Вынесено в один класс, чтобы формат ответа не расползался по контроллерам.
 */
class Presenter
{
    public static function event(Event $event, bool $withFights = false): array
    {
        $data = [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'venue' => $event->venue,
            'city' => $event->city,
            'country' => $event->country,
            'altitude_meters' => $event->altitude_meters,
            'status' => $event->status,
            'url' => $event->ufc_url,
            'fights_count' => $event->fights_count ?? $event->fights()->count(),
        ];

        if ($withFights) {
            $data['fights'] = $event->fights->map(fn (Fight $fight) => self::fight($fight))->all();
        }

        return $data;
    }

    public static function fight(Fight $fight, bool $detailed = false): array
    {
        $prediction = $fight->relationLoaded('currentPrediction')
            ? $fight->currentPrediction
            : $fight->currentPrediction()->first();

        $data = [
            'id' => $fight->id,
            'event_id' => $fight->event_id,
            'event' => $fight->relationLoaded('event') && $fight->event
                ? ['id' => $fight->event->id, 'name' => $fight->event->name, 'starts_at' => $fight->event->starts_at?->toIso8601String()]
                : null,
            'fighter1' => $fight->fighter1 ? self::fighter($fight->fighter1) : null,
            'fighter2' => $fight->fighter2 ? self::fighter($fight->fighter2) : null,
            'weight_class' => $fight->weight_class,
            'scheduled_rounds' => $fight->scheduled_rounds,
            'is_title_fight' => $fight->is_title_fight,
            'is_main_event' => $fight->is_main_event,
            'card_segment' => $fight->card_segment,
            'bout_order' => $fight->bout_order,
            'status' => $fight->status,
            'prediction' => $prediction ? self::prediction($prediction) : null,
        ];

        if ($detailed) {
            $data['odds'] = $fight->latestOdds()->get()->map(fn (Odd $odd) => self::odd($odd))->all();
            $data['bets'] = $fight->bets()->orderByDesc('expected_value')->get()->map(fn (Bet $bet) => self::bet($bet))->all();
            $data['result'] = $fight->result ? self::result($fight) : null;
        }

        return $data;
    }

    public static function fighter(Fighter $fighter): array
    {
        return [
            'id' => $fighter->id,
            'name' => $fighter->name,
            'nickname' => $fighter->nickname,
            'slug' => $fighter->slug,
            'image_url' => $fighter->image_url,
            'country' => $fighter->country,
            'age' => $fighter->ageAt(),
            'height_cm' => $fighter->height_cm,
            'reach_cm' => $fighter->reach_cm,
            'stance' => $fighter->stance,
            'weight_class' => $fighter->weight_class,
            'record' => $fighter->recordString(),
            'wins' => $fighter->wins,
            'losses' => $fighter->losses,
            'draws' => $fighter->draws,
            'style' => $fighter->style,
            'stats' => [
                'sig_strikes_per_min' => $fighter->sig_strikes_landed_per_min,
                'sig_strikes_absorbed_per_min' => $fighter->sig_strikes_absorbed_per_min,
                'striking_accuracy' => $fighter->striking_accuracy,
                'striking_defense' => $fighter->striking_defense,
                'takedown_avg' => $fighter->takedown_avg_per_15min,
                'takedown_accuracy' => $fighter->takedown_accuracy,
                'takedown_defense' => $fighter->takedown_defense,
                'submission_avg' => $fighter->submission_avg_per_15min,
            ],
            'wins_by' => [
                'ko' => $fighter->wins_by_ko,
                'submission' => $fighter->wins_by_submission,
                'decision' => $fighter->wins_by_decision,
            ],
            'last_scraped_at' => $fighter->last_scraped_at?->toIso8601String(),
        ];
    }

    public static function prediction(Prediction $prediction): array
    {
        return [
            'id' => $prediction->id,
            'probability_fighter1' => $prediction->probability_fighter1,
            'probability_fighter2' => $prediction->probability_fighter2,
            'confidence' => $prediction->confidence,
            'data_completeness' => $prediction->data_completeness,
            'explanation' => $prediction->explanation,
            'factors' => $prediction->factors,
            'applied_rules' => $prediction->applied_rules,
            'method_probabilities' => $prediction->method_probabilities,
            'probability_over_2_5' => $prediction->probability_over_2_5,
            'probability_under_2_5' => $prediction->probability_under_2_5,
            'recommended' => [
                'selection' => $prediction->recommended_selection,
                'odds' => $prediction->recommended_odds,
                'stake' => $prediction->recommended_stake,
                'ev' => $prediction->recommended_ev,
            ],
            'model_version' => $prediction->model_version,
            'created_at' => $prediction->created_at?->toIso8601String(),
        ];
    }

    public static function odd(Odd $odd): array
    {
        return [
            'id' => $odd->id,
            'bookmaker' => $odd->bookmaker,
            'market' => $odd->market,
            'selection' => $odd->selection,
            'line' => $odd->line,
            'price' => $odd->price,
            'implied_probability' => $odd->implied_probability,
            'source' => $odd->source,
            'fetched_at' => $odd->fetched_at?->toIso8601String(),
        ];
    }

    public static function bet(Bet $bet): array
    {
        return [
            'id' => $bet->id,
            'fight_id' => $bet->fight_id,
            'fight' => $bet->relationLoaded('fight') && $bet->fight ? $bet->fight->title() : null,
            'market' => $bet->market,
            'selection' => $bet->selection,
            'line' => $bet->line,
            'bookmaker' => $bet->bookmaker,
            'odds' => $bet->odds,
            'model_probability' => $bet->model_probability,
            'implied_probability' => $bet->implied_probability,
            'expected_value' => $bet->expected_value,
            'stake' => $bet->stake,
            'stake_fraction' => $bet->stake_fraction,
            'status' => $bet->status,
            'payout' => $bet->payout,
            'profit' => $bet->profit,
            'reason' => $bet->reason,
            'placed_at' => $bet->placed_at?->toIso8601String(),
            'settled_at' => $bet->settled_at?->toIso8601String(),
        ];
    }

    public static function result(Fight $fight): ?array
    {
        $result = $fight->result;

        if (! $result) {
            return null;
        }

        return [
            'winner_id' => $result->winner_id,
            'winner_name' => $result->winner_id === $fight->fighter1_id
                ? $fight->fighter1?->name
                : ($result->winner_id === $fight->fighter2_id ? $fight->fighter2?->name : null),
            'is_draw' => $result->is_draw,
            'is_no_contest' => $result->is_no_contest,
            'method' => $result->method,
            'method_detail' => $result->method_detail,
            'end_round' => $result->end_round,
            'end_time_seconds' => $result->end_time_seconds,
            'source' => $result->source,
            'entered_manually' => $result->entered_manually,
        ];
    }
}
