import { useLayoutEffect, useSyncExternalStore } from 'react'

/**
 * A deliberately small translation layer for the client portal, which a
 * client can switch between English and Filipino. Strings are written in
 * English in the code and used as their own keys; a missing Filipino entry
 * falls back to the English text. The firm's staff app stays in English.
 */
export type Locale = 'en' | 'fil'

export const LOCALES: { value: Locale; label: string }[] = [
  { value: 'en', label: 'English' },
  { value: 'fil', label: 'Filipino' },
]

const STORAGE_KEY = 'lexph-portal-locale'
const dictionaries: Partial<Record<Locale, Record<string, string>>> = {}
const listeners = new Set<() => void>()

function stored(): Locale {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'fil' ? 'fil' : 'en'
  } catch {
    return 'en'
  }
}

let current: Locale = 'en'

export function getLocale(): Locale {
  return current
}

/** Switch language; `remember` keeps the choice on this device (the portal before sign-in). */
export function setLocale(locale: Locale, remember = true): void {
  if (remember) {
    try {
      localStorage.setItem(STORAGE_KEY, locale)
    } catch {
      // Private mode: the choice lasts for this page only.
    }
  }
  if (locale === current) return
  current = locale
  document.documentElement.lang = locale === 'fil' ? 'fil' : 'en'
  listeners.forEach((l) => l())
}

/**
 * Wraps the portal's pages: uses the language last chosen on this device
 * (before paint, so English never flashes), and goes back to English when
 * the visitor leaves the portal for the staff app.
 */
export function usePortalLocaleScope(): void {
  useLayoutEffect(() => {
    setLocale(stored(), false)
    return () => setLocale('en', false)
  }, [])
}

export function registerDictionary(locale: Locale, entries: Record<string, string>): void {
  dictionaries[locale] = { ...dictionaries[locale], ...entries }
}

/** Translate an English string; `{name}` placeholders are filled from `vars`. */
export function t(text: string, vars?: Record<string, string | number | null | undefined>): string {
  const translated = dictionaries[current]?.[text] ?? text
  return vars ? translated.replace(/\{(\w+)\}/g, (match, key: string) => (vars[key] ?? match).toString()) : translated
}

/** The current locale, re-rendering when it changes. */
export function useLocale(): Locale {
  return useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    () => current,
    () => current,
  )
}

/** The BCP 47 tag for number and date formatting. */
export function intlLocale(): string {
  return current === 'fil' ? 'fil-PH' : 'en-PH'
}
