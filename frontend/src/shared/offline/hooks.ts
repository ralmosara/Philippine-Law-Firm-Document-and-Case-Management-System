import { onlineManager, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useState, useSyncExternalStore } from 'react'
import { failed, flush, pending, subscribe, type FailedWrite, type QueuedWrite } from './queue'

/** Whether the server can be reached (see connectivity.ts), kept current. */
export function useOnline(): boolean {
  return useSyncExternalStore(
    (notify) => onlineManager.subscribe(notify),
    () => onlineManager.isOnline(),
    () => true,
  )
}

/**
 * Saves waiting for a signal, and those the server refused on arrival.
 * Sends the waiting ones when the connection returns, when the app comes
 * back to the foreground, and every half minute while any are waiting.
 */
export function useOfflineQueue(userId: number) {
  const queryClient = useQueryClient()
  const online = useOnline()
  const [waiting, setWaiting] = useState<QueuedWrite[]>([])
  const [refused, setRefused] = useState<FailedWrite[]>([])

  const refresh = useCallback(async () => {
    try {
      setWaiting(await pending(userId))
      setRefused(await failed(userId))
    } catch {
      // IndexedDB unavailable (private mode): nothing can be waiting.
    }
  }, [userId])

  const send = useCallback(async () => {
    try {
      if ((await flush(userId)) > 0) await queryClient.invalidateQueries()
    } catch {
      // Tried again on the next trigger.
    }
  }, [userId, queryClient])

  useEffect(() => {
    void refresh()
    return subscribe(() => void refresh())
  }, [refresh])

  useEffect(() => {
    if (online) void send()
  }, [online, send])

  useEffect(() => {
    if (!waiting.length) return
    const visible = () => document.visibilityState === 'visible' && void send()
    const timer = window.setInterval(() => void send(), 30_000)
    document.addEventListener('visibilitychange', visible)
    return () => {
      window.clearInterval(timer)
      document.removeEventListener('visibilitychange', visible)
    }
  }, [waiting.length, send])

  return { online, waiting, refused, send }
}
