<?php

namespace App\Services\Betting;

use App\Models\BankrollEntry;
use App\Models\Bet;
use App\Models\Fight;
use App\Models\Result;
use App\Services\Support\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Виртуальный банкролл: размещение ставок, расчёт по результатам боёв
 * и ведение истории изменений баланса (разделы 5.1 и 6.2 ТЗ).
 */
class BankrollService
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** Текущий баланс. Если истории нет — стартовый банк из настроек. */
    public function current(): float
    {
        $last = BankrollEntry::query()->orderByDesc('id')->first();

        if ($last) {
            return (float) $last->balance_after;
        }

        return $this->initialise();
    }

    /** Записать стартовый банк (идемпотентно). */
    public function initialise(?float $amount = null): float
    {
        $amount = $amount ?? (float) $this->settings->get('starting_bankroll', config('ufc.bankroll.starting'));

        if (BankrollEntry::query()->exists()) {
            return $this->current();
        }

        BankrollEntry::create([
            'type' => 'initial',
            'amount' => $amount,
            'balance_after' => $amount,
            'description' => 'Стартовый банкролл',
            'occurred_at' => now(),
        ]);

        return $amount;
    }

    /** Полный сброс банка и истории ставок к стартовому значению. */
    public function reset(float $amount): float
    {
        return DB::transaction(function () use ($amount) {
            Bet::query()->whereIn('status', ['placed', 'won', 'lost', 'void'])->update([
                'status' => 'recommended',
                'payout' => null,
                'profit' => null,
                'bankroll_before' => null,
                'bankroll_after' => null,
                'placed_at' => null,
                'settled_at' => null,
            ]);

            BankrollEntry::query()->delete();

            BankrollEntry::create([
                'type' => 'reset',
                'amount' => $amount,
                'balance_after' => $amount,
                'description' => 'Сброс банкролла',
                'occurred_at' => now(),
            ]);

            return $amount;
        });
    }

    /** Разместить ставку: списать сумму с баланса. */
    public function placeBet(Bet $bet): Bet
    {
        if ($bet->status !== 'recommended') {
            return $bet;
        }

        return DB::transaction(function () use ($bet) {
            $before = $this->current();

            if ($bet->stake > $before) {
                // Банка не хватает — режем ставку до остатка
                $bet->stake = floor($before);
            }

            if ($bet->stake < 1) {
                $bet->status = 'skipped';
                $bet->reason = trim($bet->reason.' Ставка пропущена: недостаточно средств.');
                $bet->save();

                return $bet;
            }

            $after = round($before - $bet->stake, 2);

            $bet->fill([
                'status' => 'placed',
                'bankroll_before' => $before,
                'placed_at' => now(),
            ])->save();

            BankrollEntry::create([
                'bet_id' => $bet->id,
                'type' => 'bet_placed',
                'amount' => -$bet->stake,
                'balance_after' => $after,
                'description' => sprintf('Ставка %s на бой #%d', $bet->selection, $bet->fight_id),
                'occurred_at' => now(),
            ]);

            return $bet->refresh();
        });
    }

    /**
     * Рассчитать все размещённые ставки по бою после появления результата.
     *
     * @return array<int, Bet>
     */
    public function settleFight(Fight $fight): array
    {
        $result = $fight->result;

        if (! $result) {
            return [];
        }

        $bets = Bet::where('fight_id', $fight->id)->where('status', 'placed')->get();
        $settled = [];

        foreach ($bets as $bet) {
            $settled[] = $this->settleBet($bet, $fight, $result);
        }

        return $settled;
    }

    public function settleBet(Bet $bet, Fight $fight, Result $result): Bet
    {
        $outcome = $this->outcomeFor($bet, $fight, $result);

        return DB::transaction(function () use ($bet, $outcome) {
            $before = $this->current();

            [$status, $payout] = match ($outcome) {
                'won' => ['won', round($bet->stake * $bet->odds, 2)],
                'void' => ['void', $bet->stake],
                default => ['lost', 0.0],
            };

            $profit = round($payout - $bet->stake, 2);
            $after = round($before + $payout, 2);

            $bet->fill([
                'status' => $status,
                'payout' => $payout,
                'profit' => $profit,
                'bankroll_after' => $after,
                'settled_at' => now(),
            ])->save();

            if ($payout > 0) {
                BankrollEntry::create([
                    'bet_id' => $bet->id,
                    'type' => $status === 'void' ? 'bet_void' : 'bet_won',
                    'amount' => $payout,
                    'balance_after' => $after,
                    'description' => $status === 'void'
                        ? 'Возврат ставки'
                        : sprintf('Выигрыш по ставке %s (коэф. %.2f)', $bet->selection, $bet->odds),
                    'occurred_at' => now(),
                ]);
            } else {
                BankrollEntry::create([
                    'bet_id' => $bet->id,
                    'type' => 'bet_lost',
                    'amount' => 0,
                    'balance_after' => $after,
                    'description' => sprintf('Проигрыш по ставке %s', $bet->selection),
                    'occurred_at' => now(),
                ]);
            }

            return $bet->refresh();
        });
    }

    /**
     * Определить исход ставки: won | lost | void.
     */
    public function outcomeFor(Bet $bet, Fight $fight, Result $result): string
    {
        if ($result->is_no_contest) {
            return 'void';
        }

        // Полное время боя в секундах
        $totalSeconds = $result->total_seconds
            ?? (($result->end_round ? ($result->end_round - 1) * 300 : 0) + (int) $result->end_time_seconds);

        return match ($bet->market) {
            'moneyline' => $this->moneylineOutcome($bet, $fight, $result),
            'draw' => $result->is_draw ? 'won' : 'lost',
            'totals' => $this->totalsOutcome($bet, $totalSeconds),
            'method' => $this->methodOutcome($bet, $result),
            default => 'void',
        };
    }

    private function moneylineOutcome(Bet $bet, Fight $fight, Result $result): string
    {
        if ($result->is_draw || ! $result->winner_id) {
            return 'void'; // ничья без рынка «ничья» — обычно возврат
        }

        $backedFighterId = match ($bet->selection) {
            'fighter1' => $fight->fighter1_id,
            'fighter2' => $fight->fighter2_id,
            default => $bet->fighter_id,
        };

        return $result->winner_id === $backedFighterId ? 'won' : 'lost';
    }

    /**
     * Тотал раундов: «больше 2.5» выигрывает, если бой продлился дольше
     * 2 минут 30 секунд третьего раунда (750 секунд чистого времени).
     */
    private function totalsOutcome(Bet $bet, int $totalSeconds): string
    {
        $threshold = (int) round(((float) ($bet->line ?? 2.5)) * 300);

        if ($totalSeconds === $threshold) {
            return 'void';
        }

        $wentOver = $totalSeconds > $threshold;

        return match ($bet->selection) {
            'over' => $wentOver ? 'won' : 'lost',
            'under' => $wentOver ? 'lost' : 'won',
            default => 'void',
        };
    }

    private function methodOutcome(Bet $bet, Result $result): string
    {
        if (! $result->method) {
            return 'void';
        }

        return $bet->selection === $result->method ? 'won' : 'lost';
    }

    /** Ручная корректировка баланса. */
    public function adjust(float $amount, string $description): float
    {
        $after = round($this->current() + $amount, 2);

        BankrollEntry::create([
            'type' => 'adjustment',
            'amount' => $amount,
            'balance_after' => $after,
            'description' => $description,
            'occurred_at' => now(),
        ]);

        return $after;
    }
}
