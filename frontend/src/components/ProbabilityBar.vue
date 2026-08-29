<script setup>
import { computed } from 'vue'

const props = defineProps({
  first: { type: Number, default: 0.5 },
  second: { type: Number, default: 0.5 },
  firstName: { type: String, default: '' },
  secondName: { type: String, default: '' },
})

const firstPercent = computed(() => Math.round(props.first * 100))
const secondPercent = computed(() => 100 - firstPercent.value)
</script>

<template>
  <div>
    <div
      v-if="firstName || secondName"
      style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 5px"
    >
      <span>{{ firstName }}</span>
      <span class="muted">{{ secondName }}</span>
    </div>

    <div class="probability">
      <div class="probability__side probability__side--first" :style="{ width: firstPercent + '%' }">
        {{ firstPercent }}%
      </div>
      <div class="probability__side probability__side--second" :style="{ width: secondPercent + '%' }">
        {{ secondPercent }}%
      </div>
    </div>
  </div>
</template>
