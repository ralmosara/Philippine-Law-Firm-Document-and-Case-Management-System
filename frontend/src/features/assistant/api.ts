import { useQuery, useQueryClient } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { useSession } from '@/features/auth/session'
import { useRealtime } from '@/features/notifications/api'
import { usePrivateChannel } from '@/shared/realtime/echo'

export interface AiMessage {
  id: number
  role: 'user' | 'assistant'
  content: string | null
  status: 'pending' | 'complete' | 'failed'
  error: string | null
  created_at: string
}

export interface AiConversation {
  id: number
  matter_id: number
  title: string
  messages: AiMessage[]
}

export const useAssistantStatus = () =>
  useQuery({ queryKey: ['assistant', 'status'], queryFn: () => get<{ available: boolean; reason: string | null }>('/v1/assistant/status'), staleTime: 5 * 60_000 })

export const useAssistantConversations = (matterId: number, enabled: boolean) =>
  useQuery({
    queryKey: ['assistant', 'matter', matterId],
    queryFn: async () => (await get<{ data: { id: number; title: string; updated_at: string }[] }>(`/v1/matters/${matterId}/assistant`)).data,
    enabled,
  })

/**
 * While an answer is being written: it arrives live over the WebSocket
 * (checked every 10 seconds as a fallback), or without one, every 2 seconds.
 */
export function useAssistantConversation(id: number | null) {
  const realtime = useRealtime()
  const queryClient = useQueryClient()
  const userId = useSession().data?.user.id ?? null
  usePrivateChannel(realtime, '/broadcasting/auth', id && userId ? `App.Models.User.${userId}` : null, {
    '.assistant.answered': (payload) => {
      if ((payload as { conversation_id?: number }).conversation_id === id) void queryClient.invalidateQueries({ queryKey: ['assistant', 'conversation', id] }, { cancelRefetch: false })
    },
  })
  return useQuery({
    queryKey: ['assistant', 'conversation', id],
    queryFn: () => get<AiConversation>(`/v1/assistant/conversations/${id}`),
    enabled: id !== null,
    refetchInterval: (query) => (query.state.data?.messages.some((m) => m.status === 'pending') ? (realtime ? 10_000 : 2000) : false),
  })
}

export function useAsk(matterId: number) {
  return useApiMutation((input: { question: string; conversation_id?: number }) => post<AiConversation>(`/v1/matters/${matterId}/assistant`, input), {
    invalidate: [['assistant', 'matter', matterId]],
    toastErrors: true,
  })
}

export function useRetryAnswer() {
  return useApiMutation((messageId: number) => post<AiConversation>(`/v1/assistant/messages/${messageId}/retry`), { invalidate: [['assistant', 'conversation']] })
}

export function useSaveAnswerAsDocument() {
  return useApiMutation(({ messageId, title }: { messageId: number; title: string }) => post<{ document_id: number }>(`/v1/assistant/messages/${messageId}/document`, { title }), {
    invalidate: [['documents']],
    success: 'Saved as a draft document',
    toastErrors: false,
  })
}
