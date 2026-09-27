import { useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

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

/** Polls every 2 seconds while an answer is being written. */
export const useAssistantConversation = (id: number | null) =>
  useQuery({
    queryKey: ['assistant', 'conversation', id],
    queryFn: () => get<AiConversation>(`/v1/assistant/conversations/${id}`),
    enabled: id !== null,
    refetchInterval: (query) => (query.state.data?.messages.some((m) => m.status === 'pending') ? 2000 : false),
  })

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
