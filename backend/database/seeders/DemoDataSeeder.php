<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Fighter;
use App\Models\FighterStat;
use App\Models\Odd;
use App\Services\Betting\BettingService;
use App\Services\Prediction\PredictionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Демонстрационные данные: один предстоящий и один прошедший турнир
 * с полной статистикой, котировками, прогнозами и результатами.
 *
 * Нужны, чтобы приложение можно было посмотреть сразу после установки,
 * не дожидаясь успешного парсинга ufc.com. Имена бойцов вымышленные —
 * это витрина функциональности, а не реальные данные.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Fighter::query()->exists()) {
            $this->command?->info('Данные уже есть — демо-сид пропущен.');

            return;
        }

        $fighters = $this->createFighters();
        $upcoming = $this->createUpcomingEvent($fighters);
        $this->createPastEvent($fighters);

        // Прогнозы и рекомендации по предстоящему турниру
        $predictions = app(PredictionService::class);
        $betting = app(BettingService::class);

        foreach ($upcoming->fights()->with('fighter1', 'fighter2', 'event')->get() as $fight) {
            $prediction = $predictions->predictAndStore($fight);
            $betting->buildRecommendations($fight, $prediction);
        }

        $this->command?->info('Демонстрационные данные созданы.');
    }

    /** @return array<string, Fighter> */
    private function createFighters(): array
    {
        $definitions = [
            'volkov' => [
                'name' => 'Артём Волков', 'nickname' => 'Каток', 'country' => 'Россия',
                'dob' => '-29 years', 'height' => 185, 'reach' => 190, 'stance' => 'orthodox',
                'weight_class' => 'Lightweight', 'style' => 'wrestler',
                'record' => [19, 3, 0], 'ufc' => [9, 2, 0],
                'wins_by' => [5, 7, 7], 'losses_by' => [1, 0, 2],
                'stats' => [3.72, 2.61, 0.47, 0.61, 4.10, 0.46, 0.78, 1.4, 0.6],
                'five_round' => 2, 'title' => 1,
            ],
            'ferreira' => [
                'name' => 'Лукас Феррейра', 'nickname' => 'Гроза', 'country' => 'Бразилия',
                'dob' => '-34 years', 'height' => 180, 'reach' => 183, 'stance' => 'southpaw',
                'weight_class' => 'Lightweight', 'style' => 'striker',
                'record' => [22, 6, 0], 'ufc' => [8, 4, 0],
                'wins_by' => [15, 2, 5], 'losses_by' => [3, 1, 2],
                'stats' => [5.41, 3.48, 0.52, 0.59, 0.45, 0.28, 0.44, 0.2, 1.1],
                'five_round' => 1, 'title' => 0,
            ],
            'kowalski' => [
                'name' => 'Марек Ковальский', 'nickname' => null, 'country' => 'Польша',
                'dob' => '-31 years', 'height' => 178, 'reach' => 179, 'stance' => 'orthodox',
                'weight_class' => 'Welterweight', 'style' => 'grappler',
                'record' => [15, 4, 1], 'ufc' => [6, 3, 1],
                'wins_by' => [3, 9, 3], 'losses_by' => [2, 0, 2],
                'stats' => [3.05, 3.10, 0.44, 0.55, 2.80, 0.41, 0.62, 2.1, 0.4],
                'five_round' => 0, 'title' => 0,
            ],
            'tanaka' => [
                'name' => 'Кэндзи Танака', 'nickname' => 'Самурай', 'country' => 'Япония',
                'dob' => '-27 years', 'height' => 175, 'reach' => 176, 'stance' => 'orthodox',
                'weight_class' => 'Welterweight', 'style' => 'balanced',
                'record' => [12, 2, 0], 'ufc' => [4, 1, 0],
                'wins_by' => [6, 2, 4], 'losses_by' => [1, 0, 1],
                'stats' => [4.60, 2.95, 0.49, 0.63, 1.90, 0.44, 0.71, 0.8, 0.9],
                'five_round' => 0, 'title' => 0,
            ],
            'oduya' => [
                'name' => 'Сэмюэл Одуйя', 'nickname' => 'Молот', 'country' => 'Нигерия',
                'dob' => '-33 years', 'height' => 191, 'reach' => 198, 'stance' => 'orthodox',
                'weight_class' => 'Middleweight', 'style' => 'striker',
                'record' => [17, 5, 0], 'ufc' => [7, 3, 0],
                'wins_by' => [12, 1, 4], 'losses_by' => [2, 2, 1],
                'stats' => [4.95, 3.90, 0.50, 0.54, 0.80, 0.33, 0.58, 0.3, 1.3],
                'five_round' => 1, 'title' => 0,
            ],
            'morozov' => [
                'name' => 'Илья Морозов', 'nickname' => null, 'country' => 'Россия',
                'dob' => '-30 years', 'height' => 188, 'reach' => 191, 'stance' => 'switch',
                'weight_class' => 'Middleweight', 'style' => 'wrestler',
                'record' => [14, 3, 0], 'ufc' => [5, 2, 0],
                'wins_by' => [4, 5, 5], 'losses_by' => [1, 1, 1],
                'stats' => [3.30, 2.80, 0.45, 0.60, 3.60, 0.48, 0.74, 1.6, 0.5],
                'five_round' => 0, 'title' => 0,
            ],
        ];

        $fighters = [];

        foreach ($definitions as $key => $d) {
            [$slpm, $sapm, $strAcc, $strDef, $tdAvg, $tdAcc, $tdDef, $subAvg, $kdAvg] = $d['stats'];

            $fighter = Fighter::create([
                'name' => $d['name'],
                'slug' => Str::slug($d['name']),
                'nickname' => $d['nickname'],
                'country' => $d['country'],
                'date_of_birth' => Carbon::parse($d['dob'])->toDateString(),
                'height_cm' => $d['height'],
                'reach_cm' => $d['reach'],
                'stance' => $d['stance'],
                'weight_class' => $d['weight_class'],
                'wins' => $d['record'][0], 'losses' => $d['record'][1], 'draws' => $d['record'][2],
                'ufc_wins' => $d['ufc'][0], 'ufc_losses' => $d['ufc'][1], 'ufc_draws' => $d['ufc'][2],
                'wins_by_ko' => $d['wins_by'][0],
                'wins_by_submission' => $d['wins_by'][1],
                'wins_by_decision' => $d['wins_by'][2],
                'losses_by_ko' => $d['losses_by'][0],
                'losses_by_submission' => $d['losses_by'][1],
                'losses_by_decision' => $d['losses_by'][2],
                'sig_strikes_landed_per_min' => $slpm,
                'sig_strikes_absorbed_per_min' => $sapm,
                'striking_accuracy' => $strAcc,
                'striking_defense' => $strDef,
                'takedown_avg_per_15min' => $tdAvg,
                'takedown_accuracy' => $tdAcc,
                'takedown_defense' => $tdDef,
                'submission_avg_per_15min' => $subAvg,
                'knockdown_avg' => $kdAvg,
                'avg_fight_time_seconds' => 780,
                'five_round_fights' => $d['five_round'],
                'title_fights' => $d['title'],
                'style' => $d['style'],
                'stats_updated_at' => now(),
                'last_scraped_at' => now(),
            ]);

            $this->createFightHistory($fighter, $d);

            $fighters[$key] = $fighter;
        }

        return $fighters;
    }

    /** Пять последних боёв с детальной статистикой — из них модель считает форму. */
    private function createFightHistory(Fighter $fighter, array $d): void
    {
        [$slpm, $sapm, $strAcc, $strDef, $tdAvg, $tdAcc, $tdDef, $subAvg] = $d['stats'];

        $results = ['win', 'win', 'loss', 'win', 'win'];

        for ($i = 0; $i < 5; $i++) {
            $fightTime = 900;
            $variation = 1 + (($i % 3) - 1) * 0.12;

            $landed = (int) round($slpm * ($fightTime / 60) * $variation);
            $absorbed = (int) round($sapm * ($fightTime / 60) * $variation);
            $takedowns = (int) round($tdAvg * ($fightTime / 900) * $variation);

            // Кардио: у борцов и «универсалов» спад меньше, у ударников больше
            $lateFactor = $d['style'] === 'wrestler' ? 1.02 : ($d['style'] === 'striker' ? 0.82 : 0.94);

            $earlyTime = 600;
            $lateTime = $fightTime - $earlyTime;
            $earlyStrikes = (int) round($landed * $earlyTime / $fightTime / ((2 + $lateFactor) / 3));
            $lateStrikes = max(0, $landed - $earlyStrikes);

            FighterStat::create([
                'fighter_id' => $fighter->id,
                'fight_date' => now()->subMonths(4 * ($i + 1))->toDateString(),
                'event_name' => 'UFC Fight Night (демо)',
                'result' => $results[$i],
                'method' => $results[$i] === 'win' ? 'decision' : 'decision',
                'end_round' => 3,
                'end_time_seconds' => 300,
                'scheduled_rounds' => 3,
                'sig_strikes_landed' => $landed,
                'sig_strikes_attempted' => (int) round($landed / max($strAcc, 0.2)),
                'sig_strikes_absorbed' => $absorbed,
                'opponent_sig_strikes_attempted' => (int) round($absorbed / max(1 - $strDef, 0.2)),
                'takedowns_landed' => $takedowns,
                'takedowns_attempted' => (int) round($takedowns / max($tdAcc, 0.2)),
                'takedowns_conceded' => (int) round((1 - $tdDef) * 4),
                'takedowns_faced' => 4,
                'submission_attempts' => (int) round($subAvg * ($fightTime / 900)),
                'control_time_seconds' => (int) round($takedowns * 60),
                'fight_time_seconds' => $fightTime,
                'sig_strikes_landed_early' => $earlyStrikes,
                'sig_strikes_landed_late' => $lateStrikes,
                'fight_time_seconds_early' => $earlyTime,
                'fight_time_seconds_late' => $lateTime,
                'opponent_quality' => 0.45 + ($i % 3) * 0.1,
                'source' => 'demo',
            ]);
        }
    }

    /** @param array<string, Fighter> $fighters */
    private function createUpcomingEvent(array $fighters): Event
    {
        $event = Event::create([
            'name' => 'UFC 999: Волков — Феррейра (демо)',
            'slug' => 'ufc-999-demo',
            'starts_at' => now()->addDays(9)->setTime(22, 0),
            'venue' => 'T-Mobile Arena',
            'city' => 'Лас-Вегас',
            'country' => 'США',
            'altitude_meters' => 610,
            'status' => 'scheduled',
        ]);

        $cards = [
            ['volkov', 'ferreira', 'Lightweight', 5, true, true, 'main'],
            ['kowalski', 'tanaka', 'Welterweight', 3, false, false, 'main'],
            ['oduya', 'morozov', 'Middleweight', 3, false, false, 'prelim'],
        ];

        foreach ($cards as $index => [$a, $b, $weight, $rounds, $title, $main, $segment]) {
            $fight = Fight::create([
                'event_id' => $event->id,
                'fighter1_id' => $fighters[$a]->id,
                'fighter2_id' => $fighters[$b]->id,
                'weight_class' => $weight,
                'scheduled_rounds' => $rounds,
                'is_title_fight' => $title,
                'is_main_event' => $main,
                'card_segment' => $segment,
                'bout_order' => $index + 1,
                'status' => 'scheduled',
            ]);

            $this->createOdds($fight, $index);
        }

        return $event;
    }

    private function createOdds(Fight $fight, int $index): void
    {
        $lines = [
            ['moneyline', 'fighter1', null, [1.72, 1.68][$index % 2]],
            ['moneyline', 'fighter2', null, [2.25, 2.35][$index % 2]],
            ['totals', 'over', 2.5, 1.85],
            ['totals', 'under', 2.5, 1.95],
            ['method', 'ko_tko', null, 2.90],
            ['method', 'submission', null, 5.50],
            ['method', 'decision', null, 2.30],
        ];

        foreach (['pinnacle', 'marathonbet'] as $bookmakerIndex => $bookmaker) {
            foreach ($lines as [$market, $selection, $line, $price]) {
                Odd::create([
                    'fight_id' => $fight->id,
                    'fighter_id' => match ($selection) {
                        'fighter1' => $fight->fighter1_id,
                        'fighter2' => $fight->fighter2_id,
                        default => null,
                    },
                    'bookmaker' => $bookmaker,
                    'market' => $market,
                    'selection' => $selection,
                    'line' => $line,
                    'price' => round($price * (1 + $bookmakerIndex * 0.02), 2),
                    'implied_probability' => round(1 / $price, 5),
                    'source' => 'demo',
                    'is_latest' => true,
                    'fetched_at' => now(),
                ]);
            }
        }
    }

    /** @param array<string, Fighter> $fighters */
    private function createPastEvent(array $fighters): void
    {
        $event = Event::create([
            'name' => 'UFC Fight Night: Ковальский — Одуйя (демо)',
            'slug' => 'ufc-fight-night-demo',
            'starts_at' => now()->subDays(21)->setTime(21, 0),
            'venue' => 'UFC APEX',
            'city' => 'Лас-Вегас',
            'country' => 'США',
            'altitude_meters' => 610,
            'status' => 'completed',
        ]);

        $fight = Fight::create([
            'event_id' => $event->id,
            'fighter1_id' => $fighters['kowalski']->id,
            'fighter2_id' => $fighters['oduya']->id,
            'weight_class' => 'Middleweight',
            'scheduled_rounds' => 3,
            'is_main_event' => true,
            'card_segment' => 'main',
            'bout_order' => 1,
            'status' => 'completed',
        ]);

        Odd::create([
            'fight_id' => $fight->id,
            'fighter_id' => $fight->fighter1_id,
            'bookmaker' => 'pinnacle', 'market' => 'moneyline', 'selection' => 'fighter1',
            'price' => 2.40, 'implied_probability' => 0.41667, 'source' => 'demo',
            'is_latest' => true, 'fetched_at' => now()->subDays(22),
        ]);

        Odd::create([
            'fight_id' => $fight->id,
            'fighter_id' => $fight->fighter2_id,
            'bookmaker' => 'pinnacle', 'market' => 'moneyline', 'selection' => 'fighter2',
            'price' => 1.62, 'implied_probability' => 0.61728, 'source' => 'demo',
            'is_latest' => true, 'fetched_at' => now()->subDays(22),
        ]);

        \App\Models\Result::create([
            'fight_id' => $fight->id,
            'winner_id' => $fight->fighter1_id,
            'method' => 'submission',
            'method_detail' => 'Rear-naked choke',
            'end_round' => 2,
            'end_time_seconds' => 214,
            'total_seconds' => 514,
            'source' => 'demo',
        ]);
    }
}
