<script setup>
import { onMounted } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import { useUiStore } from '@/stores/ui'

const settings = useSettingsStore()
const ui = useUiStore()

const links = [
  { to: '/', label: 'Дашборд', icon: '◉' },
  { to: '/events', label: 'Турниры', icon: '▤' },
  { to: '/statistics', label: 'Статистика', icon: '◧' },
  { to: '/history', label: 'История боёв', icon: '⏱' },
  { to: '/fighters', label: 'Бойцы', icon: '☗' },
  { to: '/settings', label: 'Настройки', icon: '⚙' },
]

onMounted(() => {
  settings.load().catch(() => {
    ui.error('Не удалось загрузить настройки. Проверьте, запущен ли бекенд.')
  })
})
</script>

<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar__brand">
        <span class="sidebar__logo">UFC</span>
        <span>Predict</span>
      </div>

      <RouterLink v-for="link in links" :key="link.to" :to="link.to" class="nav-link">
        <span aria-hidden="true">{{ link.icon }}</span>
        <span>{{ link.label }}</span>
      </RouterLink>

      <div class="sidebar__footer">
        <button class="btn btn--sm" type="button" @click="settings.toggleTheme()">
          {{ settings.theme === 'dark' ? '☀ Светлая тема' : '☾ Тёмная тема' }}
        </button>
        <p style="margin-top: 10px; font-size: 12px">
          Виртуальные ставки. Реальные деньги не используются.
        </p>
      </div>
    </aside>

    <main class="content">
      <RouterView />
    </main>

    <div class="toast-stack">
      <div
        v-for="toast in ui.toasts"
        :key="toast.id"
        class="toast"
        :class="`toast--${toast.type}`"
        @click="ui.dismiss(toast.id)"
      >
        {{ toast.message }}
      </div>
    </div>
  </div>
</template>
