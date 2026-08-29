# UFC Predict — короткие команды для повседневной работы.
# Наберите `make` без аргументов, чтобы увидеть список.
#
# Этап 0. Здесь только то, под чем есть работающий код. Команды предметной
# области (турниры, коэффициенты, прогнозы, бэктест) возвращаются по мере
# появления соответствующих этапов — см. docs/roadmap-v2.md, часть 5.
# Команда, которая падает с «command not found», ничем не лучше отсутствующей:
# она выглядит рабочей и обнаруживает обратное в самый неподходящий момент.

DC      ?= docker compose
PHP     := $(DC) exec php php
ARTISAN := $(PHP) artisan

.DEFAULT_GOAL := help
.PHONY: help setup env doctor db-info up down restart rebuild ps logs logs-scheduler \
        debug-on debug-off debug-check front front-dev migrate mysql \
        test test-unit test-feature lint analyse check shell key cache-clear reset

## ---------------------------------------------------------------------------
## Запуск и остановка
## ---------------------------------------------------------------------------

help: ## Показать список команд
	@echo ""
	@echo "  UFC Predict — доступные команды"
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} \
		/^## ---/ {next} \
		/^## / {sub(/^## /, ""); printf "\n  \033[1m%s\033[0m\n", $$0; next} \
		/^[a-zA-Z_-]+:.*?## / {printf "    \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""
	@echo "  Начните с: make setup · затем make doctor"
	@echo ""

setup: env ## Первая настройка: .env, сборка фронта, поднятие контейнеров
	$(DC) --profile build run --rm node
	$(DC) up -d
	@echo ""
	@echo "  Готово. Приложение: http://localhost:$$(sed -n 's/^APP_PORT=//p' .env | head -n1)"
	@echo "  Первый старт занимает пару минут — composer ставит зависимости."
	@echo "  Проверить, что всё поднялось: make doctor"
	@echo "  Параметры подключения к базе:  make db-info"
	@echo ""

env: ## Дописать в .env недостающие ключи (существующие не трогает)
	@sh tools/env-sync.sh

doctor: ## Проверить, что вся система работает: контейнеры, порты, база, фронт
	@sh tools/doctor.sh

db-info: ## Параметры подключения к БД для PhpStorm и других GUI-клиентов
	@port=$$(sed -n 's/^DB_PORT_EXTERNAL=//p' .env 2>/dev/null | head -n1); \
	port=$${port:-33061}; \
	echo ""; \
	echo "  Подключение из PhpStorm, TablePlus, DBeaver:"; \
	echo ""; \
	echo "    Хост:     127.0.0.1"; \
	echo "    Порт:     $$port"; \
	echo "    База:     $$(sed -n 's/^DB_DATABASE=//p' backend/.env 2>/dev/null | head -n1)"; \
	echo "    Логин:    $$(sed -n 's/^DB_USERNAME=//p' backend/.env 2>/dev/null | head -n1)"; \
	echo "    Пароль:   $$(sed -n 's/^DB_PASSWORD=//p' backend/.env 2>/dev/null | head -n1)"; \
	echo ""; \
	echo "  Значения DB_HOST=mysql и DB_PORT=3306 из backend/.env — это адрес"; \
	echo "  внутри docker-сети. Снаружи он не резолвится, используйте 127.0.0.1."; \
	echo ""

up: env ## Поднять контейнеры
	$(DC) up -d

down: ## Остановить контейнеры
	$(DC) down

restart: env ## Перезапустить контейнеры
	$(DC) restart

rebuild: env ## Пересобрать образы и поднять заново
	$(DC) build --no-cache
	$(DC) up -d --force-recreate

ps: ## Статус контейнеров
	$(DC) ps

logs: ## Логи бекенда (Ctrl+C для выхода)
	$(DC) logs -f php

logs-scheduler: ## Логи планировщика задач
	$(DC) logs -f scheduler

## ---------------------------------------------------------------------------
## Отладка
## ---------------------------------------------------------------------------

# Дефект №8: раньше debug-on только перезапускал контейнер, и если образ был
# собран без Xdebug, расширение так и не появлялось. Теперь образ пересобирается
# всегда (слои кэшируются, это быстро), а результат сразу проверяется.
debug-on: env ## Включить Xdebug: пересобрать образ и перезапустить бекенд
	$(DC) build php
	XDEBUG_MODE=debug $(DC) up -d --force-recreate php scheduler
	@sleep 2
	@$(MAKE) --no-print-directory debug-check
	@echo ""
	@echo "  В PhpStorm включите «Start Listening for PHP Debug Connections»,"
	@echo "  затем откройте страницу с ?XDEBUG_TRIGGER=1 или включите Xdebug helper."
	@echo "  Подробная настройка: docs/phpstorm-xdebug.md"
	@echo ""

debug-off: env ## Выключить Xdebug (ускоряет обычную работу)
	XDEBUG_MODE=off $(DC) up -d --force-recreate php scheduler
	@sleep 2
	@$(MAKE) --no-print-directory debug-check

debug-check: ## Проверить, что Xdebug установлен и в каком он режиме
	@echo ""
	@if $(DC) exec -T php php -m | grep -qi '^xdebug$$'; then \
		echo "  Расширение Xdebug установлено в образе"; \
	else \
		echo "  Xdebug НЕ установлен в образе — выполните: make rebuild"; \
		exit 1; \
	fi
	@$(DC) exec -T php php -r 'echo "  xdebug.mode = ", ini_get("xdebug.mode") ?: "(не задан)", PHP_EOL;'
	@echo "  Порт 9003, IDE key PHPSTORM. Настройка: docs/phpstorm-xdebug.md"
	@echo ""

## ---------------------------------------------------------------------------
## Фронтенд
## ---------------------------------------------------------------------------

front: ## Собрать фронтенд в frontend/dist
	$(DC) --profile build run --rm node

front-dev: ## Режим разработки Vite с горячей перезагрузкой (http://localhost:5173)
	$(DC) --profile dev run --rm --service-ports node sh -c "npm ci && npm run dev -- --host"

## ---------------------------------------------------------------------------
## База данных
## ---------------------------------------------------------------------------

migrate: ## Накатить миграции
	$(ARTISAN) migrate --force

mysql: ## Консоль MySQL внутри контейнера
	$(DC) exec mysql sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'

## ---------------------------------------------------------------------------
## Разработка
## ---------------------------------------------------------------------------

test: ## Прогнать все тесты бекенда
	$(DC) exec -T php vendor/bin/phpunit

test-unit: ## Только юнит-тесты (без Laravel и без базы)
	$(DC) exec -T php vendor/bin/phpunit --testsuite=Unit

test-feature: ## Только функциональные тесты (приложение на SQLite в памяти)
	$(DC) exec -T php vendor/bin/phpunit --testsuite=Feature

lint: ## Проверить оформление кода (Pint)
	$(DC) exec -T php vendor/bin/pint --test

analyse: ## Статический анализ (PHPStan + Larastan)
	$(DC) exec -T php vendor/bin/phpstan analyse --memory-limit=512M

check: lint analyse test ## Всё, что проверяет CI, одной командой

shell: ## Войти в контейнер бекенда
	$(DC) exec php sh

key: ## Сгенерировать ключ приложения
	$(ARTISAN) key:generate --force

cache-clear: ## Сбросить кэши конфигурации, маршрутов и приложения
	$(ARTISAN) config:clear
	$(ARTISAN) route:clear
	$(ARTISAN) cache:clear

reset: ## Остановить всё и удалить базу (данные будут потеряны)
	@printf "  Будут удалены контейнеры и вся база. Продолжить? [y/N] " && read answer && [ "$$answer" = "y" ]
	$(DC) down -v
