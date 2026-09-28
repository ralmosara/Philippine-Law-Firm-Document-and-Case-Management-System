import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { apiClient, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { useToast } from '@/shared/ui/Toast'

export interface AppNotification {
  id: string
  kind: string
  title: string
  body?: string | null
  url: string
  read_at: string | null
  created_at: string
}

interface NotificationList {
  unread: number
  data: AppNotification[]
  realtime: { key: string } | null
}

/** Queries a notification of each kind makes stale. */
const AFFECTS: Record<string, string[][]> = {
  message: [['messages'], ['threads']],
  intake: [['intake']],
  deadline: [['deadlines'], ['tasks']],
  deadline_missed: [['deadlines'], ['tasks']],
  assigned: [['deadlines'], ['tasks'], ['matters']],
  payment: [['invoices'], ['collections']],
  signature: [['documents']],
  signature_declined: [['documents']],
  privacy: [['privacy']],
  tax: [['tax']],
  corporate: [['corporate']],
  email: [['matter-emails']],
  document_uploaded: [['document-requests'], ['files']],
}

export function useNotifications() {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: () => get<NotificationList>('/v1/notifications'),
    // Live updates arrive over the WebSocket; without one, poll.
    refetchInterval: (query) => (query.state.data?.realtime ? 5 * 60_000 : 60_000),
  })
}

export function useMarkRead() {
  return useApiMutation((id: string) => post(`/v1/notifications/${id}/read`), { invalidate: [['notifications']], toastErrors: false })
}

export function useMarkAllRead() {
  return useApiMutation(() => post('/v1/notifications/read-all'), { invalidate: [['notifications']] })
}

/**
 * Listens on the user's private channel (Laravel Reverb, same origin /app)
 * when the server offers one: a new notification refreshes the bell and the
 * lists it affects, and shows a snackbar.
 */
export function useLiveNotifications(userId: number, realtime: { key: string } | null | undefined) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const key = realtime?.key

  useEffect(() => {
    if (!key) return
    let disposed = false
    let leave: (() => void) | undefined

    void (async () => {
      const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
      if (disposed) return
      const secure = window.location.protocol === 'https:'
      const port = Number(window.location.port) || (secure ? 443 : 80)
      const echo = new Echo({
        broadcaster: 'reverb',
        key,
        Pusher,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        // Authorize through the API client, so Sanctum's cookie and XSRF header go along.
        authorizer: (channel: { name: string }) => ({
          authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
            apiClient.post<{ auth: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
              .then((response) => callback(null, response.data))
              .catch((error: Error) => callback(error, null))
          },
        }),
      })

      const channel = `App.Models.User.${userId}`
      echo.private(channel).notification((n: AppNotification) => {
        void queryClient.invalidateQueries({ queryKey: ['notifications'] })
        for (const queryKey of AFFECTS[n.kind] ?? []) void queryClient.invalidateQueries({ queryKey })
        toast.info(n.title)
      })
      leave = () => {
        echo.leave(channel)
        echo.disconnect()
      }
    })()

    return () => {
      disposed = true
      leave?.()
    }
  }, [key, userId, queryClient, toast])
}
