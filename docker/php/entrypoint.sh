#!/bin/sh
# Подготовка окружения при старте контейнера.
# Основной контейнер (php-fpm) ставит зависимости и накатывает миграции,
# контейнер планировщика просто дожидается, пока это сделает основной.
set -e

cd /var/www/backend

ROLE="$1"

if [ ! -f .env ]; then
    echo "→ Создаю .env из .env.example…"
    cp .env.example .env
fi

if [ "$ROLE" = "php-fpm" ]; then
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

if [ "$ROLE" = "php-fpm" ]; then
    echo "→ Жду MySQL…"
    i=0
    until php -r '
        $dsn = "mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306);
        new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    ' 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 40 ]; then
            echo "  MySQL не ответил за отведённое время — продолжаю без миграций."
            break
        fi
        sleep 3
    done

    echo "→ Накатываю миграции…"
    php artisan migrate --force || echo "  Миграции не выполнены, выполните вручную: docker compose exec php php artisan migrate"

    # Стартовые данные заливаем ровно один раз — по метке в storage
    if [ ! -f storage/.seeded ]; then
        echo "→ Наполняю базу стартовыми данными…"
        if php artisan db:seed --force; then
            touch storage/.seeded
        else
            echo "  Сид не выполнен, при необходимости запустите: docker compose exec php php artisan db:seed"
        fi
    fi

    php artisan config:clear >/dev/null 2>&1 || true
fi

exec "$@"
