<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { useSettingsStore } from '@/stores/settings'
import { useUiStore } from '@/stores/ui'
import { dateTime, money, percent } from '@/utils/format'

const ui = useUiStore()
const settingsStore = useSettingsStore()

const form = reactive({
  starting_bankroll: 10000,
  min_ev: 0.05,
  kelly_fraction: 1,
  max_stake_fraction: 0.05,
  max_stake_fraction_high_conf: 0.1,
  max_fraction_per_fight: 0.1,
  min_stake_fraction: 0.005,
  min_odds: 1.1,
  max_odds: 15,
  auto_place_bets: false,
  theme: 'dark',
  odds_provider: 'the_odds_api',
  score_scale: 3.5,
})

const weights = reactive({})
const sources = ref([])
const resetAmount = ref(10000)
const backtest = ref(null)
const backtestRange = reactive({ from: '', to: '' })

const WEIGHT_LABELS = {
  takedowns_offense: 'Тейкдауны (атака)',
  takedown_defense: 'Защита от тейкдаунов',
  striking_offense: 'Значимые удары (атака)',
  striking_defense: 'Защита от ударов',
  submission_offense: 'Сабмишены (атака)',
  submission_defense: 'Защита от сабмишенов',
  cardio: 'Кардио',
  physical_experience: 'Опыт, возраст, физика',
}

const weightsSum = computed(() =>
  Object.values(weights).reduce((sum, value) => sum + Number(value || 0), 0)
)

async function load() {
  const values = await settingsStore.load(true)

  for (const key of Object.keys(form)) {
    if (values[key] !== undefined && values[key] !== null) form[key] = values[key]
  }

  resetAmount.value = values.starting_bankroll

  for (const [key, value] of Object.entries(values.model_weights || {})) {
    weights[key] = Number(value)
  }

  sources.value = await api.syncStatus().catch(() => [])
}

async function save() {
  await ui.run(
    () =>
      settingsStore.save({
        ...form,
        starting_bankroll: Number(form.starting_bankroll),
        min_ev: Number(form.min_ev),
        kelly_fraction: Number(form.kelly_fraction),
        max_stake_fraction: Number(form.max_stake_fraction),
        max_stake_fraction_high_conf: Number(form.max_stake_fraction_high_conf),
        max_fraction_per_fight: Number(form.max_fraction_per_fight),
        min_stake_fraction: Number(form.min_stake_fraction),
        min_odds: Number(form.min_odds),
        max_odds: Number(form.max_odds),
        score_scale: Number(form.score_scale),
        model_weights: weights,
      }),
    'Настройки сохранены'
  )

  await load()
}

async function resetBankroll() {
  if (!confirm('Банкролл будет сброшен, а вся история ставок очищена. Продолжить?')) return

  await ui.run(() => api.resetBankroll(Number(resetAmount.value)), (r) => r.message)
}

async function runBacktest() {
  backtest.value = await ui.run(
    () =>
      api.backtest({
        from: backtestRange.from || null,
        to: backtestRange.to || null,
        bankroll: Number(form.starting_bankroll),
      }),
    'Бэктест завершён'
  )
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Настройки</h1>
        <p class="page-header__subtitle">Банкролл, правила ставок, веса модели и оформление</p>
      </div>
      <button class="btn btn--primary" type="button" :disabled="ui.busy" @click="save">
        Сохранить
      </button>
    </div>

    <div class="grid grid--2">
      <div class="card">
        <div class="card__title">Банкролл и ставки</div>

        <div class="field">
          <label class="field__label">Стартовый банкролл, ₽</label>
          <input v-model="form.starting_bankroll" type="number" min="100" step="100" />
          <div class="field__hint">Применяется при следующем сбросе банка.</div>
        </div>

        <div class="field">
          <label class="field__label">Порог value (EV), доля</label>
          <input v-model="form.min_ev" type="number" min="0" max="1" step="0.01" />
          <div class="field__hint">
            Ставка рекомендуется, если EV = P × K − 1 больше {{ percent(form.min_ev * 100) }}.
          </div>
        </div>

        <div class="field">
          <label class="field__label">Множитель Келли</label>
          <input v-model="form.kelly_fraction" type="number" min="0.05" max="1" step="0.05" />
          <div class="field__hint">
            1.0 — полный Келли, 0.5 — половинный (меньше просадки, медленнее рост).
          </div>
        </div>

        <div class="field">
          <label class="field__label">Максимум на одну ставку, доля банка</label>
          <input v-model="form.max_stake_fraction" type="number" min="0.001" max="0.5" step="0.005" />
        </div>

        <div class="field">
          <label class="field__label">Максимум при высокой уверенности (P &gt; 0.8)</label>
          <input v-model="form.max_stake_fraction_high_conf" type="number" min="0.001" max="0.5" step="0.005" />
        </div>

        <div class="field">
          <label class="field__label">Максимум на один бой суммарно</label>
          <input v-model="form.max_fraction_per_fight" type="number" min="0.001" max="1" step="0.01" />
        </div>

        <div class="field">
          <label class="field__label">Допустимые коэффициенты</label>
          <div style="display: flex; gap: 8px">
            <input v-model="form.min_odds" type="number" min="1.01" step="0.05" />
            <input v-model="form.max_odds" type="number" min="1.5" step="0.5" />
          </div>
          <div class="field__hint">Ставки вне этого диапазона не рекомендуются.</div>
        </div>
      </div>

      <div>
        <div class="card">
          <div class="card__title">Оформление и источники</div>

          <div class="field">
            <label class="field__label">Тема</label>
            <select v-model="form.theme" @change="settingsStore.applyTheme(form.theme)">
              <option value="dark">Тёмная</option>
              <option value="light">Светлая</option>
            </select>
          </div>

          <div class="field">
            <label class="field__label">Источник коэффициентов</label>
            <select v-model="form.odds_provider">
              <option value="the_odds_api">The Odds API</option>
              <option value="scraper">Парсер сайта БК</option>
              <option value="manual">Только ручной ввод</option>
            </select>
            <div class="field__hint">
              Ключ API задаётся в файле backend/.env (ODDS_API_KEY).
            </div>
          </div>

          <div class="field">
            <label style="font-size: 14px">
              <input v-model="form.auto_place_bets" type="checkbox" />
              Автоматически размещать рекомендованные ставки
            </label>
            <div class="field__hint">
              Если выключено, ставки нужно подтверждать вручную на дашборде.
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card__title">Сброс банкролла</div>
          <div class="field">
            <label class="field__label">Новый стартовый банк, ₽</label>
            <input v-model="resetAmount" type="number" min="100" step="100" />
          </div>
          <button class="btn" type="button" :disabled="ui.busy" @click="resetBankroll">
            Сбросить банк и историю ставок
          </button>
          <div class="field__hint" style="margin-top: 8px">
            Прогнозы и результаты боёв сохранятся, обнулится только денежная часть.
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__title">
        <span>Веса показателей модели</span>
        <span class="muted" style="font-size: 13px">
          сумма {{ weightsSum.toFixed(2) }} — при сохранении нормируется до 1.00
        </span>
      </div>

      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <div v-for="(label, key) in WEIGHT_LABELS" :key="key" class="field">
          <label class="field__label">{{ label }}</label>
          <input v-model="weights[key]" type="number" min="0" max="1" step="0.01" />
        </div>
      </div>

      <div class="field" style="max-width: 320px">
        <label class="field__label">Масштаб сигмоиды</label>
        <input v-model="form.score_scale" type="number" min="0.5" max="10" step="0.1" />
        <div class="field__hint">
          Чем больше, тем увереннее модель: при 3.5 разрыв в статистике на 30% даёт примерно 74%.
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card__title">Состояние источников данных</div>

      <div v-if="!sources.length" class="empty">Обращений к источникам ещё не было.</div>

      <div v-else class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Источник</th>
              <th>Последнее обращение</th>
              <th>Статус</th>
              <th class="num">Запросов сегодня</th>
              <th>Можно обновлять</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="source in sources" :key="source.source">
              <td>{{ source.source }}</td>
              <td>{{ source.last_fetch ? dateTime(source.last_fetch) : '—' }}</td>
              <td>{{ source.last_status || '—' }}</td>
              <td class="num">{{ source.requests_today }}</td>
              <td>
                <span class="badge" :class="source.can_refresh ? 'badge--positive' : 'badge--warning'">
                  {{ source.can_refresh ? 'да' : 'ждём интервал' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card__title">Бэктест на исторических данных</div>
      <p class="muted" style="font-size: 13px">
        Прогноз пересчитывается «на дату боя»: в профиль бойца попадают только те бои,
        которые состоялись раньше. Нужны прошедшие бои с результатами и сохранёнными котировками.
      </p>

      <div class="filters">
        <div class="field">
          <label class="field__label">С даты</label>
          <input v-model="backtestRange.from" type="date" />
        </div>
        <div class="field">
          <label class="field__label">По дату</label>
          <input v-model="backtestRange.to" type="date" />
        </div>
        <button class="btn btn--primary" type="button" :disabled="ui.busy" @click="runBacktest">
          Запустить бэктест
        </button>
      </div>

      <div v-if="backtest" class="grid grid--stats">
        <div class="stat">
          <div class="stat__label">Боёв проанализировано</div>
          <div class="stat__value">{{ backtest.fights_analysed }}</div>
        </div>
        <div class="stat">
          <div class="stat__label">Точность прогнозов</div>
          <div class="stat__value">{{ percent(backtest.prediction_accuracy) }}</div>
        </div>
        <div class="stat">
          <div class="stat__label">ROI</div>
          <div class="stat__value" :class="backtest.roi >= 0 ? 'positive' : 'negative'">
            {{ percent(backtest.roi) }}
          </div>
        </div>
        <div class="stat">
          <div class="stat__label">Банк на финише</div>
          <div class="stat__value">{{ money(backtest.final_bankroll) }}</div>
          <div class="stat__hint">просадка до {{ percent(backtest.max_drawdown) }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
