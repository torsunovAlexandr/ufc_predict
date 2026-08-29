<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Fighter;
use App\Models\Result;
use App\Services\Betting\BankrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_expected_structure(): void
    {
        app(BankrollService::class)->initialise(10000);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'summary' => ['bankroll', 'profit', 'roi', 'win_rate'],
                'upcoming_events',
                'next_fights',
                'recommended_bets',
                'recent_results',
            ]);
    }

    /**
     * Регрессия: дашборд соединяет fights с events ради сортировки по дате
     * турнира, а колонка `status` есть в обеих таблицах. Без явного указания
     * таблицы запрос падает с «Column 'status' is ambiguous» (MySQL 1052).
     * Тест проверяет не только структуру ответа, но и что бой действительно
     * попал в выборку — то есть join отработал.
     */
    public function test_dashboard_lists_upcoming_fights_with_join(): void
    {
        app(BankrollService::class)->initialise(10000);

        $this->makeFight(status: 'scheduled', startsAt: now()->addDays(5));

        $response = $this->getJson('/api/dashboard')->assertOk();

        $this->assertCount(1, $response->json('next_fights'));
        $this->assertCount(1, $response->json('upcoming_events'));
    }

    /** Та же проблема неоднозначной колонки, но в истории боёв. */
    public function test_history_lists_completed_fights_with_join(): void
    {
        $fight = $this->makeFight(status: 'completed', startsAt: now()->subDays(5));

        Result::create([
            'fight_id' => $fight->id,
            'winner_id' => $fight->fighter1_id,
            'method' => 'decision',
            'end_round' => 3,
            'end_time_seconds' => 300,
            'total_seconds' => 900,
        ]);

        $response = $this->getJson('/api/statistics/history')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($fight->id, $response->json('data.0.id'));
    }

    /** Фильтры истории тоже обращаются к колонкам, которые есть в обеих таблицах. */
    public function test_history_filters_do_not_break_the_join(): void
    {
        $fight = $this->makeFight(status: 'completed', startsAt: now()->subDays(5));

        Result::create([
            'fight_id' => $fight->id,
            'winner_id' => $fight->fighter1_id,
            'method' => 'ko_tko',
            'end_round' => 1,
            'end_time_seconds' => 90,
            'total_seconds' => 90,
        ]);

        $this->getJson("/api/statistics/history?event_id={$fight->event_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/statistics/history?fighter_id={$fight->fighter1_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Бэктест перебирает бои в хронологическом порядке — тоже через join. */
    public function test_backtest_endpoint_runs_without_sql_errors(): void
    {
        $this->postJson('/api/backtest', ['bankroll' => 10000])
            ->assertOk()
            ->assertJsonStructure(['data' => ['fights_analysed', 'roi', 'final_bankroll']]);
    }

    private function makeFight(string $status, \DateTimeInterface $startsAt): Fight
    {
        static $counter = 0;
        $counter++;

        $a = Fighter::create(['name' => "Боец A{$counter}", 'slug' => "boec-a{$counter}"]);
        $b = Fighter::create(['name' => "Боец B{$counter}", 'slug' => "boec-b{$counter}"]);

        $event = Event::create([
            'name' => "Турнир {$counter}",
            'slug' => "turnir-{$counter}",
            'starts_at' => $startsAt,
            'status' => $status === 'completed' ? 'completed' : 'scheduled',
        ]);

        return Fight::create([
            'event_id' => $event->id,
            'fighter1_id' => $a->id,
            'fighter2_id' => $b->id,
            'scheduled_rounds' => 3,
            'status' => $status,
        ]);
    }

    public function test_settings_can_be_updated(): void
    {
        $this->putJson('/api/settings', ['min_ev' => 0.08, 'theme' => 'light'])
            ->assertOk()
            ->assertJsonPath('data.min_ev', 0.08)
            ->assertJsonPath('data.theme', 'light');
    }

    public function test_settings_reject_invalid_values(): void
    {
        $this->putJson('/api/settings', ['min_ev' => 5])->assertStatus(422);
        $this->putJson('/api/settings', ['theme' => 'neon'])->assertStatus(422);
    }

    public function test_prediction_endpoint_creates_prediction(): void
    {
        $a = Fighter::create(['name' => 'Боец А', 'slug' => 'boec-a', 'wins' => 10, 'losses' => 2, 'takedown_avg_per_15min' => 3.5, 'takedown_defense' => 0.7, 'sig_strikes_landed_per_min' => 4.0]);
        $b = Fighter::create(['name' => 'Боец Б', 'slug' => 'boec-b', 'wins' => 8, 'losses' => 4, 'takedown_avg_per_15min' => 1.0, 'takedown_defense' => 0.45, 'sig_strikes_landed_per_min' => 5.0]);

        $event = Event::create(['name' => 'Турнир', 'slug' => 'turnir', 'starts_at' => now()->addWeek()]);

        $fight = Fight::create([
            'event_id' => $event->id,
            'fighter1_id' => $a->id,
            'fighter2_id' => $b->id,
            'scheduled_rounds' => 3,
        ]);

        $this->postJson("/api/fights/{$fight->id}/predict")
            ->assertOk()
            ->assertJsonPath('data.id', $fight->id)
            ->assertJsonStructure(['data' => ['prediction' => ['probability_fighter1', 'explanation']]]);

        $this->assertDatabaseCount('predictions', 1);
    }

    public function test_manual_result_entry_settles_the_fight(): void
    {
        $a = Fighter::create(['name' => 'Боец В', 'slug' => 'boec-v']);
        $b = Fighter::create(['name' => 'Боец Г', 'slug' => 'boec-g']);
        $event = Event::create(['name' => 'Турнир 2', 'slug' => 'turnir-2', 'starts_at' => now()->subDay()]);

        $fight = Fight::create([
            'event_id' => $event->id,
            'fighter1_id' => $a->id,
            'fighter2_id' => $b->id,
        ]);

        $this->postJson("/api/fights/{$fight->id}/result", [
            'winner_id' => $a->id,
            'method' => 'ko_tko',
            'end_round' => 2,
            'end_time_seconds' => 130,
        ])->assertOk();

        $this->assertDatabaseHas('results', ['fight_id' => $fight->id, 'winner_id' => $a->id]);
        $this->assertSame('completed', $fight->refresh()->status);
    }

    public function test_result_rejects_winner_from_another_fight(): void
    {
        $a = Fighter::create(['name' => 'Боец Д', 'slug' => 'boec-d']);
        $b = Fighter::create(['name' => 'Боец Е', 'slug' => 'boec-e']);
        $outsider = Fighter::create(['name' => 'Посторонний', 'slug' => 'postoronnii']);
        $event = Event::create(['name' => 'Турнир 3', 'slug' => 'turnir-3', 'starts_at' => now()->subDay()]);

        $fight = Fight::create(['event_id' => $event->id, 'fighter1_id' => $a->id, 'fighter2_id' => $b->id]);

        $this->postJson("/api/fights/{$fight->id}/result", ['winner_id' => $outsider->id])
            ->assertStatus(422);
    }

    public function test_manual_odds_can_be_stored(): void
    {
        $a = Fighter::create(['name' => 'Боец Ж', 'slug' => 'boec-zh']);
        $b = Fighter::create(['name' => 'Боец З', 'slug' => 'boec-z']);
        $event = Event::create(['name' => 'Турнир 4', 'slug' => 'turnir-4', 'starts_at' => now()->addWeek()]);
        $fight = Fight::create(['event_id' => $event->id, 'fighter1_id' => $a->id, 'fighter2_id' => $b->id]);

        $this->postJson("/api/fights/{$fight->id}/odds", [
            'odds' => [
                ['market' => 'moneyline', 'selection' => 'fighter1', 'price' => 1.85],
                ['market' => 'moneyline', 'selection' => 'fighter2', 'price' => 2.05],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('odds', 2);
    }
}
