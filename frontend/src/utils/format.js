/** Форматирование значений для интерфейса. Язык — только русский. */

const rubles = new Intl.NumberFormat('ru-RU', {
  style: 'currency',
  currency: 'RUB',
  maximumFractionDigits: 0,
})

const numbers = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 })

export function money(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'
  return rubles.format(Number(value))
}

export function signedMoney(value) {
  const number = Number(value ?? 0)
  const sign = number > 0 ? '+' : ''
  return sign + money(number)
}

export function percent(value, digits = 1) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'
  return `${Number(value).toFixed(digits)}%`
}

/** Доля 0..1 -> «63%» */
export function share(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'
  return `${(Number(value) * 100).toFixed(digits)}%`
}

export function decimal(value, digits = 2) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'
  return numbers.format(Number(Number(value).toFixed(digits)))
}

export function dateTime(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function dateOnly(value) {
  if (!value) return '—'
  return new Date(value).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

export function relativeDays(value) {
  if (!value) return ''
  const diff = Math.round((new Date(value) - Date.now()) / 86400000)
  if (diff === 0) return 'сегодня'
  if (diff === 1) return 'завтра'
  if (diff === -1) return 'вчера'
  if (diff > 0) return `через ${diff} ${plural(diff, 'день', 'дня', 'дней')}`
  return `${Math.abs(diff)} ${plural(Math.abs(diff), 'день', 'дня', 'дней')} назад`
}

export function plural(count, one, few, many) {
  const mod10 = count % 10
  const mod100 = count % 100
  if (mod10 === 1 && mod100 !== 11) return one
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return few
  return many
}

const MARKET_LABELS = {
  moneyline: 'Победа',
  draw: 'Ничья',
  totals: 'Тотал раундов',
  method: 'Метод победы',
}

const SELECTION_LABELS = {
  fighter1: 'первый боец',
  fighter2: 'второй боец',
  draw: 'ничья',
  over: 'больше',
  under: 'меньше',
  ko_tko: 'нокаут',
  submission: 'сабмишен',
  decision: 'решение',
}

export function marketLabel(market) {
  return MARKET_LABELS[market] || market
}

export function selectionLabel(bet, fight) {
  const selection = bet.selection

  if (bet.market === 'moneyline' && fight) {
    return selection === 'fighter1' ? fight.fighter1?.name : fight.fighter2?.name
  }

  if (bet.market === 'totals') {
    return `${SELECTION_LABELS[selection] || selection} ${bet.line ?? 2.5}`
  }

  return SELECTION_LABELS[selection] || selection
}

const METHOD_LABELS = {
  ko_tko: 'нокаут / технический нокаут',
  submission: 'сабмишен',
  decision: 'решение судей',
  dq: 'дисквалификация',
  other: 'иное',
}

export function methodLabel(method) {
  return METHOD_LABELS[method] || '—'
}

const STATUS_LABELS = {
  recommended: 'рекомендована',
  placed: 'размещена',
  won: 'выигрыш',
  lost: 'проигрыш',
  void: 'возврат',
  skipped: 'пропущена',
}

export function betStatusLabel(status) {
  return STATUS_LABELS[status] || status
}

export function betStatusClass(status) {
  return {
    won: 'badge--positive',
    lost: 'badge--negative',
    placed: 'badge--info',
    recommended: 'badge--accent',
    void: 'badge--warning',
  }[status] || ''
}

const STYLE_LABELS = {
  wrestler: 'борец',
  striker: 'ударник',
  grappler: 'грэпплер',
  balanced: 'универсал',
  unknown: 'не определён',
}

export function styleLabel(style) {
  return STYLE_LABELS[style] || style
}

const STANCE_LABELS = {
  orthodox: 'правша',
  southpaw: 'левша',
  switch: 'универсал',
  unknown: '—',
}

export function stanceLabel(stance) {
  return STANCE_LABELS[stance] || stance
}
