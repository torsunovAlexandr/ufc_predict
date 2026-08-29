# Xdebug в PhpStorm

Настройка отладки бекенда, который работает внутри Docker. Один раз пройти
всю инструкцию — минут пятнадцать; дальше отладка включается одной командой.

Xdebug уже собран в образ (`docker/php/Dockerfile`), но по умолчанию выключен,
чтобы не замедлять обычную работу.

---

## Шаг 0. Пересобрать образ

Xdebug появился в проекте позже первой сборки, поэтому образ нужно пересобрать:

```bash
docker compose build php
make debug-on
```

Проверить, что расширение на месте:

```bash
docker compose exec php php -v
```

В выводе должна быть строка `with Xdebug v3.x.x`. И проверить режим:

```bash
docker compose exec php php -i | grep 'xdebug.mode'
```

Должно быть `debug` после `make debug-on` и `off` после `make debug-off`.

---

## Шаг 1. Интерпретер PHP из контейнера

**Settings → PHP**

1. Напротив «CLI Interpreter» нажмите `...` → `+` → **From Docker, Vagrant, VM, WSL, Remote…**
2. Выберите **Docker Compose**.
3. Configuration files: `docker-compose.yml` из корня проекта.
4. Service: **php**.
5. OK. PhpStorm запустит контейнер и определит версию — в поле должно появиться
   что-то вроде `PHP 8.4.x, debugger: Xdebug 3.x.x`.

Если отладчик не определился, значит образ собран без Xdebug — вернитесь к шагу 0.

---

## Шаг 2. Сервер и сопоставление путей

Это самая частая причина, по которой точки останова «не срабатывают»: PhpStorm
получает от Xdebug путь внутри контейнера и не понимает, какому файлу на диске
он соответствует.

**Settings → PHP → Servers → `+`**

| Поле | Значение |
|---|---|
| Name | `ufc-predict` |
| Host | `localhost` |
| Port | `8080` |
| Debugger | Xdebug |
| Use path mappings | включить |

В дереве файлов сопоставьте **только папку `backend`**:

```
<корень проекта>/backend   →   /var/www/backend
```

Имя сервера должно быть ровно `ufc-predict` — оно же прописано в
`docker-compose.yml` как `PHP_IDE_CONFIG: serverName=ufc-predict`. Именно эта
переменная позволяет отлаживать консольные команды, где HTTP-запроса нет
и сервер выбрать не из чего.

---

## Шаг 3. Порт отладчика

**Settings → PHP → Debug**

- Xdebug → Debug port: **9003** (порт Xdebug 3; в Xdebug 2 был 9000).
- Включите **Can accept external connections**.
- «Force break at first line without breakpoints» лучше выключить — иначе
  отладчик будет останавливаться на каждом запросе, включая служебные.

---

## Шаг 4. Отладка веб-запросов

1. Включите Xdebug: `make debug-on`.
2. В PhpStorm нажмите значок телефона — **Start Listening for PHP Debug Connections**.
3. Поставьте точку останова, например в `app/Http/Controllers/Api/DashboardController.php`.
4. Откройте страницу с триггером:

   ```
   http://localhost:8080/api/dashboard?XDEBUG_TRIGGER=1
   ```

Отладка настроена на запуск **по триггеру**: без параметра `XDEBUG_TRIGGER`
(или cookie `XDEBUG_SESSION`) Xdebug не пытается соединиться с IDE. Это удобно —
приложение работает с обычной скоростью, пока вы сами не попросите отладку.

### Чтобы не дописывать параметр руками

Поставьте в браузер расширение **Xdebug helper** (Chrome) или **Xdebug Helper**
(Firefox), в его настройках укажите IDE key `PHPSTORM` и включайте отладку
кнопкой. Расширение ставит cookie, которая уходит и с обычными запросами,
и с XHR-запросами фронтенда к `/api` — то есть точки останова сработают
при обычной работе с интерфейсом.

Это же работает и через Vite на `localhost:5173`: cookie не различает порты,
а dev-сервер проксирует её на бекенд.

---

## Шаг 5. Отладка artisan-команд

Здесь HTTP-запроса нет, поэтому сервер определяется переменной `PHP_IDE_CONFIG`,
которая уже задана в `docker-compose.yml`.

```bash
make shell
XDEBUG_TRIGGER=1 php artisan ufc:predict --recommend
```

Или одной строкой:

```bash
docker compose exec -e XDEBUG_TRIGGER=1 php php artisan ufc:predict --recommend
```

Не забудьте, что IDE должна слушать соединения (значок телефона).

Точки останова удобно ставить в `PredictionEngine::predict()` — там видно,
как складываются факторы, применяются экспертные правила и получается
итоговая вероятность.

---

## Шаг 6. Запуск тестов из IDE

**Settings → PHP → Test Frameworks → `+` → PHPUnit by Remote Interpreter**

1. Interpreter: тот, что создали на шаге 1.
2. PHPUnit library: **Use Composer autoloader**,
   путь: `/var/www/backend/vendor/autoload.php`.
3. Default configuration file: `/var/www/backend/phpunit.xml`.

После этого тесты запускаются прямо из редактора, а с включённым Xdebug —
с точками останова.

Покрытие кода требует отдельного режима:

```bash
docker compose exec -e XDEBUG_MODE=coverage php php artisan test --coverage
```

---

## Если точка останова не срабатывает

Проверяйте по порядку — почти всегда дело в одном из первых трёх пунктов.

| Что проверить | Как |
|---|---|
| Xdebug включён | `docker compose exec php php -i \| grep xdebug.mode` → должно быть `debug` |
| IDE слушает | значок телефона в PhpStorm нажат |
| Сопоставление путей | Settings → PHP → Servers, `backend` → `/var/www/backend` |
| Триггер передан | `?XDEBUG_TRIGGER=1` в URL или включённое расширение в браузере |
| Xdebug достучался до IDE | `backend/storage/logs/xdebug.log` — там пишутся ошибки соединения |
| Порт свободен | `lsof -i :9003` — если занят другим процессом, PhpStorm не сможет слушать |

Типовые сообщения в `xdebug.log`:

- `Could not connect to debugging client` — IDE не слушает порт либо
  `host.docker.internal` не резолвится. На Docker Desktop он работает
  из коробки, на Linux его добавляет `extra_hosts` в `docker-compose.yml`.
- `Connected to client` есть, но остановки нет — почти наверняка неверное
  сопоставление путей: PhpStorm не связал `/var/www/backend/...` с файлом
  на диске.

---

## Выключить, когда отладка не нужна

```bash
make debug-off
```

Xdebug заметно замедляет PHP даже в режиме `debug` без активной сессии,
а `make test` с ним идёт в разы дольше. Держать постоянно включённым стоит,
только пока вы действительно отлаживаете.

Чтобы отладчик включался сам при каждом старте, добавьте в корневой `.env`:

```
XDEBUG_MODE=debug
```

Файл создаётся командой `make env`; строка там уже есть, её нужно раскомментировать.
