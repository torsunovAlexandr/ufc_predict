#!/bin/sh
# Подготовка окружения при старте контейнера.
#
# ВАЖНО (дефект №9): подготовка выполняется только для долгоживущих ролей —
# php-fpm и планировщика. Любая разовая команда (php -v, php -i, composer,
# artisan) исполняется сразу и без единой лишней строки в выводе: этот вывод
# разбирают IDE и скрипты. PhpStorm определяет версию PHP и наличие Xdebug,
# читая вывод `php -v` в контейнере, и посторонний текст перед ним ломает
# определение интерпретера.
#
# Правило проверяется автоматически: сборка `stack` требует, чтобы первая
# строка `docker compose exec -T php php -v` начиналась ровно с «PHP ».
set -e

cd /var/www/backend

case "$1" in
    php-fpm)
        ROLE=server
        ;;
    php)
        # Планировщик запускается как `php artisan schedule:work`
        if [ "$2" = "artisan" ] && [ "$3" = "schedule:work" ]; then
            ROLE=scheduler
        else
            ROLE=cli
        fi
        ;;
    *)
        ROLE=cli
        ;;
esac

# Разовые команды — молча и сразу
if [ "$ROLE" = "cli" ]; then
    exec "$@"
fi

if [ ! -f .env ]; then
    echo "→ Создаю .env из .env.example…"
    cp .env.example .env
fi

if [ "$ROLE" = "server" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "→ Устанавливаю зависимости composer (первый запуск, это займёт пару минут)…"
        composer install --no-interaction --prefer-dist --optimize-autoloader
    fi
else
    # Планировщик: ждём, пока основной контейнер разложит vendor
    echo "→ Жду установки зависимостей…"
    i=0
    while [ ! -f vendor/autoload.php ] && [ "$i" -lt 120 ]; do
        sleep 5
        i=$((i + 1))
    done
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    echo "→ Генерирую ключ приложения…"
    php artisan key:generate --force
fi

chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

if [ "$ROLE" = "server" ]; then
    # Конфигурацию сбрасываем ДО обращения к базе: закэшированный config
    # от предыдущего запуска может содержать старые параметры подключения.
    php artisan config:clear >/dev/null 2>&1 || true

    # Ждём базу самой миграцией, а не отдельной PDO-проверкой.
    # Так параметры подключения берутся оттуда же, откуда их берёт приложение —
    # из backend/.env через конфиг Laravel. Отдельная проверка на getenv()
    # читала переменные окружения контейнера и врала, когда они не совпадали
    # с .env.
    echo "→ Жду MySQL и накатываю миграции…"
    i=0
    until php artisan migrate --force --no-interaction >/tmp/migrate.log 2>&1; do
        i=$((i + 1))
        if [ "$i" -ge 40 ]; then
            echo "  База не ответила за отведённое время. Последняя ошибка:"
            tail -5 /tmp/migrate.log | sed 's/^/    /'
            echo "  Выполните вручную: docker compose exec php php artisan migrate"
            break
        fi
        sleep 3
    done
    if [ "$i" -lt 40 ]; then
        sed 's/^/  /' /tmp/migrate.log
    fi

    # Стартовые данные заливаем ровно один раз — по метке в storage.
    # На этапе 0 сидер пустой; метка нужна, чтобы на этапе 2 повторный
    # запуск контейнера не затирал ручные правки в базе.
    if [ ! -f storage/.seeded ]; then
        echo "→ Наполняю базу стартовыми данными…"
        if php artisan db:seed --force --no-interaction; then
            touch storage/.seeded
        else
            echo "  Сид не выполнен, при необходимости запустите: docker compose exec php php artisan db:seed"
        fi
    fi
fi

exec "$@"
