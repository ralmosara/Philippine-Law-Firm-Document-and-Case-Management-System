import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { apiClient, del, get, patch, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { DocumentStatus, DocumentTemplate, DocumentVersion, LegalDocument, MatterFile, NotarialEntry, Paginated, SignatureRequest } from '@/shared/api/types'

export function useDocuments(params: { matter_id?: number; search?: string; status?: string; page?: number }) {
  return useQuery({
    queryKey: ['documents', 'list', params],
    queryFn: () => get<Paginated<LegalDocument>>('/v1/documents', { ...params }),
    placeholderData: keepPreviousData,
  })
}

export function useDocument(id: number) {
  return useQuery({ queryKey: ['documents', id], queryFn: () => get<LegalDocument>(`/v1/documents/${id}`) })
}

export function useDocumentVersions(id: number) {
  return useQuery({ queryKey: ['documents', id, 'versions'], queryFn: () => get<DocumentVersion[]>(`/v1/documents/${id}/versions`) })
}

export function useCreateDocument() {
  return useApiMutation(
    (input: { matter_id: number; template_id?: number; title?: string; content?: string; fields?: Record<string, string> }) => post<LegalDocument>('/v1/documents', input),
    { invalidate: [['documents']], success: (d) => `Created "${d.title}"`, toastErrors: false },
  )
}

export function useSaveVersion(id: number) {
  return useApiMutation((input: { content: string; change_summary?: string }) => post<DocumentVersion>(`/v1/documents/${id}/versions`, input), {
    invalidate: [['documents']],
    success: (v) => `Saved version ${v.version_number}`,
  })
}

export function useUpdateDocument(id: number) {
  return useApiMutation((input: { title?: string; shared_with_client?: boolean }) => patch<LegalDocument>(`/v1/documents/${id}`, input), {
    invalidate: [['documents']],
    success: 'Document updated',
  })
}

export function useDocumentStatus(id: number) {
  return useApiMutation((status: DocumentStatus) => post<LegalDocument>(`/v1/documents/${id}/status`, { status }), {
    invalidate: [['documents']],
    success: (d) => `Document marked ${d.status.replace('_', ' ')}`,
  })
}

export function useDeleteDocument() {
  return useApiMutation((id: number) => del(`/v1/documents/${id}`), { invalidate: [['documents']], success: 'Document deleted' })
}

export function useTemplates() {
  return useQuery({ queryKey: ['templates'], queryFn: async () => (await get<{ data: DocumentTemplate[] }>('/v1/document-templates')).data })
}

export function useSaveTemplate(id?: number) {
  return useApiMutation(
    (input: { name: string; category?: string | null; body: string }) => (id ? put<DocumentTemplate>(`/v1/document-templates/${id}`, input) : post<DocumentTemplate>('/v1/document-templates', input)),
    { invalidate: [['templates']], success: 'Template saved', toastErrors: false },
  )
}

export function useDeleteTemplate() {
  return useApiMutation((id: number) => del(`/v1/document-templates/${id}`), { invalidate: [['templates']], success: 'Template deleted' })
}

export function useNotarialEntries(params: { search?: string; series_year?: number; page?: number }) {
  return useQuery({
    queryKey: ['notarial', params],
    queryFn: () => get<Paginated<NotarialEntry>>('/v1/notarial-entries', { ...params }),
    placeholderData: keepPreviousData,
  })
}

export function useNextNotarialNumber(enabled: boolean) {
  return useQuery({
    queryKey: ['notarial', 'next'],
    queryFn: () => get<{ doc_number: number; page_number: number; book_number: number; series_year: number }>('/v1/notarial-entries/next'),
    enabled,
  })
}

export function useCreateNotarialEntry() {
  return useApiMutation((input: Record<string, unknown>) => post<NotarialEntry>('/v1/notarial-entries', input), {
    invalidate: [['notarial']],
    success: (e) => `Entered as Doc. No. ${e.doc_number}, Page ${e.page_number}, Book ${e.book_number}, Series of ${e.series_year}`,
    toastErrors: false,
  })
}

export function useMatterFiles(matterId: number) {
  return useQuery({
    queryKey: ['files', matterId],
    queryFn: () => get<MatterFile[]>(`/v1/matters/${matterId}/files`),
    // Text extraction runs in the background; refresh until it finishes.
    refetchInterval: (query) => (query.state.data?.some((f) => f.text_status === 'pending') ? 3000 : false),
  })
}

/** Upload with progress. Validation errors come back as `errors.file`. */
export function useUploadFile(matterId: number) {
  return useApiMutation(
    ({ file, description, shared, onProgress }: { file: File; description?: string; shared: boolean; onProgress?: (percent: number) => void }) => {
      const body = new FormData()
      body.append('file', file)
      if (description) body.append('description', description)
      body.append('shared_with_client', shared ? '1' : '0')
      return apiClient
        .post<MatterFile>(`/v1/matters/${matterId}/files`, body, {
          onUploadProgress: (e) => e.total && onProgress?.(Math.round((e.loaded / e.total) * 100)),
        })
        .then((r) => r.data)
    },
    { invalidate: [['files', matterId]], success: (f) => `Uploaded ${f.name}`, toastErrors: false },
  )
}

export function useUpdateFile() {
  return useApiMutation(({ id, ...input }: { id: number; shared_with_client?: boolean; description?: string | null }) => patch<MatterFile>(`/v1/files/${id}`, input), {
    invalidate: [['files']],
    success: (f) => (f.shared_with_client ? `${f.name} is visible to the client` : `${f.name} is internal only`),
  })
}

export function useDeleteFile() {
  return useApiMutation((id: number) => del(`/v1/files/${id}`), { invalidate: [['files']], success: 'File removed' })
}

export const fileDownloadUrl = (id: number) => `/api/v1/files/${id}/download`

export function useSignatureRequests(documentId: number) {
  return useQuery({ queryKey: ['documents', documentId, 'signatures'], queryFn: () => get<SignatureRequest[]>(`/v1/documents/${documentId}/signature-requests`) })
}

export function useRequestSignature(documentId: number) {
  return useApiMutation((input: { message?: string; expires_in_days: number }) => post<SignatureRequest>(`/v1/documents/${documentId}/signature-requests`, input), {
    invalidate: [['documents']],
    success: (r) => `Signature requested from ${r.client?.name ?? 'the client'}`,
    toastErrors: false,
  })
}

export function useCancelSignatureRequest() {
  return useApiMutation((id: number) => post<SignatureRequest>(`/v1/signature-requests/${id}/cancel`), { invalidate: [['documents']], success: 'Signature request cancelled' })
}

/** Search file names, descriptions and contents (firm-wide, or one matter). */
export function useFileSearch(params: { search: string; matter_id?: number; page?: number }) {
  return useQuery({
    queryKey: ['files', 'search', params],
    queryFn: () => get<Paginated<MatterFile>>('/v1/files', { ...params }),
    enabled: params.search.trim().length >= 2,
    placeholderData: keepPreviousData,
  })
}
