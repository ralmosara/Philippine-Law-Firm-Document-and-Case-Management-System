import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiClient, get } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { useSession } from '@/features/auth/session'
import type { MatterRef, Paginated } from '@/shared/api/types'
import { useRealtime } from '@/features/notifications/api'
import { usePrivateChannel } from '@/shared/realtime/echo'

export interface ChatMessage {
  id: number
  body: string
  mine: boolean
  from_client: boolean
  sender_name: string | null
  attachment: { id: number; name: string; size_bytes: number } | null
  created_at: string
}

export interface MessageThread {
  id: number
  subject: string
  last_message_at: string | null
  unread_count?: number
  matter?: MatterRef
  client?: { id: number; name: string }
  preview?: string | null
  messages?: ChatMessage[]
}

/** Multipart body for a message with an optional attachment. */
export function messageForm(fields: Record<string, string | number | undefined>, file?: File | null): FormData {
  const body = new FormData()
  for (const [key, value] of Object.entries(fields)) if (value !== undefined) body.append(key, String(value))
  if (file) body.append('file', file)
  return body
}

export function useThreads(params: { matter_id?: number; page?: number }) {
  const realtime = useLiveInbox()
  return useQuery({
    queryKey: ['messages', 'threads', params],
    queryFn: () => get<Paginated<MessageThread>>('/v1/message-threads', { ...params }),
    placeholderData: keepPreviousData,
    // Live over the WebSocket when there is one; otherwise poll.
    refetchInterval: realtime ? 5 * 60_000 : 30_000,
  })
}

/** Any thread in the firm changed: refresh the inbox and unread counts (several screens may listen; refreshes in flight are shared, not restarted). */
function useLiveInbox() {
  const realtime = useRealtime()
  const queryClient = useQueryClient()
  const firmId = useSession().data?.firm.id ?? null
  usePrivateChannel(realtime, '/broadcasting/auth', firmId ? `firm.${firmId}.messages` : null, {
    '.thread.updated': () => {
      void queryClient.invalidateQueries({ queryKey: ['messages', 'threads'] }, { cancelRefetch: false })
      void queryClient.invalidateQueries({ queryKey: ['messages', 'unread'] }, { cancelRefetch: false })
    },
  })
  return realtime
}

/** An open conversation: new messages arrive live (or, without a WebSocket, every 15 seconds). */
export function useThread(id: number | null) {
  const realtime = useRealtime()
  const queryClient = useQueryClient()
  usePrivateChannel(realtime, '/broadcasting/auth', id ? `message-thread.${id}` : null, {
    '.thread.updated': (payload) => {
      // Our own read receipts change nothing on screen.
      if ((payload as { change?: string }).change === 'message') void queryClient.invalidateQueries({ queryKey: ['messages', 'thread', id] }, { cancelRefetch: false })
    },
  })
  return useQuery({
    queryKey: ['messages', 'thread', id],
    queryFn: () => get<MessageThread>(`/v1/message-threads/${id}`),
    enabled: id !== null,
    refetchInterval: realtime ? 2 * 60_000 : 15_000,
  })
}

export function useUnreadMessages() {
  const realtime = useLiveInbox()
  return useQuery({
    queryKey: ['messages', 'unread'],
    queryFn: async () => (await get<{ count: number }>('/v1/message-threads/unread-count')).count,
    refetchInterval: realtime ? 5 * 60_000 : 60_000,
  })
}

export function useStartThread() {
  return useApiMutation(
    (input: { matter_id: number; subject: string; body: string; file?: File | null }) =>
      apiClient.post<MessageThread>('/v1/message-threads', messageForm({ matter_id: input.matter_id, subject: input.subject, body: input.body }, input.file)).then((r) => r.data),
    { invalidate: [['messages']], success: 'Message sent', toastErrors: false },
  )
}

export function useReply(threadId: number) {
  return useApiMutation(
    (input: { body: string; file?: File | null }) => apiClient.post<MessageThread>(`/v1/message-threads/${threadId}/messages`, messageForm({ body: input.body }, input.file)).then((r) => r.data),
    { invalidate: [['messages']], toastErrors: false },
  )
}
