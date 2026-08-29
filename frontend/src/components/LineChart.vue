<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Chart from 'chart.js/auto'

const props = defineProps({
  labels: { type: Array, default: () => [] },
  datasets: { type: Array, default: () => [] },
  type: { type: String, default: 'line' },
  height: { type: Number, default: 300 },
  yFormatter: { type: Function, default: null },
})

const canvas = ref(null)
let chart = null

function themeColor(name, fallback) {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

function build() {
  if (!canvas.value) return

  const grid = themeColor('--border', '#2a2f37')
  const text = themeColor('--text-muted', '#8f9bab')

  chart = new Chart(canvas.value, {
    type: props.type,
    data: {
      labels: props.labels,
      datasets: props.datasets,
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: props.datasets.length > 1,
          labels: { color: text, usePointStyle: true, boxWidth: 8 },
        },
        tooltip: {
          callbacks: props.yFormatter
            ? {
                label: (context) => `${context.dataset.label || ''}: ${props.yFormatter(context.parsed.y)}`,
              }
            : {},
        },
      },
      scales: {
        x: {
          grid: { color: grid, drawBorder: false },
          ticks: { color: text, maxRotation: 0, autoSkipPadding: 20 },
        },
        y: {
          grid: { color: grid, drawBorder: false },
          ticks: {
            color: text,
            callback: (value) => (props.yFormatter ? props.yFormatter(value) : value),
          },
        },
      },
    },
  })
}

function rebuild() {
  if (chart) {
    chart.destroy()
    chart = null
  }
  build()
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())

watch(() => [props.labels, props.datasets], rebuild, { deep: true })
</script>

<template>
  <div :style="{ height: height + 'px', position: 'relative' }">
    <canvas ref="canvas" />
  </div>
</template>
