import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { ConflictMatch, Paginated } from '@/shared/api/types'

export interface PublicIntakePage {
  firm: { name: string; address: string | null; phone: string | null; email: string | null }
  message: string | null
  case_types: string[]
  privacy_notice?: string
}

export interface IntakeForm {
  name: string
  email: string
  phone: string
  client_type: 'individual' | 'corporate'
  case_type: string
  description: string
  opposing_parties: string[]
  preferred_times: string[]
  consent: boolean
  website: string
}

export interface IntakeRequest {
  id: number
  status: 'new' | 'scheduled' | 'accepted' | 'declined'
  name: string
  email: string
  case_type: string
  conflict_status: 'clear' | 'flagged'
  consultation_at: string | null
  assigned_lawyer: { id: number; name: string } | null
  created_at: string
}

export interface IntakeDetail extends IntakeRequest {
  phone: string | null
  client_type: string
  description: string
  opposing_parties: string[]
  preferred_times: string[]
  consent_at: string
  internal_notes: string | null
  client_id: number | null
  matter_id: number | null
  conflict_checks: { id: number; search_term: string; status: string; match_count: number; matches: ConflictMatch[] }[]
}

export const usePublicIntake = (slug: string) =>
  useQuery({ queryKey: ['public-intake', slug], queryFn: () => get<PublicIntakePage>(`/public/intake/${slug}`), retry: false })

export function useSubmitIntake(slug: string) {
  return useMutation({ mutationFn: (form: IntakeForm) => post<{ message: string }>(`/public/intake/${slug}`, form) })
}

export function useIntakeRequests(params: { status?: string; page?: number }) {
  return useQuery({
    queryKey: ['intake', 'list', params],
    queryFn: () => get<Paginated<IntakeRequest> & { open_count: number }>('/v1/intake-requests', { ...params }),
    placeholderData: keepPreviousData,
  })
}

/** For the navigation badge. */
export function useOpenIntakeCount(enabled: boolean) {
  return useQuery({
    queryKey: ['intake', 'open-count'],
    queryFn: async () => (await get<{ open_count: number }>('/v1/intake-requests', { per_page: 1 })).open_count,
    enabled,
    refetchInterval: 120_000,
  })
}

export const useIntakeRequest = (id: number) => useQuery({ queryKey: ['intake', id], queryFn: () => get<IntakeDetail>(`/v1/intake-requests/${id}`) })

export function useIntakeAction(id: number) {
  const options = { invalidate: [['intake']], toastErrors: false }
  return {
    schedule: useApiMutation((input: { consultation_at: string; assigned_lawyer_id: number }) => post<IntakeDetail>(`/v1/intake-requests/${id}/schedule`, input), { ...options, success: 'Consultation scheduled; the applicant was emailed' }),
    decline: useApiMutation((input: { internal_notes?: string; notify: boolean }) => post<IntakeDetail>(`/v1/intake-requests/${id}/decline`, input), { ...options, success: 'Request declined' }),
    accept: useApiMutation((input: { title?: string; responsible_lawyer_id?: number }) => post<{ matter_id: number }>(`/v1/intake-requests/${id}/accept`, input), { invalidate: [['intake'], ['matters'], ['clients']], success: 'Client and matter created', toastErrors: false }),
  }
}
