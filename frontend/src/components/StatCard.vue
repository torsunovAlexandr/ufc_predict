<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], default: '—' },
  hint: { type: String, default: '' },
  tone: { type: String, default: '' }, // positive | negative | ''
})
</script>

<template>
  <div class="stat">
    <div class="stat__label">{{ label }}</div>
    <div class="stat__value" :class="tone">{{ value }}</div>
    <div v-if="hint" class="stat__hint">{{ hint }}</div>
  </div>
</template>
