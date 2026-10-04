import { useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export interface EFilingCheck {
  level: 'ok' | 'warning' | 'error'
  message: string
}

export interface EFiling {
  id: number
  title: string
  status: 'prepared' | 'filed' | 'acknowledged'
  items: { kind: string; id: number; label: string; name: string; description?: string | null; first_page: number; pages: number }[]
  page_count: number
  size_bytes: number
  checks: EFilingCheck[]
  package: { id: number; name: string } | null
  filed_at: string | null
  filed_via: string | null
  filed_via_label: string | null
  filed_to: string | null
  filing_reference: string | null
  filed_by: string | null
  acknowledged_at: string | null
  acknowledgment: string | null
  acknowledgment_file: { id: number; name: string } | null
  deadline: { id: number; title: string; due_date: string } | null
  created_by: string | null
  created_at: string | null
}

export interface EFilingSources {
  documents: { id: number; title: string; status: string; updated_at: string }[]
  files: { id: number; original_name: string; description: string | null; mime_type: string; size_bytes: number; created_at: string }[]
  deadlines: { id: number; title: string; due_date: string; kind: string }[]
}

export function useEFilings(matterId: number) {
  return useQuery({
    queryKey: ['e-filings', matterId],
    queryFn: () => get<{ via: Record<string, string>; max_mb: number; data: EFiling[] }>(`/v1/matters/${matterId}/e-filings`),
  })
}

export function useEFilingSources(matterId: number, enabled: boolean) {
  return useQuery({ queryKey: ['e-filings', matterId, 'sources'], queryFn: () => get<EFilingSources>(`/v1/matters/${matterId}/e-filings/sources`), enabled })
}

export interface PrepareInput {
  document_id: number | null
  main_file_id: number | null
  annexes: { file_id: number; description: string | null }[]
  annex_style: 'letters' | 'numbers'
  separators: boolean
  title: string | null
}

export function usePrepareEFiling(matterId: number) {
  return useApiMutation((input: PrepareInput) => post<EFiling>(`/v1/matters/${matterId}/e-filings`, input), {
    invalidate: [['e-filings', matterId], ['files', matterId]],
    success: (f) => `Package ready: ${f.page_count} pages`,
    toastErrors: false,
  })
}

export function useRecordFiling(matterId: number) {
  return useApiMutation(
    ({ id, ...input }: { id: number; filed_at: string; filed_via: string; filed_to: string | null; filing_reference: string | null; deadline_id: number | null }) => post<EFiling>(`/v1/e-filings/${id}/filed`, input),
    { invalidate: [['e-filings', matterId], ['deadlines'], ['tasks']], success: 'Filing recorded', toastErrors: false },
  )
}

export function useRecordAcknowledgment(matterId: number) {
  return useApiMutation(
    ({ id, ...input }: { id: number; acknowledged_at: string; acknowledgment: string | null; acknowledgment_file_id: number | null }) => post<EFiling>(`/v1/e-filings/${id}/acknowledged`, input),
    { invalidate: [['e-filings', matterId]], success: 'Acknowledgment recorded', toastErrors: false },
  )
}
