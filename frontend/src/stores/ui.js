import { defineStore } from 'pinia'
import { ref } from 'vue'

/** Уведомления и общее состояние интерфейса. */
export const useUiStore = defineStore('ui', () => {
  const toasts = ref([])
  const busy = ref(false)
  let nextId = 1

  function notify(message, type = 'info', timeout = 4500) {
    const id = nextId++
    toasts.value.push({ id, message, type })
    setTimeout(() => dismiss(id), timeout)
  }

  function success(message) {
    notify(message, 'success')
  }

  function error(message) {
    notify(message, 'error', 7000)
  }

  function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  /** Обёртка для действий: показывает индикатор и ловит ошибки. */
  async function run(action, successMessage) {
    busy.value = true
    try {
      const result = await action()
      if (successMessage) success(typeof successMessage === 'function' ? successMessage(result) : successMessage)
      return result
    } catch (e) {
      error(e.friendlyMessage || e.message || 'Произошла ошибка')
      throw e
    } finally {
      busy.value = false
    }
  }

  return { toasts, busy, notify, success, error, dismiss, run }
})
