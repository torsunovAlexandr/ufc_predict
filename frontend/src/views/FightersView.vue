<script setup>
import { onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import { useUiStore } from '@/stores/ui'
import { decimal, dateOnly, share, stanceLabel, styleLabel } from '@/utils/format'

const ui = useUiStore()

const fighters = ref([])
const meta = ref({})
const loading = ref(true)
const filters = reactive({ search: '', page: 1 })

let searchTimer = null

async function load() {
  loading.value = true
  try {
    const response = await api.fighters({
      search: filters.search || undefined,
      page: filters.page,
      per_page: 40,
    })
    fighters.value = response.data
    meta.value = response.meta
  } catch (e) {
    ui.error(e.friendlyMessage)
  } finally {
    loading.value = false
  }
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.page = 1
    load()
  }, 350)
}

async function refresh(fighter) {
  await ui.run(() => api.refreshFighter(fighter.id), (r) => r.message)
  await load()
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
        <h1>Бойцы</h1>
        <p class="page-header__subtitle">Карточки бойцов и их карьерная статистика</p>
      </div>
    </div>

    <div class="filters">
      <div class="field" style="min-width: 260px">
        <label class="field__label" for="search">Поиск по имени</label>
        <input id="search" v-model="filters.search" type="text" placeholder="Например: Volkov" @input="onSearch" />
      </div>
    </div>

    <div v-if="loading" class="card">
      <div v-for="n in 8" :key="n" class="skeleton" style="height: 20px; margin-bottom: 12px" />
    </div>

    <div v-else-if="!fighters.length" class="card">
      <div class="empty">Бойцы не найдены. Они появятся после загрузки карда турнира.</div>
    </div>

    <template v-else>
      <div class="card table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>Боец</th>
              <th>Рекорд</th>
              <th>Категория</th>
              <th class="num">Удары/мин</th>
              <th class="num">Тейкдауны</th>
              <th class="num">Защита от TD</th>
              <th>Стиль</th>
              <th>Обновлено</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="fighter in fighters" :key="fighter.id">
              <td>
                <div style="font-weight: 600">{{ fighter.name }}</div>
                <div class="muted" style="font-size: 12px">
                  <template v-if="fighter.nickname">«{{ fighter.nickname }}» · </template>
                  {{ fighter.age ? fighter.age + ' лет' : 'возраст —' }} ·
                  {{ stanceLabel(fighter.stance) }}
                </div>
              </td>
              <td>{{ fighter.record }}</td>
              <td>{{ fighter.weight_class || '—' }}</td>
              <td class="num">{{ decimal(fighter.stats?.sig_strikes_per_min) }}</td>
              <td class="num">{{ decimal(fighter.stats?.takedown_avg) }}</td>
              <td class="num">{{ share(fighter.stats?.takedown_defense) }}</td>
              <td>{{ styleLabel(fighter.style) }}</td>
              <td class="muted" style="font-size: 12px">
                {{ fighter.last_scraped_at ? dateOnly(fighter.last_scraped_at) : 'никогда' }}
              </td>
              <td>
                <button class="btn btn--sm" type="button" :disabled="ui.busy" @click="refresh(fighter)">
                  Обновить
                </button>
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
          Страница {{ meta.current_page }} из {{ meta.last_page }} · всего {{ meta.total }}
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
