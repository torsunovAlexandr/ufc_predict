<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BetsTable from '@/components/BetsTable.vue'
import ProbabilityBar from '@/components/ProbabilityBar.vue'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import {
  dateTime,
  decimal,
  marketLabel,
  methodLabel,
  share,
  stanceLabel,
  styleLabel,
} from '@/utils/format'

const props = defineProps({ id: { type: [String, Number], required: true } })

const ui = useUiStore()
const fight = ref(null)
const loading = ref(true)
const showOddsForm = ref(false)
const showResultForm = ref(false)
const searchHits = ref([])

const manualOdds = reactive({
  fighter1: null,
  fighter2: null,
  over: null,
  under: null,
  ko_tko: null,
  submission: null,
  decision: null,
})

const manualResult = reactive({
  winner_id: '',
  method: 'decision',
  end_round: 3,
  end_time_minutes: 5,
  end_time_seconds: 0,
  is_draw: false,
  is_no_contest: false,
})

const prediction = computed(() => fight.value?.prediction)

/** Факторы, отсортированные по величине вклада. */
const factors = computed(() => {
  if (!prediction.value?.factors) return []
  return [...prediction.value.factors].sort(
    (a, b) => Math.abs(b.contribution) - Math.abs(a.contribution)
  )
})

const methodMarkets = computed(() => prediction.value?.method_probabilities?.markets || null)

/** Котировки, сгруппированные по рынку: показываем лучшую цену. */
const bestOdds = computed(() => {
  if (!fight.value?.odds) return []

  const best = new Map()

  for (const odd of fight.value.odds) {
    const key = `${odd.market}:${odd.selection}:${odd.line ?? ''}`
    const current = best.get(key)
    if (!current || Number(odd.price) > Number(current.price)) best.set(key, odd)
  }

  return [...best.values()]
})

function factorWidth(factor) {
  return Math.min(100, Math.abs(factor.contribution) * 400)
}

function selectionName(selection) {
  if (selection === 'fighter1') return fight.value?.fighter1?.name
  if (selection === 'fighter2') return fight.value?.fighter2?.name
  return {
    over: 'больше',
    under: 'меньше',
    draw: 'ничья',
    ko_tko: 'нокаут',
    submission: 'сабмишен',
    decision: 'решение',
  }[selection] || selection
}

async function load() {
  loading.value = true
  try {
    fight.value = await api.fight(props.id)
    manualResult.winner_id = fight.value?.result?.winner_id || ''
  } catch (e) {
    ui.error(e.friendlyMessage)
  } finally {
    loading.value = false
  }
}

async function predict() {
  const response = await ui.run(() => api.predictFight(props.id), (r) => r.message)
  fight.value = response.data
}

async function saveOdds() {
  const payload = []

  const push = (market, selection, price, line = null) => {
    const value = Number(price)
    if (value > 1) payload.push({ market, selection, price: value, line, bookmaker: 'ручной ввод' })
  }

  push('moneyline', 'fighter1', manualOdds.fighter1)
  push('moneyline', 'fighter2', manualOdds.fighter2)
  push('totals', 'over', manualOdds.over, 2.5)
  push('totals', 'under', manualOdds.under, 2.5)
  push('method', 'ko_tko', manualOdds.ko_tko)
  push('method', 'submission', manualOdds.submission)
  push('method', 'decision', manualOdds.decision)

  if (!payload.length) {
    ui.error('Введите хотя бы один коэффициент больше 1.00')
    return
  }

  const response = await ui.run(() => api.storeOdds(props.id, payload), (r) => r.message)
  fight.value = response.data
  showOddsForm.value = false
}

async function saveResult() {
  const payload = {
    winner_id: manualResult.winner_id || null,
    is_draw: manualResult.is_draw,
    is_no_contest: manualResult.is_no_contest,
    method: manualResult.method,
    end_round: Number(manualResult.end_round),
    end_time_seconds: Number(manualResult.end_time_minutes) * 60 + Number(manualResult.end_time_seconds),
  }

  const response = await ui.run(() => api.storeResult(props.id, payload), (r) => r.message)
  fight.value = response.data
  showResultForm.value = false
}

async function searchResult() {
  searchHits.value = await ui.run(() => api.searchResult(props.id))

  if (!searchHits.value.length) {
    ui.notify('Поиск не дал результатов. Проверьте ключ Google Custom Search в .env или введите результат вручную.')
  }
}

async function placeBets(ids) {
  await ui.run(() => api.placeBets(ids), (r) => r.message)
  await load()
}

async function skipBet(id) {
  await ui.run(() => api.skipBet(id), 'Рекомендация отклонена')
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="card">
      <div class="skeleton" style="height: 26px; width: 45%; margin-bottom: 14px" />
      <div class="skeleton" style="height: 60px" />
    </div>

    <template v-else-if="fight">
      <div class="page-header">
        <div>
          <h1>{{ fight.fighter1?.name }} — {{ fight.fighter2?.name }}</h1>
          <p class="page-header__subtitle">
            <RouterLink v-if="fight.event" :to="`/events/${fight.event_id}`">
              {{ fight.event.name }}
            </RouterLink>
            <template v-if="fight.event"> · {{ dateTime(fight.event.starts_at) }}</template>
            · {{ fight.weight_class || 'категория не указана' }} · {{ fight.scheduled_rounds }} раунда
            <span v-if="fight.is_title_fight" class="badge badge--accent">титульный</span>
          </p>
        </div>

        <div class="btn-row">
          <button class="btn" type="button" @click="showOddsForm = !showOddsForm">
            Ввести коэффициенты
          </button>
          <button class="btn btn--primary" type="button" :disabled="ui.busy" @click="predict">
            Пересчитать прогноз
          </button>
        </div>
      </div>

      <!-- Прогноз -->
      <div class="card">
        <div class="card__title">
          <span>Прогноз модели</span>
          <span v-if="prediction" class="muted" style="font-size: 13px">
            уверенность {{ share(prediction.confidence) }} · данные заполнены на
            {{ share(prediction.data_completeness) }}
          </span>
        </div>

        <div v-if="!prediction" class="empty">
          Прогноз ещё не рассчитан. Нажмите «Пересчитать прогноз».
        </div>

        <template v-else>
          <ProbabilityBar
            :first="prediction.probability_fighter1"
            :second="prediction.probability_fighter2"
            :first-name="fight.fighter1?.name"
            :second-name="fight.fighter2?.name"
          />

          <p style="margin-top: 14px">{{ prediction.explanation }}</p>

          <div class="grid grid--2" style="margin-top: 16px">
            <div>
              <h3>Вклад показателей</h3>
              <div v-for="factor in factors" :key="factor.key" class="factor">
                <div class="factor__head">
                  <span>{{ factor.label }}</span>
                  <span class="muted">
                    вес {{ Math.round(factor.weight * 100) }}% ·
                    {{ decimal(factor.values[0]) }} против {{ decimal(factor.values[1]) }}
                  </span>
                </div>
                <div class="factor__track">
                  <div class="factor__zero" />
                  <div
                    class="factor__bar"
                    :class="factor.contribution >= 0 ? 'factor__bar--first' : 'factor__bar--second'"
                    :style="{
                      width: factorWidth(factor) / 2 + '%',
                      left: factor.contribution >= 0 ? '50%' : 'auto',
                      right: factor.contribution >= 0 ? 'auto' : '50%',
                    }"
                  />
                </div>
              </div>
            </div>

            <div>
              <h3>Метод победы и тотал</h3>
              <table class="data">
                <tbody>
                  <tr v-if="methodMarkets">
                    <td>Нокаут / технический нокаут</td>
                    <td class="num">{{ share(methodMarkets.ko_tko) }}</td>
                  </tr>
                  <tr v-if="methodMarkets">
                    <td>Сабмишен</td>
                    <td class="num">{{ share(methodMarkets.submission) }}</td>
                  </tr>
                  <tr v-if="methodMarkets">
                    <td>Решение судей</td>
                    <td class="num">{{ share(methodMarkets.decision) }}</td>
                  </tr>
                  <tr>
                    <td>Тотал раундов больше 2.5</td>
                    <td class="num">{{ share(prediction.probability_over_2_5) }}</td>
                  </tr>
                  <tr>
                    <td>Тотал раундов меньше 2.5</td>
                    <td class="num">{{ share(prediction.probability_under_2_5) }}</td>
                  </tr>
                </tbody>
              </table>

              <h3 style="margin-top: 18px">Сработавшие правила</h3>
              <ul v-if="prediction.applied_rules?.length" class="rules">
                <li v-for="(rule, index) in prediction.applied_rules" :key="index">
                  <strong>{{ rule.label }}</strong>
                  <span class="badge" :class="rule.adjustment >= 0 ? 'badge--positive' : 'badge--negative'">
                    {{ rule.adjustment >= 0 ? '+' : '' }}{{ Math.round(rule.adjustment * 100) }} п.п.
                  </span>
                  <div class="muted" style="font-size: 13px">{{ rule.description }}</div>
                </li>
              </ul>
              <p v-else class="muted">Ни одно экспертное правило не сработало.</p>
            </div>
          </div>
        </template>
      </div>

      <!-- Ручной ввод коэффициентов -->
      <div v-if="showOddsForm" class="card">
        <div class="card__title">Ручной ввод коэффициентов</div>
        <p class="muted" style="font-size: 13px">
          Заполните только те рынки, которые нужны. Пустые поля игнорируются.
        </p>

        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))">
          <div class="field">
            <label class="field__label">Победа: {{ fight.fighter1?.name }}</label>
            <input v-model="manualOdds.fighter1" type="number" step="0.01" min="1.01" placeholder="1.85" />
          </div>
          <div class="field">
            <label class="field__label">Победа: {{ fight.fighter2?.name }}</label>
            <input v-model="manualOdds.fighter2" type="number" step="0.01" min="1.01" placeholder="2.05" />
          </div>
          <div class="field">
            <label class="field__label">Тотал больше 2.5</label>
            <input v-model="manualOdds.over" type="number" step="0.01" min="1.01" />
          </div>
          <div class="field">
            <label class="field__label">Тотал меньше 2.5</label>
            <input v-model="manualOdds.under" type="number" step="0.01" min="1.01" />
          </div>
          <div class="field">
            <label class="field__label">Победа нокаутом</label>
            <input v-model="manualOdds.ko_tko" type="number" step="0.01" min="1.01" />
          </div>
          <div class="field">
            <label class="field__label">Победа сабмишеном</label>
            <input v-model="manualOdds.submission" type="number" step="0.01" min="1.01" />
          </div>
          <div class="field">
            <label class="field__label">Победа решением</label>
            <input v-model="manualOdds.decision" type="number" step="0.01" min="1.01" />
          </div>
        </div>

        <div class="btn-row">
          <button class="btn btn--primary" type="button" :disabled="ui.busy" @click="saveOdds">
            Сохранить и пересчитать ставки
          </button>
          <button class="btn" type="button" @click="showOddsForm = false">Отмена</button>
        </div>
      </div>

      <!-- Котировки -->
      <div class="card">
        <div class="card__title">Коэффициенты букмекеров</div>

        <div v-if="!bestOdds.length" class="empty">
          Котировок нет. Загрузите их на странице турнира или введите вручную.
        </div>

        <div v-else class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Рынок</th>
                <th>Исход</th>
                <th class="num">Коэффициент</th>
                <th class="num">Вероятность БК</th>
                <th class="num">Вероятность модели</th>
                <th>Букмекер</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="odd in bestOdds" :key="odd.id">
                <td>{{ marketLabel(odd.market) }}</td>
                <td>
                  {{ selectionName(odd.selection) }}
                  <span v-if="odd.line" class="muted">{{ odd.line }}</span>
                </td>
                <td class="num">{{ decimal(odd.price) }}</td>
                <td class="num muted">{{ share(odd.implied_probability) }}</td>
                <td class="num">
                  <template v-if="prediction">
                    {{
                      share(
                        odd.selection === 'fighter1'
                          ? prediction.probability_fighter1
                          : odd.selection === 'fighter2'
                            ? prediction.probability_fighter2
                            : odd.selection === 'over'
                              ? prediction.probability_over_2_5
                              : odd.selection === 'under'
                                ? prediction.probability_under_2_5
                                : methodMarkets?.[odd.selection]
                      )
                    }}
                  </template>
                  <span v-else>—</span>
                </td>
                <td class="muted">{{ odd.bookmaker }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Ставки -->
      <div class="card">
        <div class="card__title">Ставки по этому бою</div>
        <BetsTable
          :bets="fight.bets"
          :fight="fight"
          :show-fight="false"
          selectable
          @place="placeBets"
          @skip="skipBet"
        />
      </div>

      <!-- Результат -->
      <div class="card">
        <div class="card__title">
          <span>Результат боя</span>
          <div class="btn-row">
            <button class="btn btn--sm" type="button" :disabled="ui.busy" @click="searchResult">
              Найти в интернете
            </button>
            <button class="btn btn--sm" type="button" @click="showResultForm = !showResultForm">
              {{ fight.result ? 'Исправить вручную' : 'Ввести вручную' }}
            </button>
          </div>
        </div>

        <div v-if="fight.result" style="margin-bottom: 12px">
          <p>
            <strong>{{ fight.result.winner_name || (fight.result.is_draw ? 'Ничья' : 'Победитель не определён') }}</strong>
            — {{ methodLabel(fight.result.method) }}
            <template v-if="fight.result.end_round">
              , раунд {{ fight.result.end_round }}
            </template>
            <span v-if="fight.result.entered_manually" class="badge badge--warning">введено вручную</span>
          </p>
        </div>

        <div v-else class="muted" style="margin-bottom: 12px">Результат ещё не известен.</div>

        <ul v-if="searchHits.length" class="rules">
          <li v-for="hit in searchHits" :key="hit.link">
            <a :href="hit.link" target="_blank" rel="noopener"><strong>{{ hit.title }}</strong></a>
            <div class="muted" style="font-size: 13px">{{ hit.snippet }}</div>
          </li>
        </ul>

        <div v-if="showResultForm">
          <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(170px, 1fr))">
            <div class="field">
              <label class="field__label">Победитель</label>
              <select v-model="manualResult.winner_id">
                <option value="">не определён</option>
                <option :value="fight.fighter1?.id">{{ fight.fighter1?.name }}</option>
                <option :value="fight.fighter2?.id">{{ fight.fighter2?.name }}</option>
              </select>
            </div>
            <div class="field">
              <label class="field__label">Метод</label>
              <select v-model="manualResult.method">
                <option value="ko_tko">Нокаут / TKO</option>
                <option value="submission">Сабмишен</option>
                <option value="decision">Решение судей</option>
                <option value="dq">Дисквалификация</option>
                <option value="other">Иное</option>
              </select>
            </div>
            <div class="field">
              <label class="field__label">Раунд</label>
              <input v-model="manualResult.end_round" type="number" min="1" max="5" />
            </div>
            <div class="field">
              <label class="field__label">Время (мин : сек)</label>
              <div style="display: flex; gap: 6px">
                <input v-model="manualResult.end_time_minutes" type="number" min="0" max="5" />
                <input v-model="manualResult.end_time_seconds" type="number" min="0" max="59" />
              </div>
            </div>
            <div class="field">
              <label class="field__label">Особые случаи</label>
              <label style="display: block; font-size: 14px">
                <input v-model="manualResult.is_draw" type="checkbox" /> ничья
              </label>
              <label style="display: block; font-size: 14px">
                <input v-model="manualResult.is_no_contest" type="checkbox" /> бой не состоялся (NC)
              </label>
            </div>
          </div>

          <div class="btn-row">
            <button class="btn btn--primary" type="button" :disabled="ui.busy" @click="saveResult">
              Сохранить результат и рассчитать ставки
            </button>
            <button class="btn" type="button" @click="showResultForm = false">Отмена</button>
          </div>
        </div>
      </div>

      <!-- Бойцы -->
      <div class="grid grid--2">
        <div v-for="corner in ['fighter1', 'fighter2']" :key="corner" class="card">
          <div class="card__title">{{ fight[corner]?.name }}</div>

          <p class="muted" style="font-size: 13px">
            {{ fight[corner]?.record }} ·
            {{ fight[corner]?.age ? fight[corner].age + ' лет' : 'возраст неизвестен' }} ·
            {{ stanceLabel(fight[corner]?.stance) }} · стиль: {{ styleLabel(fight[corner]?.style) }}
          </p>

          <table class="data">
            <tbody>
              <tr>
                <td>Значимых ударов в минуту</td>
                <td class="num">{{ decimal(fight[corner]?.stats?.sig_strikes_per_min) }}</td>
              </tr>
              <tr>
                <td>Точность ударов</td>
                <td class="num">{{ share(fight[corner]?.stats?.striking_accuracy) }}</td>
              </tr>
              <tr>
                <td>Защита от ударов</td>
                <td class="num">{{ share(fight[corner]?.stats?.striking_defense) }}</td>
              </tr>
              <tr>
                <td>Тейкдаунов за бой</td>
                <td class="num">{{ decimal(fight[corner]?.stats?.takedown_avg) }}</td>
              </tr>
              <tr>
                <td>Защита от тейкдаунов</td>
                <td class="num">{{ share(fight[corner]?.stats?.takedown_defense) }}</td>
              </tr>
              <tr>
                <td>Попыток сабмишена за бой</td>
                <td class="num">{{ decimal(fight[corner]?.stats?.submission_avg) }}</td>
              </tr>
              <tr>
                <td>Рост / размах рук</td>
                <td class="num">
                  {{ fight[corner]?.height_cm || '—' }} / {{ fight[corner]?.reach_cm || '—' }} см
                </td>
              </tr>
            </tbody>
          </table>

          <RouterLink
            v-if="fight[corner]?.id"
            :to="`/fighters?highlight=${fight[corner].id}`"
            class="muted"
            style="font-size: 13px"
          >
            Все бойцы →
          </RouterLink>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.factor { margin-bottom: 12px; }

.factor__head {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 13px;
  margin-bottom: 4px;
}

.factor__track {
  position: relative;
  height: 8px;
  background: var(--bg-hover);
  border-radius: 4px;
}

.factor__zero {
  position: absolute;
  left: 50%;
  top: -2px;
  bottom: -2px;
  width: 1px;
  background: var(--border);
}

.factor__bar {
  position: absolute;
  top: 0;
  bottom: 0;
  border-radius: 4px;
}

.factor__bar--first { background: var(--accent); }
.factor__bar--second { background: #5b6878; }

.rules {
  list-style: none;
  padding: 0;
  margin: 0;
}

.rules li {
  padding: 8px 0;
  border-bottom: 1px solid var(--border);
}

.rules li:last-child { border-bottom: none; }
</style>
