import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { apiClient, get } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { MatterRef, Paginated } from '@/shared/api/types'

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
  return useQuery({
    queryKey: ['messages', 'threads', params],
    queryFn: () => get<Paginated<MessageThread>>('/v1/message-threads', { ...params }),
    placeholderData: keepPreviousData,
    refetchInterval: 30_000,
  })
}

/** Open conversations refresh every 15 seconds. */
export function useThread(id: number | null) {
  return useQuery({
    queryKey: ['messages', 'thread', id],
    queryFn: () => get<MessageThread>(`/v1/message-threads/${id}`),
    enabled: id !== null,
    refetchInterval: 15_000,
  })
}

export function useUnreadMessages() {
  return useQuery({
    queryKey: ['messages', 'unread'],
    queryFn: async () => (await get<{ count: number }>('/v1/message-threads/unread-count')).count,
    refetchInterval: 60_000,
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
