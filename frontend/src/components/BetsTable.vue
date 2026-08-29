<script setup>
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  betStatusClass,
  betStatusLabel,
  decimal,
  marketLabel,
  money,
  percent,
  selectionLabel,
  share,
  signedMoney,
} from '@/utils/format'

const props = defineProps({
  bets: { type: Array, default: () => [] },
  selectable: { type: Boolean, default: false },
  showFight: { type: Boolean, default: true },
  // Нужен, чтобы вместо «первый боец» показывать его имя
  fight: { type: Object, default: null },
})

const emit = defineEmits(['place', 'skip'])

const selected = ref([])

const selectableBets = computed(() => props.bets.filter((bet) => bet.status === 'recommended'))

const allSelected = computed(
  () => selectableBets.value.length > 0 && selected.value.length === selectableBets.value.length
)

function toggleAll() {
  selected.value = allSelected.value ? [] : selectableBets.value.map((bet) => bet.id)
}

function place() {
  if (selected.value.length) {
    emit('place', [...selected.value])
    selected.value = []
  }
}

function profitTone(bet) {
  if (bet.profit === null || bet.profit === undefined) return ''
  return Number(bet.profit) >= 0 ? 'positive' : 'negative'
}
</script>

<template>
  <div>
    <div v-if="selectable && selectableBets.length" class="btn-row" style="margin-bottom: 12px">
      <button class="btn btn--sm" type="button" @click="toggleAll">
        {{ allSelected ? 'Снять выделение' : 'Выбрать все рекомендации' }}
      </button>
      <button class="btn btn--sm btn--primary" type="button" :disabled="!selected.length" @click="place">
        Разместить выбранные ({{ selected.length }})
      </button>
    </div>

    <div v-if="!bets.length" class="empty">Ставок пока нет.</div>

    <div v-else class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th v-if="selectable" style="width: 34px"></th>
            <th v-if="showFight">Бой</th>
            <th>Рынок</th>
            <th>Исход</th>
            <th class="num">Коэф.</th>
            <th class="num">Модель</th>
            <th class="num">EV</th>
            <th class="num">Ставка</th>
            <th>Статус</th>
            <th class="num">Результат</th>
            <th v-if="selectable" style="width: 40px"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="bet in bets" :key="bet.id">
            <td v-if="selectable">
              <input
                v-if="bet.status === 'recommended'"
                v-model="selected"
                type="checkbox"
                :value="bet.id"
              />
            </td>
            <td v-if="showFight">
              <RouterLink v-if="bet.fight_id" :to="`/fights/${bet.fight_id}`">
                {{ bet.fight || 'Бой #' + bet.fight_id }}
              </RouterLink>
              <span v-else>—</span>
            </td>
            <td>{{ marketLabel(bet.market) }}</td>
            <td>{{ selectionLabel(bet, fight) }}</td>
            <td class="num">{{ decimal(bet.odds) }}</td>
            <td class="num">
              {{ share(bet.model_probability) }}
              <span class="muted" style="font-size: 12px"> / {{ share(bet.implied_probability) }}</span>
            </td>
            <td class="num" :class="Number(bet.expected_value) > 0 ? 'positive' : ''">
              {{ percent(Number(bet.expected_value) * 100) }}
            </td>
            <td class="num">{{ money(bet.stake) }}</td>
            <td>
              <span class="badge" :class="betStatusClass(bet.status)">
                {{ betStatusLabel(bet.status) }}
              </span>
            </td>
            <td class="num" :class="profitTone(bet)">
              {{ bet.profit === null || bet.profit === undefined ? '—' : signedMoney(bet.profit) }}
            </td>
            <td v-if="selectable">
              <button
                v-if="bet.status === 'recommended'"
                class="btn btn--sm"
                type="button"
                title="Отклонить рекомендацию"
                @click="emit('skip', bet.id)"
              >
                ✕
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
