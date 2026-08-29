# Xdebug в PhpStorm

Настройка отладки бекенда, который работает внутри Docker. Один раз пройти
всю инструкцию — минут пятнадцать; дальше отладка включается одной командой.

Xdebug уже собран в образ (`docker/php/Dockerfile`), но по умолчанию выключен,
чтобы не замедлять обычную работу.

---

## Шаг 0. Собрать образ с Xdebug

```bash
make debug-on
```

Команда сама пересоберёт образ, если Xdebug в нём ещё нет, поднимет контейнеры
и напечатает результат проверки. В выводе должна быть строка `with Xdebug v3.x.x`
и `xdebug.mode = debug`.

Проверить в любой момент:

```bash
make debug-check
```

Если написано «Xdebug НЕ установлен в образе» — значит сборка не прошла;
посмотрите вывод `docker compose build php` целиком, там будет причина.

**Про `--force-recreate` и сборку.** `docker compose up --force-recreate`
пересоздаёт контейнеры из **существующего** образа и НЕ пересобирает его.
Если менялся `Dockerfile`, нужен именно `docker compose build php` —
раньше `make debug-on` этого не делал, и Xdebug не появлялся.

---

## Шаг 0.5. Если вместо Docker Desktop у вас OrbStack, Colima или Podman

Симптом:

```
Cannot connect to the Docker daemon at unix:///var/run/docker.sock.
Is the docker daemon running?
```

В терминале при этом всё работает. Причина в том, что PhpStorm — приложение
с графическим интерфейсом: оно не наследует переменные окружения из вашего
shell-профиля и не знает про `DOCKER_HOST` и docker-контексты. Оно идёт
по жёсткому пути `/var/run/docker.sock`, которого у альтернативных рантаймов
может не быть: OrbStack создаёт там символическую ссылку только если при
установке получил права администратора.

**Узнайте настоящий путь к сокету:**

```bash
docker context ls
docker context inspect "$(docker context show)" --format '{{.Endpoints.docker.Host}}'
ls -l /var/run/docker.sock          # есть ли ссылка и не битая ли она
```

Типичные пути:

| Рантайм | Сокет |
|---|---|
| OrbStack | `~/.orbstack/run/docker.sock` |
| Colima | `~/.colima/default/docker.sock` |
| Podman (macOS) | `~/.local/share/containers/podman/machine/podman.sock` |
| Docker Desktop | `/var/run/docker.sock` |

**Пропишите его в PhpStorm:**

Settings → Build, Execution, Deployment → **Docker** → `+` → выберите
**TCP socket** и в поле **Engine API URL** укажите путь с префиксом `unix://`
и **абсолютным** путём (тильда не раскроется):

```
unix:///Users/<ваш-пользователь>/.orbstack/run/docker.sock
```

Несмотря на название «TCP socket», это поле принимает и unix-сокеты — так
описано в [документации JetBrains](https://www.jetbrains.com/help/phpstorm/settings-docker.html).
Внизу диалога должно появиться «Connection successful».

После этого возвращайтесь к шагу 1: без рабочего подключения к Docker
интерпретер из Docker Compose не создаётся вообще, и Xdebug определить не из чего.

**Альтернатива для OrbStack:** восстановить стандартную ссылку, чтобы её видели
все инструменты сразу. Она создаётся при установке с правами администратора —
проще всего заново пройти настройку OrbStack и согласиться на запрос прав.
Проверка: `ls -l /var/run/docker.sock` должен показывать ссылку
на `~/.orbstack/run/docker.sock`.

**Про `host.docker.internal`.** OrbStack поддерживает это имя, как и Docker
Desktop, плюс в `docker-compose.yml` оно продублировано через `extra_hosts`.
Если Xdebug не сможет достучаться до IDE, это первое, что стоит проверить —
подробности в разделе «Если точка останова не срабатывает».

---

## Шаг 0.6. Ошибка «client version 1.24 is too old»

```
Status 400: client version 1.24 is too old.
Minimum supported API version is 1.40, please upgrade your client
```

Подключение к демону уже работает — не сходятся версии Docker Engine API.
Свежие версии движка (28-я подняла минимум до 1.40, 29-я до 1.44) больше
не принимают старые клиенты, а Docker-клиент внутри JetBrains-IDE по-прежнему
представляется версией 1.24. Это баг самой IDE, а не проекта:
[IJPL-217878](https://youtrack.jetbrains.com/projects/IJPL/issues/IJPL-217878),
на момент написания не закрыт.

**Сначала — важное:** для пошаговой отладки Docker-интеграция в PhpStorm
**не нужна вообще**. Xdebug сам соединяется с IDE по TCP 9003, а IDE только
слушает порт и сопоставляет пути. Так что шаг 1 можно пропустить и сразу идти
к шагам 2–5 — точки останова будут работать. Интерпретер из контейнера нужен
лишь для запуска тестов из редактора и для подсказок по версии PHP.

**Если интерпретер всё-таки нужен**, разрешите движку принимать старый клиент.
В OrbStack конфигурация демона лежит в `~/.orbstack/config/docker.json`:

```bash
orb config docker          # откроет конфиг в редакторе
```

Добавьте ключ:

```json
{
    "min-api-version": "1.24"
}
```

Примените и проверьте:

```bash
orb restart docker
docker version
```

Для Docker Desktop то же самое делается в Settings → Docker Engine, для Linux —
в `/etc/docker/daemon.json` с последующим `systemctl restart docker`.

Это ослабляет ограничение движка, введённое ради безопасности: снова
разрешаются запросы по устаревшей версии API. На локальной машине для разработки
это приемлемо, но держать такую настройку на сервере не стоит. Как только
JetBrains закроют IJPL-217878 и вы обновите IDE — ключ можно убрать.

Третий вариант, если менять конфигурацию движка не хочется: обновить PhpStorm
до версии, где баг уже исправлен. Проверьте в трекере, в каком релизе он закрыт.

---

## Шаг 1. Интерпретер PHP из контейнера

> Нужен для запуска тестов из редактора и подсказок по версии PHP.
> **Для точек останова он не обязателен** — если Docker-интеграция капризничает,
> переходите сразу к шагу 2.

**Settings → PHP**

1. Напротив «CLI Interpreter» нажмите `...` → `+` → **From Docker, Vagrant, VM, WSL, Remote…**
2. Выберите **Docker Compose**.
3. Configuration files: `docker-compose.yml` из корня проекта.
4. Service: **php**.
5. OK. PhpStorm запустит контейнер и определит версию — в поле должно появиться
   что-то вроде `PHP 8.4.x, debugger: Xdebug 3.x.x`.

### Если PhpStorm не показывает Xdebug

Поле должно выглядеть так: `PHP 8.4.x, debugger: Xdebug 3.x.x`. Если отладчика там нет:

| Проверка | Как |
|---|---|
| Расширение вообще в образе | `make debug-check` — если его нет, дело не в IDE |
| Образ пересобран после правки Dockerfile | `docker compose build php` (одного `up --force-recreate` мало) |
| PhpStorm не закэшировал старое значение | в диалоге интерпретера нажмите ↻ рядом с полем версии |
| PhpStorm подключён к тому же Docker | Settings → Build, Execution, Deployment → Docker, статус «Connected» |
| Вывод контейнера чистый | `docker compose run --rm --no-deps php php -v` — перед строкой `PHP 8.4.x` не должно быть ничего лишнего |

Последний пункт неочевиден, но ломал определение: PhpStorm разбирает вывод
`php -v`, и если entrypoint печатал в него свои сообщения о подготовке окружения,
IDE не находила ни версию, ни отладчик. Сейчас entrypoint выполняет разовые
команды молча — подготовка идёт только для `php-fpm` и планировщика.

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
