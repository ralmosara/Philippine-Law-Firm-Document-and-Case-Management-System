import { useQuery, useQueryClient } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { useToast } from '@/shared/ui/Toast'
import { usePrivateChannel } from '@/shared/realtime/echo'

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
  disbursement: [['disbursements']],
  prospect: [['prospects']],
  document_uploaded: [['document-requests'], ['files']],
  budget: [['budget']],
  prescription: [['prescriptions']],
  feedback: [['feedback']],
  time: [['time-entries']],
  credentials: [['session']],
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

  usePrivateChannel(realtime, '/broadcasting/auth', `App.Models.User.${userId}`, {
    notification: (payload) => {
      const n = payload as AppNotification
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
      for (const queryKey of AFFECTS[n.kind] ?? []) void queryClient.invalidateQueries({ queryKey })
      toast.info(n.title)
    },
  })
}

/** The WebSocket key, from the bell's (cached) query; null when the server offers none. */
export function useRealtime() {
  return useNotifications().data?.realtime ?? null
}
