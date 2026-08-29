<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BetsTable from '@/components/BetsTable.vue'
import FightCard from '@/components/FightCard.vue'
import StatCard from '@/components/StatCard.vue'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { dateTime, methodLabel, money, percent, relativeDays, signedMoney } from '@/utils/format'

const ui = useUiStore()

const data = ref(null)
const loading = ref(true)

const summary = computed(() => data.value?.summary || {})

async function load() {
  loading.value = true
  try {
    data.value = await api.dashboard()
  } catch (e) {
    ui.error(e.friendlyMessage || 'Не удалось загрузить дашборд')
  } finally {
    loading.value = false
  }
}

async function refreshAll() {
  await ui.run(async () => {
    await api.syncEvents(false)
    await api.syncOdds()
    await load()
  }, 'Данные обновлены')
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
    <div class="page-header">
      <div>
        <h1>Дашборд</h1>
        <p class="page-header__subtitle">
          Текущее состояние виртуального банка, ближайшие бои и рекомендации
        </p>
      </div>
      <div class="btn-row">
        <button class="btn" type="button" :disabled="ui.busy" @click="refreshAll">
          Обновить данные
        </button>
      </div>
    </div>

    <div v-if="loading" class="grid grid--stats">
      <div v-for="n in 4" :key="n" class="stat">
        <div class="skeleton" style="width: 60%; margin-bottom: 10px" />
        <div class="skeleton" style="height: 24px; width: 80%" />
      </div>
    </div>

    <template v-else>
      <div class="grid grid--stats">
        <StatCard
          label="Банкролл"
          :value="money(summary.bankroll)"
          :hint="`старт ${money(summary.starting_bankroll)}`"
        />
        <StatCard
          label="Профит"
          :value="signedMoney(summary.profit)"
          :tone="Number(summary.profit) >= 0 ? 'positive' : 'negative'"
          :hint="percent(summary.profit_percent) + ' от банка'"
        />
        <StatCard
          label="ROI"
          :value="percent(summary.roi)"
          :tone="Number(summary.roi) >= 0 ? 'positive' : 'negative'"
          :hint="`оборот ${money(summary.total_staked)}`"
        />
        <StatCard
          label="Выигрышных ставок"
          :value="percent(summary.win_rate)"
          :hint="`${summary.wins || 0} из ${(summary.wins || 0) + (summary.losses || 0)}`"
        />
      </div>

      <div class="grid grid--2" style="margin-top: 20px">
        <div>
          <div class="card">
            <div class="card__title">
              <span>Ближайшие турниры</span>
              <RouterLink to="/events" class="muted" style="font-size: 13px">все турниры →</RouterLink>
            </div>

            <div v-if="!data.upcoming_events.length" class="empty">
              Турниров не найдено. Нажмите «Обновить данные» или загрузите карды вручную.
            </div>

            <RouterLink
              v-for="event in data.upcoming_events"
              :key="event.id"
              :to="`/events/${event.id}`"
              class="event-row"
            >
              <div>
                <div style="font-weight: 600">{{ event.name }}</div>
                <div class="muted" style="font-size: 13px">
                  {{ dateTime(event.starts_at) }} · {{ event.city || 'место уточняется' }}
                </div>
              </div>
              <div style="text-align: right">
                <div class="badge">{{ event.fights_count }} боёв</div>
                <div class="muted" style="font-size: 12px; margin-top: 4px">
                  {{ relativeDays(event.starts_at) }}
                </div>
              </div>
            </RouterLink>
          </div>

          <div class="card">
            <div class="card__title">Последние результаты</div>

            <div v-if="!data.recent_results.length" class="empty">Результатов пока нет.</div>

            <div v-for="fight in data.recent_results" :key="fight.id" class="event-row">
              <div>
                <RouterLink :to="`/fights/${fight.id}`" style="font-weight: 600">
                  {{ fight.fighter1?.name }} — {{ fight.fighter2?.name }}
                </RouterLink>
                <div class="muted" style="font-size: 13px">
                  <template v-if="fight.result">
                    Победа: {{ fight.result.winner_name || (fight.result.is_draw ? 'ничья' : '—') }},
                    {{ methodLabel(fight.result.method) }}
                  </template>
                </div>
              </div>
              <span
                v-if="fight.prediction && fight.result?.winner_name"
                class="badge"
                :class="
                  (fight.prediction.probability_fighter1 >= 0.5
                    ? fight.fighter1?.name
                    : fight.fighter2?.name) === fight.result.winner_name
                    ? 'badge--positive'
                    : 'badge--negative'
                "
              >
                {{
                  (fight.prediction.probability_fighter1 >= 0.5
                    ? fight.fighter1?.name
                    : fight.fighter2?.name) === fight.result.winner_name
                    ? 'прогноз верен'
                    : 'прогноз неверен'
                }}
              </span>
            </div>
          </div>
        </div>

        <div>
          <div class="card">
            <div class="card__title">Ближайшие бои</div>

            <div v-if="!data.next_fights.length" class="empty">
              Боёв не найдено.
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px">
              <FightCard
                v-for="fight in data.next_fights"
                :key="fight.id"
                :fight="fight"
                show-event
              />
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top: 20px">
        <div class="card__title">
          <span>Рекомендованные ставки</span>
          <span class="muted" style="font-size: 13px">
            размещение списывает сумму с виртуального банка
          </span>
        </div>

        <BetsTable
          :bets="data.recommended_bets"
          selectable
          @place="placeBets"
          @skip="skipBet"
        />
      </div>
    </template>
  </div>
</template>

<style scoped>
.event-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 11px 0;
  border-bottom: 1px solid var(--border);
}

.event-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
</style>
