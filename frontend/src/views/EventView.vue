<script setup>
import { computed, onMounted, ref } from 'vue'
import FightCard from '@/components/FightCard.vue'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { dateTime, relativeDays } from '@/utils/format'

const props = defineProps({ id: { type: [String, Number], required: true } })

const ui = useUiStore()
const event = ref(null)
const loading = ref(true)

const segments = computed(() => {
  if (!event.value) return []

  const groups = { main: [], prelim: [], early_prelim: [] }

  for (const fight of event.value.fights) {
    ;(groups[fight.card_segment] || groups.main).push(fight)
  }

  return [
    { key: 'main', title: 'Основной кард', fights: groups.main },
    { key: 'prelim', title: 'Прелимы', fights: groups.prelim },
    { key: 'early_prelim', title: 'Ранние прелимы', fights: groups.early_prelim },
  ].filter((group) => group.fights.length)
})

async function load() {
  loading.value = true
  try {
    event.value = await api.event(props.id)
  } catch (e) {
    ui.error(e.friendlyMessage)
  } finally {
    loading.value = false
  }
}

async function predict() {
  await ui.run(() => api.predictEvent(props.id), (r) => r.message)
  await load()
}

async function refreshOdds() {
  await ui.run(() => api.refreshEventOdds(props.id), (r) => r.message)
  await load()
}

async function fetchResults() {
  await ui.run(() => api.fetchEventResults(props.id), (r) => r.message)
  await load()
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="card">
      <div class="skeleton" style="height: 24px; width: 40%; margin-bottom: 14px" />
      <div class="skeleton" style="height: 16px; width: 60%" />
    </div>

    <template v-else-if="event">
      <div class="page-header">
        <div>
          <h1>{{ event.name }}</h1>
          <p class="page-header__subtitle">
            {{ dateTime(event.starts_at) }} · {{ relativeDays(event.starts_at) }}
            <template v-if="event.venue"> · {{ event.venue }}</template>
            <template v-if="event.city">, {{ event.city }}</template>
            <template v-if="event.altitude_meters">
              · высота {{ event.altitude_meters }} м
            </template>
          </p>
        </div>

        <div class="btn-row">
          <button class="btn" type="button" :disabled="ui.busy" @click="refreshOdds">
            Обновить коэффициенты
          </button>
          <button class="btn btn--primary" type="button" :disabled="ui.busy" @click="predict">
            Пересчитать прогнозы
          </button>
          <button
            v-if="event.status !== 'scheduled' || new Date(event.starts_at) < new Date()"
            class="btn"
            type="button"
            :disabled="ui.busy"
            @click="fetchResults"
          >
            Получить актуальные результаты
          </button>
        </div>
      </div>

      <div v-if="!event.fights.length" class="card">
        <div class="empty">
          Бои этого турнира ещё не загружены. Нажмите «Загрузить с ufc.com» на странице турниров.
        </div>
      </div>

      <section v-for="group in segments" :key="group.key" style="margin-bottom: 24px">
        <h2>{{ group.title }}</h2>
        <div style="display: flex; flex-direction: column; gap: 12px">
          <FightCard v-for="fight in group.fights" :key="fight.id" :fight="fight" />
        </div>
      </section>
    </template>
  </div>
</template>
