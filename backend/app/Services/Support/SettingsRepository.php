<?php

namespace App\Services\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Настройки пользователя. Значения по умолчанию берутся из config/ufc.php,
 * переопределения хранятся в таблице `settings`.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'ufc.settings.all';

    /** Описание всех поддерживаемых настроек: тип, группа, значение по умолчанию. */
    public function definitions(): array
    {
        $bankroll = config('ufc.bankroll');

        return [
            'starting_bankroll' => ['float', 'bankroll', 'Стартовый банкролл, ₽', $bankroll['starting']],
            'min_ev' => ['float', 'betting', 'Порог value (EV)', $bankroll['min_ev']],
            'kelly_fraction' => ['float', 'betting', 'Множитель Келли', $bankroll['kelly_fraction']],
            'max_stake_fraction' => ['float', 'betting', 'Максимум на ставку, доля банка', $bankroll['max_stake_fraction']],
            'max_stake_fraction_high_conf' => ['float', 'betting', 'Максимум при высокой уверенности', $bankroll['max_stake_fraction_high_conf']],
            'max_fraction_per_fight' => ['float', 'betting', 'Максимум на один бой', $bankroll['max_fraction_per_fight']],
            'min_stake_fraction' => ['float', 'betting', 'Минимальная доля банка', $bankroll['min_stake_fraction']],
            'min_odds' => ['float', 'betting', 'Минимальный коэффициент', $bankroll['min_odds']],
            'max_odds' => ['float', 'betting', 'Максимальный коэффициент', $bankroll['max_odds']],
            'auto_place_bets' => ['boolean', 'betting', 'Размещать рекомендованные ставки автоматически', false],
            'theme' => ['string', 'interface', 'Тема оформления', 'dark'],
            'odds_provider' => ['string', 'sources', 'Источник коэффициентов', config('ufc.odds.primary')],
            'model_weights' => ['json', 'model', 'Веса показателей модели', config('ufc.weights')],
            'score_scale' => ['float', 'model', 'Масштаб сигмоиды', config('ufc.score_scale')],
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = Setting::all()->mapWithKeys(fn (Setting $s) => [$s->key => $s->typedValue()])->all();

            $values = [];
            foreach ($this->definitions() as $key => [$type, $group, $label, $default]) {
                $values[$key] = $stored[$key] ?? $default;
            }

            return $values;
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $definition = $this->definitions()[$key] ?? null;

        if (! $definition) {
            return;
        }

        [$type, $group, $label] = $definition;

        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $type === 'json' ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $this->stringify($value),
                'type' => $type,
                'group' => $group,
                'label' => $label,
            ]
        );

        $this->flush();
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Конфигурация предметной области с учётом пользовательских настроек.
     * Именно её получают PredictionEngine и BankrollCalculator.
     *
     * @return array<string, mixed>
     */
    public function domainConfig(): array
    {
        $config = config('ufc');
        $settings = $this->all();

        $config['weights'] = $this->normalizeWeights($settings['model_weights'] ?? $config['weights']);
        $config['score_scale'] = (float) ($settings['score_scale'] ?? $config['score_scale']);

        foreach ([
            'starting' => 'starting_bankroll',
            'min_ev' => 'min_ev',
            'kelly_fraction' => 'kelly_fraction',
            'max_stake_fraction' => 'max_stake_fraction',
            'max_stake_fraction_high_conf' => 'max_stake_fraction_high_conf',
            'max_fraction_per_fight' => 'max_fraction_per_fight',
            'min_stake_fraction' => 'min_stake_fraction',
            'min_odds' => 'min_odds',
            'max_odds' => 'max_odds',
        ] as $configKey => $settingKey) {
            if (isset($settings[$settingKey])) {
                $config['bankroll'][$configKey] = (float) $settings[$settingKey];
            }
        }

        return $config;
    }

    /**
     * Веса всегда приводятся к сумме 1.0 — иначе балл модели выйдет
     * за пределы [-1, 1] и сигмоида даст искажённые вероятности.
     *
     * @param  array<string, float|string>  $weights
     * @return array<string, float>
     */
    public function normalizeWeights(array $weights): array
    {
        $weights = array_map('floatval', $weights);
        $sum = array_sum($weights);

        if ($sum <= 0) {
            return config('ufc.weights');
        }

        return array_map(fn (float $w): float => round($w / $sum, 6), $weights);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
