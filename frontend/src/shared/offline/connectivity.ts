import { onlineManager } from '@tanstack/react-query'

/**
 * Whether the server can actually be reached. The browser's own flag only
 * says whether there is a network: a phone on courthouse Wi-Fi without
 * internet, or with one bar of signal, reports "online" while every request
 * fails. So a request that fails for want of a connection marks the app
 * offline; data on screen stays, queries wait instead of failing, and a
 * light check every few seconds brings everything back once the server
 * answers.
 */
let probe: number | null = null

export function noteNetworkFailure(): void {
  if (onlineManager.isOnline()) onlineManager.setOnline(false)
  probe ??= window.setInterval(check, 8000)
}

async function check(): Promise<void> {
  try {
    // Any answer at all (even an error status) means the server is reachable.
    await fetch('/api/health', { cache: 'no-store', credentials: 'omit' })
    markOnline()
  } catch {
    // Still unreachable; try again on the next tick.
  }
}

function markOnline(): void {
  if (probe !== null) {
    window.clearInterval(probe)
    probe = null
  }
  onlineManager.setOnline(true)
}

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => void check())
  window.addEventListener('offline', () => noteNetworkFailure())
  if (typeof navigator !== 'undefined' && navigator.onLine === false) noteNetworkFailure()
}
