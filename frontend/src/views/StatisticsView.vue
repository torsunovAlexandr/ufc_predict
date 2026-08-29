<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BetsTable from '@/components/BetsTable.vue'
import LineChart from '@/components/LineChart.vue'
import StatCard from '@/components/StatCard.vue'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { dateOnly, money, percent, plural, signedMoney } from '@/utils/format'

const ui = useUiStore()

const summary = ref({})
const chart = ref([])
const benchmarks = ref(null)
const accuracy = ref(null)
const bets = ref([])
const loading = ref(true)

const filters = reactive({ from: '', to: '' })

const chartData = computed(() => ({
  labels: chart.value.map((point) => dateOnly(point.date)),
  datasets: [
    {
      label: 'Банкролл, ₽',
      data: chart.value.map((point) => point.balance),
      borderColor: '#d20a0a',
      backgroundColor: 'rgba(210, 10, 10, 0.12)',
      fill: true,
      tension: 0.25,
      pointRadius: chart.value.length > 60 ? 0 : 2,
      borderWidth: 2,
    },
  ],
}))

const calibrationData = computed(() => ({
  labels: (accuracy.value?.calibration || []).map((b) => `${b.from}–${b.to}%`),
  datasets: [
    {
      label: 'Фактическая доля побед',
      data: (accuracy.value?.calibration || []).map((b) => b.actual),
      backgroundColor: '#4a90d9',
    },
    {
      label: 'Идеальная калибровка',
      data: (accuracy.value?.calibration || []).map((b) => (b.from + b.to) / 2),
      backgroundColor: 'rgba(143, 155, 171, 0.45)',
    },
  ],
}))

async function load() {
  loading.value = true
  try {
    const params = {}
    if (filters.from) params.from = filters.from
    if (filters.to) params.to = filters.to

    const [s, c, b, a, list] = await Promise.all([
      api.summary(params),
      api.bankrollChart(params),
      api.benchmarks(),
      api.accuracy(),
      api.bets({ per_page: 100, ...params }),
    ])

    summary.value = s
    chart.value = c
    benchmarks.value = b
    accuracy.value = a
    bets.value = list.data
  } catch (e) {
    ui.error(e.friendlyMessage)
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.from = ''
  filters.to = ''
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Статистика</h1>
        <p class="page-header__subtitle">Доходность виртуальных ставок и точность прогнозов</p>
      </div>
    </div>

    <div class="filters">
      <div class="field">
        <label class="field__label" for="from">С даты</label>
        <input id="from" v-model="filters.from" type="date" />
      </div>
      <div class="field">
        <label class="field__label" for="to">По дату</label>
        <input id="to" v-model="filters.to" type="date" />
      </div>
      <button class="btn" type="button" @click="load">Применить</button>
      <button class="btn" type="button" @click="resetFilters">Сбросить</button>
    </div>

    <div class="grid grid--stats">
      <StatCard label="Банкролл" :value="money(summary.bankroll)" :hint="`старт ${money(summary.starting_bankroll)}`" />
      <StatCard
        label="Профит"
        :value="signedMoney(summary.profit)"
        :tone="Number(summary.profit) >= 0 ? 'positive' : 'negative'"
      />
      <StatCard
        label="ROI"
        :value="percent(summary.roi)"
        :tone="Number(summary.roi) >= 0 ? 'positive' : 'negative'"
        :hint="`оборот ${money(summary.total_staked)}`"
      />
      <StatCard
        label="Доля выигрышей"
        :value="percent(summary.win_rate)"
        :hint="`${summary.bets_settled || 0} ${plural(summary.bets_settled || 0, 'рассчитанная ставка', 'рассчитанные ставки', 'рассчитанных ставок')}`"
      />
      <StatCard label="Средняя ставка" :value="money(summary.average_stake)" />
      <StatCard label="Средний коэффициент" :value="summary.average_odds || '—'" />
      <StatCard
        label="Текущая серия"
        :value="summary.streaks?.current ? `${summary.streaks.current}` : '—'"
        :hint="
          summary.streaks?.current_type === 'won'
            ? 'выигрышей подряд'
            : summary.streaks?.current_type === 'lost'
              ? 'проигрышей подряд'
              : ''
        "
        :tone="summary.streaks?.current_type === 'won' ? 'positive' : summary.streaks?.current_type === 'lost' ? 'negative' : ''"
      />
      <StatCard
        label="Ставок в игре"
        :value="summary.bets_pending || 0"
        hint="размещены, результат неизвестен"
      />
    </div>

    <div class="card" style="margin-top: 20px">
      <div class="card__title">Изменение банкролла</div>
      <div v-if="!chart.length" class="empty">Ещё нет ни одной операции по банку.</div>
      <LineChart
        v-else
        :labels="chartData.labels"
        :datasets="chartData.datasets"
        :y-formatter="(v) => money(v)"
      />
    </div>

    <div class="grid grid--2" style="margin-top: 20px">
      <div class="card">
        <div class="card__title">Сравнение со простыми стратегиями</div>
        <p class="muted" style="font-size: 13px">
          Обе базовые стратегии считаются на тех же завершённых боях плоской ставкой
          {{ money(benchmarks?.flat_stake) }}.
        </p>

        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Стратегия</th>
                <th class="num">Ставок</th>
                <th class="num">Побед</th>
                <th class="num">Профит</th>
                <th class="num">ROI</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="strategy in benchmarks?.strategies || []" :key="strategy.key">
                <td :style="strategy.key === 'model' ? 'font-weight:650' : ''">{{ strategy.name }}</td>
                <td class="num">{{ strategy.bets }}</td>
                <td class="num">{{ strategy.wins }} ({{ percent(strategy.win_rate, 0) }})</td>
                <td class="num" :class="strategy.profit >= 0 ? 'positive' : 'negative'">
                  {{ signedMoney(strategy.profit) }}
                </td>
                <td class="num" :class="strategy.roi >= 0 ? 'positive' : 'negative'">
                  {{ percent(strategy.roi) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card__title">
          <span>Калибровка прогнозов</span>
          <span v-if="accuracy" class="muted" style="font-size: 13px">
            точность {{ percent(accuracy.accuracy) }} на {{ accuracy.fights }}
            {{ plural(accuracy.fights, 'бое', 'боях', 'боях') }}
          </span>
        </div>

        <div v-if="!accuracy?.calibration?.length" class="empty">
          Нужны завершённые бои с прогнозами.
        </div>

        <LineChart
          v-else
          type="bar"
          :labels="calibrationData.labels"
          :datasets="calibrationData.datasets"
          :height="260"
          :y-formatter="(v) => v + '%'"
        />
      </div>
    </div>

    <div class="card" style="margin-top: 20px">
      <div class="card__title">История ставок</div>
      <BetsTable :bets="bets" />
    </div>
  </div>
</template>
