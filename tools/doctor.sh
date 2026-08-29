#!/bin/sh
# Диагностика окружения. Восемь проверок из docs/stage-0.md, раздел 0.3.F.
#
# Каждая проверка печатает OK либо причину и подсказку, что сделать.
# Ненулевой код возврата при любой неудаче — именно поэтому скрипт можно
# гонять в CI, а не только глазами.
#
# Запускается из корня репозитория: make doctor
set -u

FAILED=0
WARNED=0
CHECK=0

DC="docker compose"

green()  { printf '\033[32m%s\033[0m' "$1"; }
red()    { printf '\033[31m%s\033[0m' "$1"; }
dim()    { printf '\033[2m%s\033[0m' "$1"; }

begin() {
    CHECK=$((CHECK + 1))
    printf '  %d. %-46s ' "$CHECK" "$1"
}

ok() {
    green "OK"
    if [ $# -gt 0 ] && [ -n "$1" ]; then printf '  '; dim "$1"; fi
    printf '\n'
}

# Предупреждение — это не провал. Оно нужно там, где ответ зависит не от
# состояния машины, а от того, откуда сделан запрос: в CI такая проверка
# обязана оставаться зелёной, а разработчику — быть на виду.
warn() {
    printf '\033[33m%s\033[0m' "ВНИМАНИЕ"
    printf '\n'
    printf '     %s\n' "$1"
    shift
    for hint in "$@"; do
        printf '     '
        dim "→ $hint"
        printf '\n'
    done
    WARNED=$((WARNED + 1))
}

fail() {
    red "НЕТ"
    printf '\n'
    printf '     %s\n' "$1"
    shift
    for hint in "$@"; do
        printf '     '
        dim "→ $hint"
        printf '\n'
    done
    FAILED=$((FAILED + 1))
}

env_value() {
    key="$1"
    file="$2"
    fallback="$3"
    val=""
    if [ -f "$file" ]; then
        val=$(sed -n "s/^[[:space:]]*${key}=//p" "$file" | head -n1 | tr -d '\r')
    fi
    [ -n "$val" ] || val="$fallback"
    printf '%s' "$val"
}

APP_PORT=$(env_value APP_PORT .env 8080)
DB_PORT_EXTERNAL=$(env_value DB_PORT_EXTERNAL .env 33061)
DB_DATABASE=$(env_value DB_DATABASE backend/.env ufc_predict)
DB_USERNAME=$(env_value DB_USERNAME backend/.env ufc)
DB_PASSWORD=$(env_value DB_PASSWORD backend/.env secret)

printf '\n  UFC Predict — диагностика\n\n'

# --- 1. Docker --------------------------------------------------------------

begin "Docker установлен и демон отвечает"
if ! command -v docker >/dev/null 2>&1; then
    fail "Команда docker не найдена." \
         "Установите Docker Desktop или OrbStack."
elif ! docker info >/dev/null 2>&1; then
    fail "Демон Docker не отвечает." \
         "Запустите Docker Desktop / OrbStack и повторите."
else
    ok "$(docker --version | cut -d, -f1)"
fi

# --- 2. Контейнеры ----------------------------------------------------------

begin "Четыре контейнера подняты, MySQL healthy"
if ! docker info >/dev/null 2>&1; then
    fail "Пропущено: демон Docker не отвечает."
else
    MISSING=""
    for name in ufc-nginx ufc-php ufc-mysql ufc-scheduler; do
        state=$(docker inspect -f '{{.State.Status}}' "$name" 2>/dev/null || echo "нет")
        [ "$state" = "running" ] || MISSING="$MISSING $name($state)"
    done
    MYSQL_HEALTH=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}без healthcheck{{end}}' ufc-mysql 2>/dev/null || echo "нет")

    if [ -n "$MISSING" ]; then
        fail "Не запущены:$MISSING" \
             "make up — поднять контейнеры" \
             "docker compose logs — посмотреть, на чём остановилось"
    elif [ "$MYSQL_HEALTH" != "healthy" ]; then
        fail "ufc-mysql в состоянии «$MYSQL_HEALTH»." \
             "docker compose logs mysql | tail -30" \
             "Строка «unknown variable» означает, что в docker/mysql/my.cnf попал ключ, удалённый в MySQL 8.4 (дефект №2)."
    else
        ok "все четыре, mysql healthy"
    fi
fi

# --- 3. Внешний порт базы ---------------------------------------------------

begin "База доступна снаружи на порту $DB_PORT_EXTERNAL"
PUBLISHED=$($DC port mysql 3306 2>/dev/null | tail -n1 | sed 's/.*://')
if [ -z "$PUBLISHED" ]; then
    fail "docker compose не сообщает опубликованный порт mysql." \
         "make up, затем повторите"
elif [ "$PUBLISHED" != "$DB_PORT_EXTERNAL" ]; then
    fail "Опубликован порт $PUBLISHED, а в .env записано $DB_PORT_EXTERNAL." \
         "Приведите DB_PORT_EXTERNAL в корневом .env в соответствие и выполните make up" \
         "Менять порт под уже настроенным подключением в PhpStorm — дефект №6."
else
    # Проверяем сам TCP-порт с вашей машины, а не изнутри docker-сети:
    # именно снаружи к нему подключается PhpStorm.
    PORT_OPEN=1
    if command -v nc >/dev/null 2>&1; then
        nc -z 127.0.0.1 "$DB_PORT_EXTERNAL" >/dev/null 2>&1 || PORT_OPEN=0
    elif command -v python3 >/dev/null 2>&1; then
        python3 - "$DB_PORT_EXTERNAL" <<'PY' >/dev/null 2>&1 || PORT_OPEN=0
import socket, sys
s = socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=5)
s.close()
PY
    fi

    CREDS_OK=1
    $DC exec -T mysql mysql -h 127.0.0.1 -P 3306 \
        -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
        -e 'select 1' >/dev/null 2>&1 || CREDS_OK=0

    if [ "$PORT_OPEN" -eq 0 ]; then
        fail "Порт $DB_PORT_EXTERNAL на 127.0.0.1 не слушается." \
             "make up" \
             "Если порт занят другим MySQL — задайте свободный в корневом .env и выполните make up"
    elif [ "$CREDS_OK" -eq 0 ]; then
        fail "Порт открыт, но пользователь $DB_USERNAME не пускает в базу $DB_DATABASE." \
             "Пароль в backend/.env должен совпадать с тем, с которым база создавалась." \
             "Если меняли пароль после первого запуска — make reset пересоздаст базу с нуля."
    else
        ok "порт слушается, пользователь $DB_USERNAME проходит"
    fi
fi

# --- 4. /up -----------------------------------------------------------------

begin "http://localhost:$APP_PORT/up отвечает 200"
UP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://localhost:$APP_PORT/up" 2>/dev/null || echo 000)
if [ "$UP_CODE" = "200" ]; then
    ok
else
    fail "Получен код $UP_CODE вместо 200." \
         "make logs — посмотреть, что говорит бекенд" \
         "Если код 000 — nginx не слушает порт $APP_PORT; проверьте APP_PORT в корневом .env"
fi

# --- 5. /api/health ---------------------------------------------------------

begin "http://localhost:$APP_PORT/api/health и database: true"
HEALTH=$(curl -s --max-time 15 "http://localhost:$APP_PORT/api/health" 2>/dev/null || echo '')
if [ -z "$HEALTH" ]; then
    fail "Пустой ответ." \
         "make logs"
elif printf '%s' "$HEALTH" | grep -q '"database":[[:space:]]*true'; then
    PHPV=$(printf '%s' "$HEALTH" | sed -n 's/.*"php":"\([^"]*\)".*/\1/p')
    LARV=$(printf '%s' "$HEALTH" | sed -n 's/.*"laravel":"\([^"]*\)".*/\1/p')
    ok "PHP $PHPV, Laravel $LARV"
elif printf '%s' "$HEALTH" | grep -q '"database":[[:space:]]*false'; then
    fail "Приложение отвечает, но не видит базу." \
         "make db-info — сверьте параметры" \
         "docker compose logs mysql | tail -30"
else
    fail "Ответ не похож на ожидаемый JSON: $(printf '%s' "$HEALTH" | head -c 120)" \
         "Скорее всего запрос ушёл не в Laravel, а в статику: проверьте docker/nginx/default.conf"
fi

# --- 6. Собранный фронтенд --------------------------------------------------

begin "frontend/dist собран и не старше исходников"
if [ ! -f frontend/dist/index.html ]; then
    fail "Нет frontend/dist/index.html." \
         "make front — собрать фронтенд"
else
    NEWER=$(find frontend/src -type f -newer frontend/dist/index.html 2>/dev/null | head -n1)
    if [ -n "$NEWER" ]; then
        fail "Исходники новее сборки (например, $NEWER)." \
             "make front — пересобрать"
    else
        ok
    fi
fi

# --- 7. Чистый вывод php -v -------------------------------------------------

begin "docker compose exec php php -v без посторонних строк"
PHP_V=$($DC exec -T php php -v 2>/dev/null | head -n1)
case "$PHP_V" in
    "PHP "*)
        ok "$(printf '%s' "$PHP_V" | cut -d' ' -f1,2)"
        ;;
    "")
        fail "Команда ничего не вернула." \
             "make up, затем повторите"
        ;;
    *)
        fail "Первая строка: «$PHP_V»." \
             "docker/php/entrypoint.sh печатает лишнее для разовых команд — это дефект №9." \
             "PhpStorm по такому выводу не определит интерпретер."
        ;;
esac

# --- 8. Источник расписания -------------------------------------------------

begin "ufc.com отдаёт расписание с этой машины"
UFC_CODE=$(curl -s -o /dev/null -w '%{http_code}' -A 'ufc-predict-doctor' \
           --max-time 20 https://www.ufc.com/events 2>/dev/null || echo 000)
case "$UFC_CODE" in
    200)
        ok "страница отдаётся, парсер этапа 3 сможет её прочитать"
        ;;
    403)
        warn "Получен 403: этот адрес ufc.com отсекает." \
             "С домашнего адреса такого обычно нет — 403 приходит на адреса датацентров и VPN." \
             "Так же отвечает и сборка contract в GitHub Actions, поэтому там 403 не считается провалом." \
             "Если 403 приходит с вашей рабочей машины — парсер этапа 3 работать не будет, отключите VPN."
        ;;
    000)
        warn "Сервер не ответил вовсе." \
             "Проверьте сеть; если интернет есть, значит ufc.com недоступен прямо сейчас."
        ;;
    *)
        fail "ufc.com/events ответил $UFC_CODE вместо 200." \
             "Адрес расписания мог измениться — на этапе 3 это ломает сбор турниров."
        ;;
esac

# --- Итог -------------------------------------------------------------------

printf '\n'
if [ "$FAILED" -eq 0 ]; then
    if [ "$WARNED" -eq 0 ]; then
        printf '  '; green "Все $CHECK проверки пройдены."; printf '\n\n'
    else
        printf '  '; green "Провалов нет"; printf ', предупреждений: %s из %s проверок.\n\n' "$WARNED" "$CHECK"
    fi
    printf '  Подключение к базе из PhpStorm, TablePlus, DBeaver:\n\n'
    printf '    Хост:    127.0.0.1\n'
    printf '    Порт:    %s\n' "$DB_PORT_EXTERNAL"
    printf '    База:    %s\n' "$DB_DATABASE"
    printf '    Логин:   %s\n' "$DB_USERNAME"
    printf '    Пароль:  %s\n' "$DB_PASSWORD"
    printf '\n'
    printf '  Приложение: http://localhost:%s\n\n' "$APP_PORT"
    exit 0
fi

printf '  '; red "Не пройдено проверок: $FAILED из $CHECK."; printf '\n\n'
exit 1
