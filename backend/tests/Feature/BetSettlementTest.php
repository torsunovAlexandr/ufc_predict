<?php

namespace Tests\Feature;

use App\Models\BankrollEntry;
use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Fighter;
use App\Models\Result;
use App\Services\Betting\BankrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BetSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Fight $fight;

    protected function setUp(): void
    {
        parent::setUp();

        $a = Fighter::create(['name' => 'Первый', 'slug' => 'pervyi']);
        $b = Fighter::create(['name' => 'Второй', 'slug' => 'vtoroi']);

        $event = Event::create([
            'name' => 'Тестовый турнир',
            'slug' => 'test-event',
            'starts_at' => now()->subDay(),
        ]);

        $this->fight = Fight::create([
            'event_id' => $event->id,
            'fighter1_id' => $a->id,
            'fighter2_id' => $b->id,
            'scheduled_rounds' => 3,
        ]);
    }

    private function bet(array $attributes = []): Bet
    {
        return Bet::create(array_merge([
            'fight_id' => $this->fight->id,
            'market' => 'moneyline',
            'selection' => 'fighter1',
            'odds' => 2.0,
            'model_probability' => 0.6,
            'implied_probability' => 0.5,
            'expected_value' => 0.2,
            'kelly_fraction' => 0.2,
            'stake_fraction' => 0.05,
            'stake' => 500,
            'status' => 'recommended',
        ], $attributes));
    }

    public function test_placing_a_bet_debits_the_bankroll(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);

        $bet = $service->placeBet($this->bet());

        $this->assertSame('placed', $bet->status);
        $this->assertEqualsWithDelta(9500.0, $service->current(), 0.01);
    }

    public function test_winning_bet_credits_full_payout(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);

        $bet = $service->placeBet($this->bet());

        Result::create([
            'fight_id' => $this->fight->id,
            'winner_id' => $this->fight->fighter1_id,
            'method' => 'decision',
            'end_round' => 3,
            'end_time_seconds' => 300,
            'total_seconds' => 900,
        ]);

        $settled = $service->settleFight($this->fight->refresh());

        $this->assertCount(1, $settled);
        $this->assertSame('won', $settled[0]->status);
        $this->assertEqualsWithDelta(500.0, $settled[0]->profit, 0.01);
        $this->assertEqualsWithDelta(10500.0, $service->current(), 0.01);
    }

    public function test_losing_bet_keeps_bankroll_debited(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);

        $service->placeBet($this->bet());

        Result::create([
            'fight_id' => $this->fight->id,
            'winner_id' => $this->fight->fighter2_id,
            'method' => 'ko_tko',
            'end_round' => 1,
            'end_time_seconds' => 120,
            'total_seconds' => 120,
        ]);

        $settled = $service->settleFight($this->fight->refresh());

        $this->assertSame('lost', $settled[0]->status);
        $this->assertEqualsWithDelta(9500.0, $service->current(), 0.01);
    }

    public function test_no_contest_returns_the_stake(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);

        $service->placeBet($this->bet());

        Result::create(['fight_id' => $this->fight->id, 'is_no_contest' => true]);

        $settled = $service->settleFight($this->fight->refresh());

        $this->assertSame('void', $settled[0]->status);
        $this->assertEqualsWithDelta(10000.0, $service->current(), 0.01);
    }

    public function test_totals_under_wins_when_fight_ends_early(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);

        $bet = $service->placeBet($this->bet([
            'market' => 'totals',
            'selection' => 'under',
            'line' => 2.5,
        ]));

        $result = Result::create([
            'fight_id' => $this->fight->id,
            'winner_id' => $this->fight->fighter1_id,
            'method' => 'ko_tko',
            'end_round' => 2,
            'end_time_seconds' => 100,
            'total_seconds' => 400,
        ]);

        $this->assertSame('won', $service->outcomeFor($bet, $this->fight, $result));
    }

    public function test_totals_over_wins_when_fight_goes_the_distance(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);

        $bet = $this->bet(['market' => 'totals', 'selection' => 'over', 'line' => 2.5]);

        $result = Result::create([
            'fight_id' => $this->fight->id,
            'winner_id' => $this->fight->fighter1_id,
            'method' => 'decision',
            'end_round' => 3,
            'end_time_seconds' => 300,
            'total_seconds' => 900,
        ]);

        $this->assertSame('won', $service->outcomeFor($bet, $this->fight, $result));
    }

    public function test_method_bet_matches_result_method(): void
    {
        $service = app(BankrollService::class);

        $result = Result::create([
            'fight_id' => $this->fight->id,
            'winner_id' => $this->fight->fighter2_id,
            'method' => 'submission',
            'end_round' => 2,
            'end_time_seconds' => 60,
            'total_seconds' => 360,
        ]);

        $this->assertSame('won', $service->outcomeFor($this->bet(['market' => 'method', 'selection' => 'submission']), $this->fight, $result));
        $this->assertSame('lost', $service->outcomeFor($this->bet(['market' => 'method', 'selection' => 'ko_tko']), $this->fight, $result));
    }

    public function test_bankroll_history_is_recorded(): void
    {
        $service = app(BankrollService::class);
        $service->initialise(10000);
        $service->placeBet($this->bet());

        $this->assertSame(2, BankrollEntry::count());
        $this->assertSame('initial', BankrollEntry::orderBy('id')->first()->type);
    }
}
