<script setup>
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { dateTime, relativeDays } from '@/utils/format'

const ui = useUiStore()

const scope = ref('upcoming')
const events = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    events.value = await api.events({ scope: scope.value, limit: 60 })
  } catch (e) {
    ui.error(e.friendlyMessage)
  } finally {
    loading.value = false
  }
}

async function sync() {
  await ui.run(() => api.syncEvents(true), (r) => r.message)
  await load()
}

watch(scope, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Турниры</h1>
        <p class="page-header__subtitle">Список турниров UFC и их карды</p>
      </div>
      <div class="btn-row">
        <button class="btn" type="button" :disabled="ui.busy" @click="sync">
          Загрузить с ufc.com
        </button>
      </div>
    </div>

    <div class="filters">
      <div class="field">
        <label class="field__label" for="scope">Показывать</label>
        <select id="scope" v-model="scope">
          <option value="upcoming">Предстоящие</option>
          <option value="past">Прошедшие</option>
          <option value="all">Все</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="card">
      <div v-for="n in 5" :key="n" class="skeleton" style="margin-bottom: 12px; height: 20px" />
    </div>

    <div v-else-if="!events.length" class="card">
      <div class="empty">
        Турниров нет. Нажмите «Загрузить с ufc.com» — система разберёт страницу
        <a href="https://www.ufc.com/events" target="_blank" rel="noopener">ufc.com/events</a>.
      </div>
    </div>

    <div v-else class="table-wrap card">
      <table class="data">
        <thead>
          <tr>
            <th>Турнир</th>
            <th>Дата</th>
            <th>Место</th>
            <th class="num">Боёв</th>
            <th>Статус</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="event in events" :key="event.id">
            <td>
              <RouterLink :to="`/events/${event.id}`" style="font-weight: 600">
                {{ event.name }}
              </RouterLink>
            </td>
            <td>
              {{ dateTime(event.starts_at) }}
              <div class="muted" style="font-size: 12px">{{ relativeDays(event.starts_at) }}</div>
            </td>
            <td>
              {{ event.city || '—' }}
              <div v-if="event.venue" class="muted" style="font-size: 12px">{{ event.venue }}</div>
            </td>
            <td class="num">{{ event.fights_count }}</td>
            <td>
              <span
                class="badge"
                :class="{
                  'badge--info': event.status === 'scheduled',
                  'badge--positive': event.status === 'completed',
                  'badge--negative': event.status === 'cancelled',
                }"
              >
                {{
                  {
                    scheduled: 'запланирован',
                    live: 'идёт',
                    completed: 'завершён',
                    cancelled: 'отменён',
                  }[event.status] || event.status
                }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
