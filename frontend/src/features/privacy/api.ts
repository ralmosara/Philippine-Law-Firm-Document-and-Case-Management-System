import { useQuery } from '@tanstack/react-query'
import { get, patch, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type DsrType = 'access' | 'correction' | 'erasure' | 'objection' | 'portability'
export type DsrStatus = 'open' | 'completed' | 'denied'

export const DSR_TYPES: Record<DsrType, string> = {
  access: 'See a copy of my data',
  correction: 'Correct my data',
  erasure: 'Delete my data',
  objection: 'Stop a use of my data',
  portability: 'Get my data in a portable file',
}

export interface PrivacySummary {
  open_requests: number
  overdue_requests: number
  incidents_awaiting_npc: number
  due_for_disposal: number
  clients_without_consent: number
}

export interface PrivacySettings {
  dpo_name: string | null
  dpo_email: string | null
  privacy_notice: string | null
  default_notice: string
  privacy_notice_fil: string | null
  default_notice_fil: string
  notice_text: string
  privacy_notice_version: number
  privacy_notice_updated_at: string | null
  retention_years: number
}

export interface DataSubjectRequest {
  id: number
  type: DsrType
  type_label: string
  requester_name: string
  requester_email: string | null
  client: { id: number; name: string; anonymized: boolean } | null
  details: string | null
  source: 'portal' | 'staff'
  status: DsrStatus
  due_on: string
  is_overdue: boolean
  resolution: string | null
  handled_by: string | null
  resolved_at: string | null
  created_at: string
}

export interface PrivacyIncident {
  id: number
  title: string
  description: string
  discovered_at: string
  occurred_at: string | null
  affected_count: number | null
  data_involved: string | null
  sensitive: boolean
  notifiable: boolean
  npc_notified_at: string | null
  subjects_notified_at: string | null
  actions_taken: string | null
  status: 'open' | 'contained' | 'closed'
  notify_by: string
  npc_notification_pending: boolean
  reported_by: string | null
}

export interface RetentionMatter {
  id: number
  reference: string
  title: string
  client: string | null
  closed_at: string
  files: number
}

const invalidate = [['privacy']]

export const usePrivacySummary = () => useQuery({ queryKey: ['privacy', 'summary'], queryFn: () => get<PrivacySummary>('/v1/privacy/summary') })
export const usePrivacySettings = () => useQuery({ queryKey: ['privacy', 'settings'], queryFn: () => get<PrivacySettings>('/v1/privacy/settings') })
export const useDataRequests = () => useQuery({ queryKey: ['privacy', 'requests'], queryFn: () => get<DataSubjectRequest[]>('/v1/privacy/requests') })
export const useIncidents = () => useQuery({ queryKey: ['privacy', 'incidents'], queryFn: () => get<PrivacyIncident[]>('/v1/privacy/incidents') })
export const useRetention = () => useQuery({ queryKey: ['privacy', 'retention'], queryFn: () => get<{ retention_years: number; matters: RetentionMatter[] }>('/v1/privacy/retention') })

export function useSavePrivacySettings() {
  return useApiMutation((input: { dpo_name: string | null; dpo_email: string | null; privacy_notice: string | null; privacy_notice_fil: string | null; retention_years: number }) => put<PrivacySettings>('/v1/privacy/settings', input), {
    invalidate,
    success: (s) => `Saved. Privacy notice is at version ${s.privacy_notice_version}.`,
    toastErrors: false,
  })
}

export function useRecordRequest() {
  return useApiMutation((input: { requester_name: string; requester_email?: string; type: DsrType; details?: string; client_id?: number }) => post<DataSubjectRequest>('/v1/privacy/requests', input), {
    invalidate,
    success: 'Request recorded',
    toastErrors: false,
  })
}

export function useResolveRequest() {
  return useApiMutation(({ id, ...input }: { id: number; status: 'completed' | 'denied'; resolution: string }) => post<DataSubjectRequest>(`/v1/privacy/requests/${id}/resolve`, input), {
    invalidate,
    success: 'Request closed',
    toastErrors: false,
  })
}

export function useSaveIncident(id?: number) {
  return useApiMutation(
    (input: Partial<Omit<PrivacyIncident, 'id' | 'notify_by' | 'npc_notification_pending' | 'reported_by'>>) =>
      id ? patch<PrivacyIncident>(`/v1/privacy/incidents/${id}`, input) : post<PrivacyIncident>('/v1/privacy/incidents', input),
    { invalidate, success: id ? 'Incident updated' : 'Incident logged', toastErrors: false },
  )
}

export function useDisposeMatter() {
  return useApiMutation((id: number) => post<{ files: number; threads: number; documents: number }>(`/v1/matters/${id}/dispose`, { confirm: true }), {
    invalidate: [...invalidate, ['matters']],
    success: (r) => `Disposed of: ${r.files} files, ${r.threads} message threads, ${r.documents} drafts erased`,
  })
}

export function useAnonymizeClient() {
  return useApiMutation((id: number) => post<{ id: number; name: string }>(`/v1/clients/${id}/anonymize`, { confirm: true }), {
    invalidate: [...invalidate, ['clients']],
    success: 'Client anonymized',
    toastErrors: false,
  })
}
