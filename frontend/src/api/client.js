import axios from 'axios'

/**
 * Единый HTTP-клиент. Базовый адрес берётся из переменной окружения
 * VITE_API_URL, по умолчанию — относительный /api (nginx проксирует
 * запросы на Laravel).
 */
const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  timeout: 120000,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

client.interceptors.response.use(
  (response) => response,
  (error) => {
    const data = error.response?.data
    let message = 'Не удалось связаться с сервером'

    if (data?.message) {
      message = data.message
    }

    if (data?.errors) {
      message = Object.values(data.errors).flat().join(' ')
    }

    if (error.code === 'ECONNABORTED') {
      message = 'Запрос выполняется слишком долго — попробуйте ещё раз'
    }

    error.friendlyMessage = message

    return Promise.reject(error)
  }
)

export default client

export const api = {
  dashboard: () => client.get('/dashboard').then((r) => r.data),

  events: (params) => client.get('/events', { params }).then((r) => r.data.data),
  event: (id) => client.get(`/events/${id}`).then((r) => r.data.data),
  predictEvent: (id) => client.post(`/events/${id}/predict`).then((r) => r.data),
  refreshEventOdds: (id) => client.post(`/events/${id}/odds`).then((r) => r.data),
  fetchEventResults: (id) => client.post(`/events/${id}/results`).then((r) => r.data),

  fight: (id) => client.get(`/fights/${id}`).then((r) => r.data.data),
  predictFight: (id) => client.post(`/fights/${id}/predict`).then((r) => r.data),
  storeOdds: (id, odds) => client.post(`/fights/${id}/odds`, { odds }).then((r) => r.data),
  storeResult: (id, payload) => client.post(`/fights/${id}/result`, payload).then((r) => r.data),
  searchResult: (id) => client.get(`/fights/${id}/search-result`).then((r) => r.data.data),

  fighters: (params) => client.get('/fighters', { params }).then((r) => r.data),
  fighter: (id) => client.get(`/fighters/${id}`).then((r) => r.data),
  refreshFighter: (id) => client.post(`/fighters/${id}/refresh`).then((r) => r.data),

  bets: (params) => client.get('/bets', { params }).then((r) => r.data),
  placeBets: (betIds) => client.post('/bets/place', { bet_ids: betIds }).then((r) => r.data),
  skipBet: (id) => client.post(`/bets/${id}/skip`).then((r) => r.data),

  summary: (params) => client.get('/statistics/summary', { params }).then((r) => r.data.data),
  bankrollChart: (params) => client.get('/statistics/bankroll', { params }).then((r) => r.data.data),
  benchmarks: () => client.get('/statistics/benchmarks').then((r) => r.data.data),
  accuracy: () => client.get('/statistics/accuracy').then((r) => r.data.data),
  history: (params) => client.get('/statistics/history', { params }).then((r) => r.data),

  settings: () => client.get('/settings').then((r) => r.data),
  updateSettings: (payload) => client.put('/settings', payload).then((r) => r.data),
  resetBankroll: (amount) => client.post('/settings/bankroll/reset', { amount }).then((r) => r.data),

  syncEvents: (force) => client.post('/sync/events', { force }).then((r) => r.data),
  syncFighters: (force) => client.post('/sync/fighters', { force }).then((r) => r.data),
  syncOdds: () => client.post('/sync/odds').then((r) => r.data),
  syncStatus: () => client.get('/sync/status').then((r) => r.data.data),

  backtest: (payload) => client.post('/backtest', payload).then((r) => r.data.data),
}
