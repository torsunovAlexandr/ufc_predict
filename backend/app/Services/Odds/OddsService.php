<?php

namespace App\Services\Odds;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Odd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сбор котировок: опрашивает доступных поставщиков, сопоставляет
 * их данные с боями в БД и сохраняет коэффициенты.
 *
 * История котировок сохраняется: предыдущие записи по бою помечаются
 * is_latest = false, актуальные — true.
 */
class OddsService
{
    /** @param array<int, OddsProvider> $providers в порядке приоритета */
    public function __construct(private readonly array $providers) {}

    /**
     * Обновить коэффициенты по турниру.
     *
     * @return array{stored: int, matched: int, provider: string|null, unmatched: array<int, string>}
     */
    public function refreshForEvent(Event $event): array
    {
        $event->loadMissing('fights.fighter1', 'fights.fighter2');

        foreach ($this->providers as $provider) {
            if (!$provider->isAvailable()) {
                continue;
            }

            $quotes = $provider->fetchForEvent($event);

            if ($quotes === []) {
                continue;
            }

            return $this->store($event, $quotes, $provider->name());
        }

        Log::channel('scraping')->warning('Ни один поставщик коэффициентов не вернул данные.');

        return ['stored' => 0, 'matched' => 0, 'provider' => null, 'unmatched' => []];
    }

    /**
     * @param  array<int, array<string, mixed>>  $quotes
     * @return array{stored: int, matched: int, provider: string, unmatched: array<int, string>}
     */
    public function store(Event $event, array $quotes, string $source): array
    {
        $stored = 0;
        $matchedFights = [];
        $unmatched = [];

        DB::transaction(function () use ($event, $quotes, $source, &$stored, &$matchedFights, &$unmatched) {
            foreach ($quotes as $quote) {
                $fight = $this->matchFight($event, $quote);

                if (! $fight) {
                    $unmatched[$quote['fighter1'].' — '.$quote['fighter2']] = true;

                    continue;
                }

                // Котировки могут прийти в порядке, обратном нашему —
                // выравниваем selection под порядок бойцов в БД
                $selection = $this->alignSelection($fight, $quote);

                if ($selection === null) {
                    continue;
                }

                // Прежние котировки этого рынка у этого букмекера — уже не актуальны
                Odd::where('fight_id', $fight->id)
                    ->where('bookmaker', $quote['bookmaker'])
                    ->where('market', $quote['market'])
                    ->where('selection', $selection)
                    ->when($quote['line'] !== null, fn ($q) => $q->where('line', $quote['line']))
                    ->update(['is_latest' => false]);

                Odd::create([
                    'fight_id' => $fight->id,
                    'fighter_id' => match ($selection) {
                        'fighter1' => $fight->fighter1_id,
                        'fighter2' => $fight->fighter2_id,
                        default => null,
                    },
                    'bookmaker' => $quote['bookmaker'],
                    'market' => $quote['market'],
                    'selection' => $selection,
                    'line' => $quote['line'],
                    'price' => $quote['price'],
                    'implied_probability' => $quote['price'] > 0 ? round(1 / $quote['price'], 5) : null,
                    'source' => $source,
                    'is_latest' => true,
                    'fetched_at' => now(),
                ]);

                $stored++;
                $matchedFights[$fight->id] = true;
            }
        });

        return [
            'stored' => $stored,
            'matched' => count($matchedFights),
            'provider' => $source,
            'unmatched' => array_keys($unmatched),
        ];
    }

    /** @param array<string, mixed> $quote */
    private function matchFight(Event $event, array $quote): ?Fight
    {
        $a = $this->normalize($quote['fighter1'] ?? '');
        $b = $this->normalize($quote['fighter2'] ?? '');

        foreach ($event->fights as $fight) {
            $n1 = $this->normalize($fight->fighter1?->name ?? '');
            $n2 = $this->normalize($fight->fighter2?->name ?? '');

            if (($a === $n1 && $b === $n2) || ($a === $n2 && $b === $n1)) {
                return $fight;
            }

            // Запасное сопоставление по фамилиям
            if ($this->lastName($a) === $this->lastName($n1) && $this->lastName($b) === $this->lastName($n2)) {
                return $fight;
            }

            if ($this->lastName($a) === $this->lastName($n2) && $this->lastName($b) === $this->lastName($n1)) {
                return $fight;
            }
        }

        return null;
    }

    /**
     * У поставщика «fighter1» — это его первый боец, который может быть
     * нашим вторым. Приводим выбор к порядку бойцов в нашей БД.
     *
     * @param  array<string, mixed>  $quote
     */
    private function alignSelection(Fight $fight, array $quote): ?string
    {
        $selection = $quote['selection'];

        if (! in_array($selection, ['fighter1', 'fighter2'], true)) {
            return $selection; // over/under/draw/method порядок не затрагивает
        }

        $providerName = $selection === 'fighter1' ? $quote['fighter1'] : $quote['fighter2'];
        $providerName = $this->normalize($providerName);

        $ours1 = $this->normalize($fight->fighter1?->name ?? '');
        $ours2 = $this->normalize($fight->fighter2?->name ?? '');

        if ($providerName === $ours1 || $this->lastName($providerName) === $this->lastName($ours1)) {
            return 'fighter1';
        }

        if ($providerName === $ours2 || $this->lastName($providerName) === $this->lastName($ours2)) {
            return 'fighter2';
        }

        return null;
    }

    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\p{L}\s]/u', '', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function lastName(string $name): string
    {
        $parts = explode(' ', $name);

        return end($parts) ?: $name;
    }

    /**
     * Ручной ввод коэффициентов — используется, когда API недоступен.
     *
     * @param  array<int, array{market: string, selection: string, line?: float|null, price: float, bookmaker?: string}>  $quotes
     */
    public function storeManual(Fight $fight, array $quotes): int
    {
        $stored = 0;

        DB::transaction(function () use ($fight, $quotes, &$stored) {
            foreach ($quotes as $quote) {
                Odd::where('fight_id', $fight->id)
                    ->where('market', $quote['market'])
                    ->where('selection', $quote['selection'])
                    ->when(isset($quote['line']), fn ($q) => $q->where('line', $quote['line']))
                    ->update(['is_latest' => false]);

                Odd::create([
                    'fight_id' => $fight->id,
                    'fighter_id' => match ($quote['selection']) {
                        'fighter1' => $fight->fighter1_id,
                        'fighter2' => $fight->fighter2_id,
                        default => null,
                    },
                    'bookmaker' => $quote['bookmaker'] ?? 'ручной ввод',
                    'market' => $quote['market'],
                    'selection' => $quote['selection'],
                    'line' => $quote['line'] ?? null,
                    'price' => $quote['price'],
                    'implied_probability' => $quote['price'] > 0 ? round(1 / $quote['price'], 5) : null,
                    'source' => 'manual',
                    'is_latest' => true,
                    'fetched_at' => now(),
                ]);

                $stored++;
            }
        });

        return $stored;
    }
}
