import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

/** The value, after it has stopped changing for `delay` ms. */
export function useDebounced<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay)
    return () => window.clearTimeout(timer)
  }, [value, delay])
  return debounced
}

/**
 * A string filter kept in the URL query string, so filtered views can be
 * bookmarked, shared and survive a reload. Setting a filter resets paging.
 */
export function useUrlState(key: string, fallback = ''): [string, (value: string) => void] {
  const [params, setParams] = useSearchParams()
  const value = params.get(key) ?? fallback

  const setValue = useCallback(
    (next: string) => {
      setParams(
        (current) => {
          const updated = new URLSearchParams(current)
          if (next === '' || next === fallback) updated.delete(key)
          else updated.set(key, next)
          if (key !== 'page') updated.delete('page')
          return updated
        },
        { replace: true },
      )
    },
    [key, fallback, setParams],
  )

  return [value, setValue]
}

export function useUrlPage(): [number, (page: number) => void] {
  const [raw, setRaw] = useUrlState('page', '1')
  return [Math.max(1, Number(raw) || 1), (page: number) => setRaw(String(page))]
}
