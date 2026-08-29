<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import ProbabilityBar from './ProbabilityBar.vue'
import { decimal, money } from '@/utils/format'

const props = defineProps({
  fight: { type: Object, required: true },
  showEvent: { type: Boolean, default: false },
})

const prediction = computed(() => props.fight.prediction)

const favourite = computed(() => {
  if (!prediction.value) return null
  return prediction.value.probability_fighter1 >= 0.5
    ? props.fight.fighter1
    : props.fight.fighter2
})

const recommendation = computed(() => prediction.value?.recommended)

const hasRecommendation = computed(
  () => recommendation.value?.stake && Number(recommendation.value.stake) > 0
)
</script>

<template>
  <RouterLink :to="`/fights/${fight.id}`" class="card fight-card">
    <div class="fight-card__head">
      <div>
        <div class="fight-card__names">
          {{ fight.fighter1?.name || '—' }}
          <span class="muted">против</span>
          {{ fight.fighter2?.name || '—' }}
        </div>
        <div class="fight-card__meta">
          <span v-if="showEvent && fight.event">{{ fight.event.name }} · </span>
          <span>{{ fight.weight_class || 'весовая категория не указана' }}</span>
          <span> · {{ fight.scheduled_rounds }} раунда</span>
          <span v-if="fight.is_title_fight" class="badge badge--accent" style="margin-left: 6px">титульный</span>
          <span v-if="fight.is_main_event" class="badge badge--info" style="margin-left: 6px">главный бой</span>
        </div>
      </div>

      <div v-if="hasRecommendation" class="badge badge--positive">
        ставка {{ money(recommendation.stake) }}
      </div>
    </div>

    <ProbabilityBar
      v-if="prediction"
      :first="prediction.probability_fighter1"
      :second="prediction.probability_fighter2"
      :first-name="fight.fighter1?.name"
      :second-name="fight.fighter2?.name"
    />

    <p v-else class="muted" style="margin: 8px 0 0; font-size: 13px">
      Прогноз ещё не рассчитан.
    </p>

    <div v-if="prediction" class="fight-card__footer">
      <span>
        Фаворит модели: <strong>{{ favourite?.name }}</strong>
      </span>
      <span class="muted">
        уверенность {{ Math.round(prediction.confidence * 100) }}%
        <template v-if="hasRecommendation">
          · коэффициент {{ decimal(recommendation.odds) }}
        </template>
      </span>
    </div>
  </RouterLink>
</template>

<style scoped>
.fight-card {
  display: block;
  transition: border-color 0.12s, transform 0.12s;
}

.fight-card:hover {
  border-color: var(--accent);
}

.fight-card__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
}

.fight-card__names {
  font-weight: 650;
  font-size: 16px;
  margin-bottom: 3px;
}

.fight-card__meta {
  font-size: 13px;
  color: var(--text-muted);
}

.fight-card__footer {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 10px;
  font-size: 13px;
}
</style>
