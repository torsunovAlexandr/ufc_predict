<?php

/**
 * Автономная проверка модели прогнозирования и модуля ставок.
 *
 * Скрипт не требует composer, базы данных и вообще Laravel — он подключает
 * только «чистые» классы предметной области. Удобно для быстрой проверки
 * математики после правки весов:
 *
 *     php backend/tools/model-selfcheck.php
 */

declare(strict_types=1);

// Заглушка env() — конфиг читается вне Laravel
if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

// Минимальный PSR-4 автозагрузчик для пространства имён App\
spl_autoload_register(function (string $class): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $path = __DIR__.'/../app/'.str_replace('\\', '/', substr($class, 4)).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\Betting\BankrollCalculator;
use App\Services\Betting\OddsQuote;
use App\Services\Prediction\FightContext;
use App\Services\Prediction\FighterProfile;
use App\Services\Prediction\PredictionEngine;

$config = require __DIR__.'/../config/ufc.php';

$assertions = 0;
$failures = 0;

function check(string $title, bool $condition, string $details = ''): void
{
    global $assertions, $failures;
    $assertions++;

    if ($condition) {
        echo "  ✓ {$title}\n";
    } else {
        $failures++;
        echo "  ✗ {$title}".($details ? " — {$details}" : '')."\n";
    }
}

// ---------------------------------------------------------------------------
// Профили для проверки: классический борец против классического ударника
// ---------------------------------------------------------------------------
$wrestler = FighterProfile::fromArray([
    'id' => 1,
    'name' => 'Борец',
    'age' => 28,
    'height_cm' => 180,
    'reach_cm' => 183,
    'stance' => 'orthodox',
    'takedowns_per_15' => 4.2,
    'takedown_accuracy' => 0.45,
    'sig_strikes_per_min' => 3.1,
    'striking_accuracy' => 0.44,
    'submission_attempts_per_15' => 1.2,
    'takedown_defense' => 0.80,
    'striking_defense' => 0.58,
    'sig_strikes_absorbed_per_min' => 2.6,
    'submission_defense' => 0.9,
    'cardio_index' => 0.72,
    'wins' => 18, 'losses' => 3, 'ufc_fights' => 10,
    'five_round_fights' => 2, 'title_fights' => 1,
    'wins_by_ko' => 4, 'wins_by_submission' => 6, 'wins_by_decision' => 8,
    'losses_by_ko' => 1, 'losses_by_submission' => 0, 'losses_by_decision' => 2,
    'recent_results' => ['win', 'win', 'win', 'loss', 'win'],
    'recent_loss_methods' => [null, null, null, 'decision', null],
    'style' => 'wrestler',
    'data_completeness' => 1.0,
]);

$striker = FighterProfile::fromArray([
    'id' => 2,
    'name' => 'Ударник',
    'age' => 34,
    'height_cm' => 178,
    'reach_cm' => 180,
    'stance' => 'orthodox',
    'takedowns_per_15' => 0.4,
    'takedown_accuracy' => 0.25,
    'sig_strikes_per_min' => 5.4,
    'striking_accuracy' => 0.51,
    'submission_attempts_per_15' => 0.2,
    'takedown_defense' => 0.42,
    'striking_defense' => 0.62,
    'sig_strikes_absorbed_per_min' => 3.4,
    'submission_defense' => 0.7,
    'cardio_index' => 0.55,
    'wins' => 20, 'losses' => 6, 'ufc_fights' => 12,
    'five_round_fights' => 1, 'title_fights' => 0,
    'wins_by_ko' => 14, 'wins_by_submission' => 1, 'wins_by_decision' => 5,
    'losses_by_ko' => 3, 'losses_by_submission' => 1, 'losses_by_decision' => 2,
    'recent_results' => ['win', 'loss', 'win', 'win', 'loss'],
    'recent_loss_methods' => [null, 'ko_tko', null, null, 'decision'],
    'style' => 'striker',
    'data_completeness' => 1.0,
]);

$engine = new PredictionEngine($config);

echo "\n=== Модель прогнозирования ===\n";

$context = new FightContext(scheduledRounds: 3, weightClass: 'Lightweight');
$prediction = $engine->predict($wrestler, $striker, $context);

check('Вероятности в сумме дают 1', abs($prediction->probabilityFighter1 + $prediction->probabilityFighter2 - 1) < 1e-4);
check('Вероятность в допустимых границах',
    $prediction->probabilityFighter1 > 0.05 && $prediction->probabilityFighter1 < 0.95,
    sprintf('P1 = %.3f', $prediction->probabilityFighter1));
check('Борец — фаворит против ударника со слабой защитой от тейкдаунов',
    $prediction->probabilityFighter1 > 0.5,
    sprintf('P1 = %.3f', $prediction->probabilityFighter1));
check('Сработало правило «борец против слабой защиты»',
    in_array('wrestler_vs_weak_td_defense', array_column($prediction->appliedRules, 'key'), true));
check('Сработало правило возраста (28 против 34)',
    in_array('age_advantage', array_column($prediction->appliedRules, 'key'), true));
check('Сумма весов факторов равна 1', abs(array_sum(array_column($prediction->factors, 'weight')) - 1.0) < 1e-6);
check('Объяснение не пустое и на русском',
    mb_strlen($prediction->explanation) > 60 && str_contains($prediction->explanation, 'Борец'));
check('Полнота данных близка к 1', $prediction->dataCompleteness > 0.9);

echo "\n  Вероятность победы борца: ".round($prediction->probabilityFighter1 * 100, 1)."%\n";
echo '  Уверенность: '.round($prediction->confidence * 100, 1)."%\n";
echo '  Объяснение: '.$prediction->explanation."\n";

// --- Симметрия: перестановка бойцов должна давать зеркальный результат ---
$mirrored = $engine->predict($striker, $wrestler, $context);
check('Модель симметрична при перестановке бойцов',
    abs($mirrored->probabilityFighter2 - $prediction->probabilityFighter1) < 1e-3,
    sprintf('%.5f против %.5f', $mirrored->probabilityFighter2, $prediction->probabilityFighter1));

// --- Одинаковые бойцы дают ровно 50/50 ---
$clone = FighterProfile::fromArray([
    'id' => 3, 'name' => 'Клон', 'age' => 28, 'height_cm' => 180, 'reach_cm' => 183,
    'stance' => 'orthodox', 'takedowns_per_15' => 4.2, 'takedown_accuracy' => 0.45,
    'sig_strikes_per_min' => 3.1, 'striking_accuracy' => 0.44, 'submission_attempts_per_15' => 1.2,
    'takedown_defense' => 0.80, 'striking_defense' => 0.58, 'sig_strikes_absorbed_per_min' => 2.6,
    'submission_defense' => 0.9, 'cardio_index' => 0.72,
    'wins' => 18, 'losses' => 3, 'ufc_fights' => 10, 'five_round_fights' => 2, 'title_fights' => 1,
    'wins_by_ko' => 4, 'wins_by_submission' => 6, 'wins_by_decision' => 8,
    'losses_by_ko' => 1, 'losses_by_submission' => 0, 'losses_by_decision' => 2,
    'recent_results' => ['win', 'win', 'win', 'loss', 'win'],
    'recent_loss_methods' => [null, null, null, 'decision', null],
    'style' => 'wrestler', 'data_completeness' => 1.0,
]);

$even = $engine->predict($wrestler, $clone, $context);
check('Одинаковые бойцы: ровно 50/50', abs($even->probabilityFighter1 - 0.5) < 1e-6,
    sprintf('P1 = %.6f', $even->probabilityFighter1));

// --- Пятираундовый бой без опыта пяти раундов ---
$rookie = FighterProfile::fromArray([
    'id' => 4, 'name' => 'Новичок', 'age' => 27, 'height_cm' => 180, 'reach_cm' => 183,
    'stance' => 'orthodox', 'takedowns_per_15' => 4.2, 'takedown_accuracy' => 0.45,
    'sig_strikes_per_min' => 3.1, 'striking_accuracy' => 0.44, 'submission_attempts_per_15' => 1.2,
    'takedown_defense' => 0.80, 'striking_defense' => 0.58, 'sig_strikes_absorbed_per_min' => 2.6,
    'submission_defense' => 0.9, 'cardio_index' => 0.72,
    'wins' => 18, 'losses' => 3, 'ufc_fights' => 10, 'five_round_fights' => 0,
    'wins_by_ko' => 4, 'wins_by_submission' => 6, 'wins_by_decision' => 8,
    'losses_by_ko' => 1, 'losses_by_submission' => 0, 'losses_by_decision' => 2,
    'recent_results' => ['win', 'win'], 'style' => 'wrestler', 'data_completeness' => 1.0,
]);

$fiveRound = $engine->predict($rookie, $wrestler, new FightContext(scheduledRounds: 5, isTitleFight: true));
check('Отсутствие опыта пяти раундов снижает вероятность',
    in_array('no_five_round_experience', array_column($fiveRound->appliedRules, 'key'), true)
    && $fiveRound->probabilityFighter1 < 0.5,
    sprintf('P1 = %.3f', $fiveRound->probabilityFighter1));

// --- Методы победы ---
$methods = $prediction->methodProbabilities;
check('Методы победы в сумме дают 1',
    abs(array_sum($methods['markets']) - 1) < 1e-3,
    sprintf('сумма = %.5f', array_sum($methods['markets'])));
check('Тотал: over + under = 1', abs($prediction->probabilityOver25 + $prediction->probabilityUnder25 - 1) < 1e-4);
check('У ударника нокаут вероятнее сабмишена',
    $methods['fighter2']['ko_tko'] > $methods['fighter2']['submission']);

// --- Очная встреча ---
$h2hContext = new FightContext(scheduledRounds: 3, headToHead: ['fighter1' => 0, 'fighter2' => 2, 'draws' => 0]);
$h2h = $engine->predict($wrestler, $striker, $h2hContext);
check('Две прошлые победы соперника снижают вероятность',
    $h2h->probabilityFighter1 < $prediction->probabilityFighter1,
    sprintf('%.3f против %.3f', $h2h->probabilityFighter1, $prediction->probabilityFighter1));

// ---------------------------------------------------------------------------
echo "\n=== Ставки и банкролл ===\n";

$calculator = new BankrollCalculator($config['bankroll']);

// Классический пример Келли: P = 0.6, K = 2.0 -> f = (0.6*2 - 1)/(2 - 1) = 0.2
check('Формула Келли считает верно', abs($calculator->rawKelly(0.6, 2.0) - 0.2) < 1e-9,
    sprintf('f = %.5f', $calculator->rawKelly(0.6, 2.0)));
check('EV считается верно', abs($calculator->expectedValue(0.6, 2.0) - 0.2) < 1e-9);
check('Отрицательный EV даёт отрицательный Келли', $calculator->rawKelly(0.4, 2.0) < 0);

$quote = new OddsQuote(market: 'moneyline', selection: 'fighter1', price: 2.20, bookmaker: 'test');
$recommendation = $calculator->evaluate($quote, 0.62, 10000.0);

check('Value-ставка найдена', $recommendation !== null);
check('Ставка не превышает 5% банка при обычной уверенности',
    $recommendation && $recommendation->stake <= 500.01,
    $recommendation ? sprintf('ставка = %.2f', $recommendation->stake) : 'нет ставки');
check('Сумма ставки округлена до рубля',
    $recommendation && abs($recommendation->stake - round($recommendation->stake)) < 1e-9);

$noValue = $calculator->evaluate(new OddsQuote('moneyline', 'fighter1', 1.40, 'test'), 0.62, 10000.0);
check('Ставка без value отклоняется', $noValue === null);

$lowOdds = $calculator->evaluate(new OddsQuote('moneyline', 'fighter1', 1.05, 'test'), 0.99, 10000.0);
check('Слишком низкий коэффициент отклоняется', $lowOdds === null);

$highConfidence = $calculator->evaluate(new OddsQuote('moneyline', 'fighter1', 1.60, 'test'), 0.85, 10000.0);
check('При P > 0.8 допускается до 10% банка',
    $highConfidence && $highConfidence->stake > 500 && $highConfidence->stake <= 1000.01,
    $highConfidence ? sprintf('ставка = %.2f', $highConfidence->stake) : 'нет ставки');

// Лимит на бой: несколько value-исходов не должны превысить 10% банка
$quotes = [
    [new OddsQuote('moneyline', 'fighter1', 2.20, 'test'), 0.62],
    [new OddsQuote('totals', 'under', 2.10, 'test', 2.5), 0.60],
    [new OddsQuote('method', 'ko_tko', 3.10, 'test'), 0.42],
];

$portfolio = $calculator->evaluateFight($quotes, 10000.0);
$totalStake = array_sum(array_map(fn ($r) => $r->stake, $portfolio));

check('Суммарная ставка на бой не превышает 10% банка', $totalStake <= 1000.01,
    sprintf('сумма = %.2f', $totalStake));
check('Приоритет у ставки с наибольшим EV',
    count($portfolio) === 0 || $portfolio[0]->expectedValue >= ($portfolio[1]->expectedValue ?? -1));

echo "\n  Рекомендаций на бой: ".count($portfolio).', общая сумма '.round($totalStake)." ₽\n";
foreach ($portfolio as $r) {
    printf("    %s/%s — коэф. %.2f, P=%.2f, EV=%.3f, ставка %d ₽\n",
        $r->market, $r->selection, $r->odds, $r->modelProbability, $r->expectedValue, $r->stake);
}

// ---------------------------------------------------------------------------
echo "\n".str_repeat('-', 60)."\n";
echo $failures === 0
    ? "Все проверки пройдены: {$assertions}\n"
    : "Проверок: {$assertions}, из них не пройдено: {$failures}\n";

exit($failures === 0 ? 0 : 1);
