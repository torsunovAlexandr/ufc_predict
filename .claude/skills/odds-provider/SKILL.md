---
name: odds-provider
description: Подключение нового источника букмекерских коэффициентов — API агрегатора, парсер сайта БК или импорт из файла. Использовать при задачах «добавь букмекера», «подключи API коэффициентов», «коэффициенты не приклеиваются к боям», «замени The Odds API».
---

# Новый источник коэффициентов

Источники подключаются через интерфейс `App\Services\Odds\OddsProvider`.
Остальной код — сопоставление с боями, версионирование, поиск value-ставок —
менять не нужно.

## Что уже есть

| Класс | Что делает |
|---|---|
| `TheOddsApiProvider` | The Odds API, основной источник, бесплатный ключ |
| `BookmakerScraperProvider` | парсер сайтов БК, по умолчанию выключен |
| `OddsService::storeManual()` | ручной ввод из интерфейса |

Приоритет задан списком в `AppServiceProvider`: `OddsService` опрашивает поставщиков
по очереди и останавливается на первом, вернувшем непустой результат.

## Порядок действий

### 1. Класс поставщика

Создайте `backend/app/Services/Odds/ВашProvider.php`:

```php
class PinnacleProvider implements OddsProvider
{
    public function __construct(private readonly HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'pinnacle';   // попадёт в odds.source
    }

    public function isAvailable(): bool
    {
        // ключ на месте и дневная квота не исчерпана
        return (bool) config('services.pinnacle.key')
            && $this->fetcher->requestsToday($this->name()) < (int) config('services.pinnacle.daily_limit');
    }

    public function fetchForEvent(Event $event): array
    {
        // ...
    }
}
```

**Ходить в сеть только через `HttpFetcher`** — он даёт кэш, паузы между запросами,
учёт дневных квот и журнал в `scrape_logs`. Прямой Guzzle в проекте запрещён.

```php
$response = $this->fetcher->fetchJson($this->name(), $url, $query, ttlMinutes: 60);
$html = $this->fetcher->fetch($this->name(), $url, ttlHours: 1);
```

### 2. Формат возвращаемых данных

`fetchForEvent()` возвращает плоский массив котировок. Каждая — такой формы:

```php
[
    'fighter1' => 'Alex Volkanovski',   // имена как их отдал источник
    'fighter2' => 'Ilia Topuria',
    'commence_time' => '2026-09-12T22:00:00Z',   // или null
    'bookmaker' => 'pinnacle',
    'market' => 'moneyline',            // moneyline | draw | totals | method
    'selection' => 'fighter1',          // fighter1 | fighter2 | draw | over | under | ko_tko | submission | decision
    'line' => null,                     // 2.5 для тотала раундов
    'price' => 1.85,                    // ДЕСЯТИЧНЫЙ коэффициент
]
```

Три вещи, на которых спотыкаются:

- **Коэффициент только десятичный.** Американские (`-150`, `+130`) и дробные
  конвертируйте у себя в провайдере. Формулы: `american > 0 → 1 + a/100`,
  `american < 0 → 1 + 100/|a|`.
- **`fighter1` / `fighter2` — это порядок источника, а не наш.** Подставляйте имена
  так, как их вернул источник; выравниванием займётся `OddsService::alignSelection()`,
  сопоставляя по полному имени и по фамилии. Если этого не сделать, коэффициенты
  молча приклеятся не к тому бойцу.
- **Цена ≤ 1.0 — мусор**, отбрасывайте прямо в провайдере.

### 3. Конфигурация

Ключи и лимиты — в `backend/config/services.php`:

```php
'pinnacle' => [
    'key' => env('PINNACLE_KEY'),
    'base_url' => env('PINNACLE_BASE_URL', 'https://api.example.com/v1'),
    'daily_limit' => (int) env('PINNACLE_DAILY_LIMIT', 500),
],
```

Переменные добавьте в `backend/.env.example` с пустым значением и комментарием,
где взять ключ. Реальные ключи в репозиторий не попадают.

Если у источника свои ограничения по частоте — пропишите их в `config/ufc.php`,
секция `sources`, ключом по имени провайдера: `request_delay_seconds`,
`page_ttl_hours`, `source_interval_minutes`. `HttpFetcher` подхватит их сам.

### 4. Регистрация

В `AppServiceProvider::register()`, в списке `OddsService`. Порядок = приоритет:

```php
$this->app->singleton(OddsService::class, fn ($app) => new OddsService([
    $app->make(PinnacleProvider::class),
    $app->make(TheOddsApiProvider::class),
    $app->make(BookmakerScraperProvider::class),
]));
```

Если источник должен выбираться пользователем, добавьте вариант в
`SettingsRepository::definitions()` (ключ `odds_provider`), в валидацию
`SettingsController::update()` и в выпадающий список в
`frontend/src/views/SettingsView.vue`.

### 5. Проверка

```bash
make odds
make logs
```

Затем сверьтесь с базой:

```bash
docker compose exec php php artisan tinker --execute="
  \$odds = \App\Models\Odd::where('source','pinnacle')->where('is_latest',true)->with('fight.fighter1','fight.fighter2')->take(5)->get();
  foreach (\$odds as \$o) {
      echo \$o->fight->title().' | '.\$o->market.'/'.\$o->selection.' = '.\$o->price.PHP_EOL;
  }
"
```

Проверьте главное: **коэффициент фаворита должен стоять у того бойца, который
действительно фаворит.** Перепутанный порядок — самая частая и самая незаметная
ошибка при подключении источника.

Команда печатает список несопоставленных пар — если он длинный, проблема
в написании имён; смотрите `OddsService::matchFight()`, там есть запасное
сопоставление по фамилиям.

## Про парсинг сайтов букмекеров

`BookmakerScraperProvider` выключен по умолчанию, и на это две причины:
линия у большинства БК подгружается скриптами (простой HTML-парсер увидит пустой
каркас), а правила сайтов обычно прямо запрещают автоматический сбор данных.

Если владелец всё же просит включить:

- ограничение «не чаще 1 запроса в 10 минут на сайт» соблюдается блокировкой
  в кэше (`acquireLock`) — не ослабляйте его;
- не добавляйте headless-браузер без явной просьбы: это тяжёлая зависимость
  ради задачи, которая решается ручным вводом;
- напомните, что у ручного ввода нет ни одного из этих рисков и он уже работает.

## Импорт из файла

Отдельный класс-провайдер для этого не нужен — проще добавить artisan-команду,
которая читает CSV и вызывает `OddsService::storeManual($fight, $quotes)`.
Этот метод сам сбросит `is_latest` у прежних котировок и проставит источник `manual`.
