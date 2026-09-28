import { useQuery } from '@tanstack/react-query'
import { get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export interface Prospect {
  id: number
  name: string
  organization: string | null
  client_type: 'individual' | 'corporate'
  email: string | null
  phone: string | null
  source: string
  source_label: string
  referred_by: string | null
  case_type: string | null
  description: string | null
  opposing_parties: string[]
  estimated_value_cents: number | null
  stage: string
  stage_label: string
  owner_id: number | null
  owner: string | null
  next_step: string | null
  next_step_on: string | null
  follow_up_due: boolean
  proposal_sent_on: string | null
  engagement_sent_on: string | null
  engagement_signed_on: string | null
  lost_reason: string | null
  intake_request_id: number | null
  matter: { id: number; reference: string; title: string } | null
  created_at: string | null
}

export interface ProspectDetail extends Prospect {
  conflicts: { id: number; search_term: string; status: string; match_count: number }[]
  events: { id: number; type: string; from_stage: string | null; to_stage: string | null; to_label: string | null; body: string | null; by: string | null; at: string | null }[]
}

export type ProspectInput = Pick<Prospect, 'name' | 'organization' | 'client_type' | 'email' | 'phone' | 'source' | 'referred_by' | 'case_type' | 'description' | 'opposing_parties' | 'estimated_value_cents' | 'owner_id' | 'next_step' | 'next_step_on'>

interface Summary {
  label: string
  total: number
  open: number
  won: number
  lost: number
  win_rate: number | null
  won_value_cents: number
  avg_days_to_win: number | null
}

export interface ProspectReport {
  from: string
  to: string
  overall: Summary
  by_source: (Summary & { source: string })[]
  by_owner: Summary[]
  lost_reasons: { reason: string; count: number }[]
  pipeline: { stage: string; label: string; count: number; value_cents: number }[]
}

export const OPEN_STAGES = ['lead', 'consultation', 'proposal', 'engagement_sent'] as const

export function useProspects(params: { closed?: boolean; search?: string }) {
  return useQuery({
    queryKey: ['prospects', params],
    queryFn: () => get<{ stages: Record<string, string>; sources: Record<string, string>; data: Prospect[] }>('/v1/prospects', { closed: params.closed ? 1 : undefined, search: params.search || undefined }),
  })
}

export function useProspect(id: number | null) {
  return useQuery({ queryKey: ['prospects', 'one', id], queryFn: () => get<ProspectDetail>(`/v1/prospects/${id}`), enabled: id !== null })
}

export function useProspectReport(from: string, to: string, enabled: boolean) {
  return useQuery({ queryKey: ['prospects', 'report', from, to], queryFn: () => get<ProspectReport>('/v1/prospects/report', { from: from || undefined, to: to || undefined }), enabled })
}

export function useSaveProspect(id?: number) {
  return useApiMutation((input: ProspectInput) => (id ? put<Prospect>(`/v1/prospects/${id}`, input) : post<Prospect>('/v1/prospects', input)), {
    invalidate: [['prospects']],
    success: id ? 'Saved' : 'Prospect added and conflict-checked',
    toastErrors: false,
  })
}

export function useMoveProspect(id: number) {
  return useApiMutation((input: { stage: string; note?: string | null; lost_reason?: string | null }) => post<Prospect>(`/v1/prospects/${id}/stage`, input), {
    invalidate: [['prospects']],
    success: (p) => `Now: ${p.stage_label}`,
    toastErrors: false,
  })
}

export function useProspectNote(id: number) {
  return useApiMutation((input: { type: string; body: string }) => post(`/v1/prospects/${id}/notes`, input), { invalidate: [['prospects', 'one', id]], success: 'Noted' })
}

export function useConvertProspect(id: number) {
  return useApiMutation((input: { title?: string | null; responsible_lawyer_id?: number | null }) => post<{ matter_id: number; reference: string }>(`/v1/prospects/${id}/convert`, input), {
    invalidate: [['prospects'], ['matters'], ['clients']],
    success: (r) => `Engaged: matter ${r.reference} opened`,
    toastErrors: false,
  })
}

export function useTrackIntake() {
  return useApiMutation((intakeId: number) => post<Prospect>(`/v1/intake-requests/${intakeId}/prospect`), { invalidate: [['prospects']], success: 'Added to the business development pipeline' })
}
