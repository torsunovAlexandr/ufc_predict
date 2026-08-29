#!/bin/sh
# Генератор конфигурации. Дописывает недостающие ключи и НИКОГДА не трогает
# уже записанные значения.
#
# Так выглядит дефект №7 первой версии: команда `make env` пересобирала корневой
# .env целиком, и всё, что вы правили руками, исчезало при следующем запуске.
# Здесь этого не может случиться по построению: файл только дополняется.
#
# Что делает:
#   1. создаёт backend/.env из backend/.env.example, если его нет;
#   2. создаёт корневой .env, если его нет;
#   3. дописывает в корневой .env те ключи из .env.example, которых там нет,
#      подставляя значения DB_* из backend/.env, чтобы compose создал базу
#      ровно с теми параметрами, с которыми к ней потом подключится приложение.
#
# Запускается из корня репозитория.
set -e

ROOT_ENV=".env"
ROOT_EXAMPLE=".env.example"
BACK_ENV="backend/.env"
BACK_EXAMPLE="backend/.env.example"

if [ ! -f "$ROOT_EXAMPLE" ]; then
    echo "  Не найден $ROOT_EXAMPLE — запускайте из корня репозитория." >&2
    exit 1
fi

# --- backend/.env -----------------------------------------------------------

if [ ! -f "$BACK_ENV" ]; then
    cp "$BACK_EXAMPLE" "$BACK_ENV"
    echo "  Создан $BACK_ENV из образца"
fi

# Значение ключа из файла: первое вхождение, всё после первого «=».
value_of() {
    key="$1"
    file="$2"
    [ -f "$file" ] || return 1
    sed -n "s/^[[:space:]]*${key}=//p" "$file" | head -n1
}

has_key() {
    key="$1"
    file="$2"
    [ -f "$file" ] && grep -qE "^[[:space:]]*${key}=" "$file"
}

# --- корневой .env ----------------------------------------------------------

if [ ! -f "$ROOT_ENV" ]; then
    {
        echo "# Корневой .env — параметры, которые видит docker compose."
        echo "# Создан командой make setup. Правьте руками сколько угодно:"
        echo "# генератор только дописывает недостающие ключи и никогда"
        echo "# не перезаписывает то, что здесь уже есть."
        echo ""
    } > "$ROOT_ENV"
    echo "  Создан $ROOT_ENV"
fi

added=0

# Ключи берём из образца — так список не разъезжается с документацией.
keys=$(sed -n 's/^\([A-Z_][A-Z0-9_]*\)=.*/\1/p' "$ROOT_EXAMPLE")

for key in $keys; do
    if has_key "$key" "$ROOT_ENV"; then
        continue
    fi

    # DB_DATABASE, DB_USERNAME, DB_PASSWORD должны совпадать с backend/.env:
    # из них compose создаёт базу и пользователя при первом запуске.
    case "$key" in
        DB_DATABASE|DB_USERNAME|DB_PASSWORD)
            val=$(value_of "$key" "$BACK_ENV" || true)
            ;;
        *)
            val=""
            ;;
    esac

    if [ -z "$val" ]; then
        val=$(value_of "$key" "$ROOT_EXAMPLE")
    fi

    printf '%s=%s\n' "$key" "$val" >> "$ROOT_ENV"
    echo "  Дописан ключ $key"
    added=$((added + 1))
done

if [ "$added" -eq 0 ]; then
    echo "  Корневой .env уже полон — ни одна строка не изменена"
fi
