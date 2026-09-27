const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })
const pesoCompact = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', notation: 'compact', maximumFractionDigits: 1 })

/** ₱1,234.56 from integer centavos. */
export function money(cents: number | null | undefined): string {
  return peso.format((cents ?? 0) / 100)
}

/** ₱1.2M — for dense dashboards. */
export function moneyCompact(cents: number | null | undefined): string {
  return pesoCompact.format((cents ?? 0) / 100)
}

/** Parse a peso amount typed by a user ("1,500.50") into centavos. */
export function toCents(input: string | number): number {
  const value = typeof input === 'number' ? input : Number(String(input).replace(/[₱,\s]/g, ''))
  return Number.isFinite(value) ? Math.round(value * 100) : NaN
}

/**
 * Parse a date-only string (YYYY-MM-DD) as a local calendar date. `new Date('2026-07-09')`
 * is UTC midnight, which is the previous day in timezones west of UTC.
 */
export function parseDate(value: string): Date {
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1)
}

/** YYYY-MM-DD for a local Date. */
export function isoDate(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

export function today(): string {
  return isoDate(new Date())
}

const dateFormat = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
const longDateFormat = new Intl.DateTimeFormat('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
const dateTimeFormat = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })

export function date(value: string | null | undefined): string {
  if (!value) return '—'
  return dateFormat.format(value.length <= 10 ? parseDate(value) : new Date(value))
}

export function longDate(value: string): string {
  return longDateFormat.format(parseDate(value))
}

export function dateTime(value: string | null | undefined): string {
  return value ? dateTimeFormat.format(new Date(value)) : '—'
}

/** "2h 30m" from minutes. */
export function duration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m}m`
  return m === 0 ? `${h}h` : `${h}h ${m}m`
}

/** "in 3 days", "today", "2 days ago". */
export function relativeDays(days: number): string {
  if (days === 0) return 'today'
  if (days === 1) return 'tomorrow'
  if (days === -1) return 'yesterday'
  return days > 0 ? `in ${days} days` : `${-days} days ago`
}

export function initials(name: string): string {
  return name
    .replace(/^Atty\.\s*/i, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

/** "2.4 MB" from bytes. */
export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  return `${value.toFixed(value < 10 ? 1 : 0)} ${units[unit]}`
}
