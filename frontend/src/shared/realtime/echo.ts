import { useEffect, useRef } from 'react'
import { apiClient } from '@/shared/api/axios'

/**
 * One WebSocket connection (Laravel Reverb, same origin /app) shared by every
 * screen: the bell, open message threads, the assistant. Staff authorize
 * private channels at /broadcasting/auth, portal clients at
 * /portal/broadcasting/auth. Screens keep a slow poll as a fallback, so a
 * dropped connection only makes them slower, never stale for long.
 */
export type AuthEndpoint = '/broadcasting/auth' | '/portal/broadcasting/auth'

type Listener = (payload: unknown) => void

interface Channel {
  listen(event: string, callback: Listener): Channel
  stopListening(event: string, callback?: Listener): Channel
  notification(callback: Listener): Channel
  stopListeningForNotification(callback: Listener): Channel
}

interface Connection {
  private(name: string): Channel
  leave(name: string): void
  disconnect(): void
}

const connections = new Map<string, Promise<Connection>>()
const subscribers = new Map<string, number>()

function connect(key: string, authEndpoint: AuthEndpoint): Promise<Connection> {
  const id = `${key}|${authEndpoint}`
  let connection = connections.get(id)
  if (!connection) {
    connection = (async () => {
      const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
      const secure = window.location.protocol === 'https:'
      const port = Number(window.location.port) || (secure ? 443 : 80)
      return new Echo({
        broadcaster: 'reverb',
        key,
        Pusher,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        // Through the API client, so the session cookie and XSRF header go along.
        authorizer: (channel: { name: string }) => ({
          authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
            apiClient.post<{ auth: string }>(authEndpoint, { socket_id: socketId, channel_name: channel.name })
              .then((response) => callback(null, response.data))
              .catch((error: Error) => callback(error, null))
          },
        }),
      }) as unknown as Connection
    })()
    connections.set(id, connection)
  }
  return connection
}

/** Drop every connection (sign-out), so the next person starts clean. */
export function disconnectRealtime(): void {
  for (const connection of connections.values()) void connection.then((c) => c.disconnect())
  connections.clear()
  subscribers.clear()
}

/**
 * Listen on a private channel while the component is mounted. `events`
 * maps broadcast names (".thread.updated") to handlers; "notification"
 * receives Laravel notifications. Nothing happens without a realtime key.
 */
export function usePrivateChannel(
  realtime: { key: string } | null | undefined,
  authEndpoint: AuthEndpoint,
  channel: string | null,
  events: Record<string, Listener>,
): void {
  // The latest handlers, without resubscribing on every render.
  const handlers = useRef(events)
  useEffect(() => {
    handlers.current = events
  })
  const key = realtime?.key
  const names = Object.keys(events).sort().join(',')

  useEffect(() => {
    if (!key || !channel) return
    let disposed = false
    const bound: [string, Listener][] = names.split(',').filter(Boolean).map((event) => [event, (payload: unknown) => handlers.current[event]?.(payload)])
    let connection: Connection | null = null

    void connect(key, authEndpoint).then((c) => {
      if (disposed) return
      connection = c
      subscribers.set(channel, (subscribers.get(channel) ?? 0) + 1)
      const ch = c.private(channel)
      for (const [event, listener] of bound) {
        if (event === 'notification') ch.notification(listener)
        else ch.listen(event, listener)
      }
    })

    return () => {
      disposed = true
      if (!connection) return
      const ch = connection.private(channel)
      for (const [event, listener] of bound) {
        if (event === 'notification') ch.stopListeningForNotification(listener)
        else ch.stopListening(event, listener)
      }
      const left = (subscribers.get(channel) ?? 1) - 1
      if (left <= 0) {
        subscribers.delete(channel)
        connection.leave(channel)
      } else {
        subscribers.set(channel, left)
      }
    }
  }, [key, authEndpoint, channel, names])
}
