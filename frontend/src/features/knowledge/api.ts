import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export interface KnowledgeItem {
  id: number
  kind: string
  kind_label: string
  title: string
  citation: string | null
  doctrine: string | null
  practice_area: string | null
  tags: string[]
  created_by: string | null
  created_by_id: number | null
  updated_at: string | null
  snippet?: string | null
  body?: string | null
  updated_by?: string | null
  source_document_id?: number | null
  source_matter?: { id: number; reference: string; title: string } | null
}

export type KnowledgeInput = Pick<KnowledgeItem, 'kind' | 'title' | 'citation' | 'doctrine' | 'practice_area' | 'tags'> & { body: string | null }

export function useKnowledge(params: { search?: string; kind?: string; tag?: string; practice_area?: string }) {
  return useQuery({
    queryKey: ['knowledge', params],
    queryFn: () => get<{ kinds: Record<string, string>; tags: string[]; data: KnowledgeItem[] }>('/v1/knowledge', {
      search: params.search || undefined, kind: params.kind || undefined, tag: params.tag || undefined, practice_area: params.practice_area || undefined,
    }),
    placeholderData: keepPreviousData,
  })
}

export function useKnowledgeItem(id: number | null) {
  return useQuery({ queryKey: ['knowledge', 'item', id], queryFn: () => get<KnowledgeItem>(`/v1/knowledge/${id}`), enabled: id !== null })
}

export function useSaveKnowledge(id?: number) {
  return useApiMutation((input: KnowledgeInput) => (id ? put<KnowledgeItem>(`/v1/knowledge/${id}`, input) : post<KnowledgeItem>('/v1/knowledge', input)), {
    invalidate: [['knowledge']],
    success: 'Saved to the knowledge bank',
    toastErrors: false,
  })
}

export function useDeleteKnowledge() {
  return useApiMutation((id: number) => del(`/v1/knowledge/${id}`), { invalidate: [['knowledge']], success: 'Removed from the knowledge bank' })
}

export function useSaveDocumentToKnowledge(documentId: number) {
  return useApiMutation((input: { kind: string; title: string; tags: string[]; notes: string | null }) => post<KnowledgeItem>(`/v1/documents/${documentId}/knowledge`, input), {
    invalidate: [['knowledge']],
    success: 'Kept in the knowledge bank',
    toastErrors: false,
  })
}
