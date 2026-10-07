import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { ConflictMatch, Paginated } from '@/shared/api/types'
import type { Locale } from '@/shared/lib/i18n'
import { longDate } from '@/shared/lib/format'

export interface PublicIntakePage {
  firm: { name: string; address: string | null; phone: string | null; email: string | null }
  message: string | null
  case_types: { value: string; label: string }[]
  /** The firm's own questions, by type of case. */
  questions?: Record<string, { key: string; label: string; type: 'text' | 'textarea' | 'date' | 'number'; required: boolean; hint: string | null }[]>
  privacy_notice?: string
}

export interface IntakeForm {
  name: string
  email: string
  phone: string
  client_type: 'individual' | 'corporate'
  case_type: string
  description: string
  /** When the problem arose (YYYY-MM-DD), so a lawyer can check prescription early. */
  incident_on: string | null
  opposing_parties: string[]
  preferred_times: string[]
  /** Answers to the firm's questions for the chosen type of case, by question key. */
  answers: Record<string, string>
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
  incident_on: string | null
  answers?: { label: string; answer: string }[]
  locale: 'en' | 'fil'
  /** The lawyer's prescription screening, while the request is open. */
  prescription: { period_key: string; label: string; basis: string; last_day: string; file_by: string; days_left: number; state: 'running' | 'soon' | 'urgent' | 'prescribed' } | null
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

/** The X-Locale header follows the chosen language; the locale is in the key so a switch refetches. */
export const usePublicIntake = (slug: string, locale: Locale) =>
  useQuery({ queryKey: ['public-intake', slug, locale], queryFn: () => get<PublicIntakePage>(`/public/intake/${slug}`), retry: false, placeholderData: keepPreviousData })

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

export function useScreenPrescription(id: number) {
  return useApiMutation((input: { period_key: string | null; incident_on?: string | null }) => put<IntakeDetail>(`/v1/intake-requests/${id}/prescription`, input), {
    invalidate: [['intake']],
    success: (r) => (r.prescription ? `Last day to file: ${longDate(r.prescription.last_day)}` : 'Saved'),
    toastErrors: false,
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
