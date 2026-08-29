<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { dateOnly, methodLabel, share } from '@/utils/format'

const ui = useUiStore()

const fights = ref([])
const meta = ref({})
const loading = ref(true)
const filters = reactive({ page: 1 })

async function load() {
  loading.value = true
  try {
    const response = await api.history({ page: filters.page, per_page: 25 })
    fights.value = response.data
    meta.value = response.meta
  } catch (e) {
    ui.error(e.friendlyMessage)
  } finally {
    loading.value = false
  }
}

function changePage(delta) {
  const next = filters.page + delta
  if (next < 1 || next > (meta.value.last_page || 1)) return
  filters.page = next
  load()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>История боёв</h1>
        <p class="page-header__subtitle">Прошедшие бои: что предсказала модель и что случилось на самом деле</p>
      </div>
    </div>

    <div v-if="loading" class="card">
      <div v-for="n in 6" :key="n" class="skeleton" style="height: 20px; margin-bottom: 12px" />
    </div>

    <div v-else-if="!fights.length" class="card">
      <div class="empty">
        Завершённых боёв с результатами пока нет. Получите результаты на странице турнира.
      </div>
    </div>

    <template v-else>
      <div class="card table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Дата</th>
              <th>Бой</th>
              <th class="num">Прогноз</th>
              <th>Победитель</th>
              <th>Метод</th>
              <th>Итог</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="fight in fights" :key="fight.id">
              <td>{{ dateOnly(fight.event?.starts_at) }}</td>
              <td>
                <RouterLink :to="`/fights/${fight.id}`">
                  {{ fight.fighter1?.name }} — {{ fight.fighter2?.name }}
                </RouterLink>
                <div class="muted" style="font-size: 12px">{{ fight.event?.name }}</div>
              </td>
              <td class="num">
                <template v-if="fight.prediction">
                  {{
                    fight.prediction.probability_fighter1 >= 0.5
                      ? fight.fighter1?.name
                      : fight.fighter2?.name
                  }}
                  <span class="muted">
                    {{
                      share(
                        Math.max(
                          fight.prediction.probability_fighter1,
                          fight.prediction.probability_fighter2
                        )
                      )
                    }}
                  </span>
                </template>
                <span v-else class="muted">не рассчитан</span>
              </td>
              <td>
                {{ fight.result?.winner_name || (fight.result?.is_draw ? 'ничья' : '—') }}
              </td>
              <td>{{ methodLabel(fight.result?.method) }}</td>
              <td>
                <span
                  v-if="fight.prediction_correct !== null"
                  class="badge"
                  :class="fight.prediction_correct ? 'badge--positive' : 'badge--negative'"
                >
                  {{ fight.prediction_correct ? 'угадано' : 'мимо' }}
                </span>
                <span v-else class="badge">нет данных</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="btn-row" style="margin-top: 14px; align-items: center">
        <button class="btn btn--sm" type="button" :disabled="filters.page <= 1" @click="changePage(-1)">
          ← Назад
        </button>
        <span class="muted" style="font-size: 13px">
          Страница {{ meta.current_page }} из {{ meta.last_page }}
        </span>
        <button
          class="btn btn--sm"
          type="button"
          :disabled="filters.page >= (meta.last_page || 1)"
          @click="changePage(1)"
        >
          Вперёд →
        </button>
      </div>
    </template>
  </div>
</template>
