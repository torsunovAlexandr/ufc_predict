# UFC Predict — короткие команды для повседневной работы.
# Наберите `make` без аргументов, чтобы увидеть список.

DC      ?= docker compose
PHP     := $(DC) exec php php
ARTISAN := $(PHP) artisan

.DEFAULT_GOAL := help
.PHONY: help setup env db-info debug-on debug-off up down restart rebuild ps logs logs-scheduler \
        front front-dev shell mysql migrate fresh seed key test test-unit \
        test-feature selfcheck events fighters odds predict results pipeline \
        backtest cache-clear optimize schedule reset

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
	@echo "  Примеры: make setup · make db-info · make backtest FROM=2024-01-01"
	@echo ""

setup: env ## Первый запуск: .env, сборка фронта, поднятие контейнеров
	$(DC) --profile build run --rm node
	$(DC) up -d
	@echo ""
	@echo "  Готово. Приложение: http://localhost:8080"
	@echo "  Первый старт занимает пару минут — composer ставит зависимости."
	@echo "  Следить за ходом: make logs · параметры БД: make db-info"

env: ## Синхронизировать переменные для Docker из backend/.env в корневой .env
	@test -f backend/.env || (cp backend/.env.example backend/.env && echo "  Создан backend/.env")
	@{ \
		echo "# Файл создаётся командой make env. Правьте backend/.env, а не этот файл."; \
		echo "# Отсюда Docker Compose берёт параметры для контейнера MySQL,"; \
		echo "# чтобы они не разошлись с настройками приложения."; \
		grep -E '^DB_(DATABASE|USERNAME|PASSWORD)=' backend/.env; \
		echo "# Порт приложения на вашей машине:"; \
		echo "# APP_PORT=8080"; \
		echo "# Порт MySQL на вашей машине (смените, если 3306 занят):"; \
		echo "# DB_PORT_EXTERNAL=3306"; \
		echo "# Раскомментируйте, чтобы Xdebug был включён постоянно:"; \
		echo "# XDEBUG_MODE=debug"; \
	} > .env
	@echo "  Корневой .env синхронизирован с backend/.env"

db-info: ## Параметры подключения к БД для PhpStorm и других GUI-клиентов
	@port=$$(grep -sE '^DB_PORT_EXTERNAL=' .env | cut -d= -f2); \
	port=$${port:-3306}; \
	echo ""; \
	echo "  Подключение из PhpStorm, TablePlus, DBeaver:"; \
	echo ""; \
	echo "    Хост:     127.0.0.1"; \
	echo "    Порт:     $$port"; \
	echo "    База:     $$(grep -sE '^DB_DATABASE=' backend/.env | cut -d= -f2-)"; \
	echo "    Логин:    $$(grep -sE '^DB_USERNAME=' backend/.env | cut -d= -f2-)"; \
	echo "    Пароль:   $$(grep -sE '^DB_PASSWORD=' backend/.env | cut -d= -f2-)"; \
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

debug-on: env ## Включить Xdebug и перезапустить бекенд
	XDEBUG_MODE=debug $(DC) up -d --force-recreate php scheduler
	@echo ""
	@echo "  Xdebug включён: порт 9003, IDE key PHPSTORM."
	@echo "  В PhpStorm включите «Start Listening for PHP Debug Connections»,"
	@echo "  затем откройте страницу с ?XDEBUG_TRIGGER=1 или включите расширение Xdebug helper."
	@echo "  Подробная настройка: docs/phpstorm-xdebug.md"
	@echo ""

debug-off: env ## Выключить Xdebug (ускоряет обычную работу)
	XDEBUG_MODE=off $(DC) up -d --force-recreate php scheduler
	@echo "  Xdebug выключен."

## ---------------------------------------------------------------------------
## Фронтенд
## ---------------------------------------------------------------------------

front: ## Собрать фронтенд в frontend/dist
	$(DC) --profile build run --rm node

front-dev: ## Режим разработки Vite с горячей перезагрузкой (http://localhost:5173)
	$(DC) --profile dev run --rm --service-ports node npm run dev -- --host

## ---------------------------------------------------------------------------
## База данных
## ---------------------------------------------------------------------------

migrate: ## Накатить миграции
	$(ARTISAN) migrate --force

fresh: ## Пересоздать базу с нуля и залить стартовые данные (все ставки пропадут)
	$(ARTISAN) migrate:fresh --seed --force

seed: ## Залить стартовые данные
	$(ARTISAN) db:seed --force

mysql: ## Консоль MySQL внутри контейнера
	$(DC) exec mysql sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'

## ---------------------------------------------------------------------------
## Обновление данных и прогнозы
## ---------------------------------------------------------------------------

events: ## Загрузить турниры и карды боёв с ufc.com
	$(ARTISAN) ufc:sync-events --fights

fighters: ## Обновить карточки бойцов ближайших турниров
	$(ARTISAN) ufc:sync-fighters

odds: ## Загрузить букмекерские коэффициенты
	$(ARTISAN) ufc:sync-odds

predict: ## Пересчитать прогнозы и сформировать рекомендации по ставкам
	$(ARTISAN) ufc:predict --recommend

results: ## Получить результаты прошедших боёв и рассчитать ставки
	$(ARTISAN) ufc:results

pipeline: ## Полный цикл: турниры → бойцы → коэффициенты → прогнозы
	$(ARTISAN) ufc:pipeline

# make backtest FROM=2024-01-01 TO=2025-12-31 BANKROLL=10000
backtest: ## Прогон стратегии на истории (FROM=, TO=, BANKROLL=)
	$(ARTISAN) ufc:backtest \
		$(if $(FROM),--from=$(FROM)) \
		$(if $(TO),--to=$(TO)) \
		$(if $(BANKROLL),--bankroll=$(BANKROLL))

schedule: ## Разово выполнить задачи по расписанию
	$(ARTISAN) schedule:run

## ---------------------------------------------------------------------------
## Разработка
## ---------------------------------------------------------------------------

test: ## Прогнать все тесты
	$(ARTISAN) test

test-unit: ## Только юнит-тесты (модель и ставки)
	$(ARTISAN) test --testsuite=Unit

test-feature: ## Только функциональные тесты (API и расчёт ставок)
	$(ARTISAN) test --testsuite=Feature

selfcheck: ## Проверить математику модели без Docker и composer
	@php backend/tools/model-selfcheck.php 2>/dev/null \
		|| $(PHP) tools/model-selfcheck.php

shell: ## Войти в контейнер бекенда
	$(DC) exec php sh

key: ## Сгенерировать ключ приложения
	$(ARTISAN) key:generate --force

cache-clear: ## Сбросить кэши конфигурации, маршрутов и приложения
	$(ARTISAN) config:clear
	$(ARTISAN) route:clear
	$(ARTISAN) cache:clear

optimize: ## Прогреть кэши для боевого режима
	$(ARTISAN) config:cache
	$(ARTISAN) route:cache

reset: ## Остановить всё и удалить базу (данные будут потеряны)
	@printf "  Будут удалены контейнеры и вся база. Продолжить? [y/N] " && read answer && [ "$$answer" = "y" ]
	$(DC) down -v
