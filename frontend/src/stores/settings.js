import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'

const THEME_KEY = 'ufc-predict-theme'

export const useSettingsStore = defineStore('settings', () => {
  const values = ref({})
  const definitions = ref({})
  const loaded = ref(false)
  const theme = ref(localStorage.getItem(THEME_KEY) || 'dark')

  applyTheme(theme.value)

  function applyTheme(value) {
    theme.value = value
    document.documentElement.setAttribute('data-theme', value)
    localStorage.setItem(THEME_KEY, value)
  }

  function toggleTheme() {
    const next = theme.value === 'dark' ? 'light' : 'dark'
    applyTheme(next)
    // Сохраняем выбор на сервере, но интерфейс не ждёт ответа
    api.updateSettings({ theme: next }).catch(() => {})
  }

  async function load(force = false) {
    if (loaded.value && !force) return values.value

    const response = await api.settings()
    values.value = response.data
    definitions.value = response.definitions
    loaded.value = true

    if (response.data.theme && response.data.theme !== theme.value) {
      applyTheme(response.data.theme)
    }

    return values.value
  }

  async function save(payload) {
    const response = await api.updateSettings(payload)
    values.value = response.data
    if (payload.theme) applyTheme(payload.theme)
    return response
  }

  return { values, definitions, loaded, theme, load, save, applyTheme, toggleTheme }
})
