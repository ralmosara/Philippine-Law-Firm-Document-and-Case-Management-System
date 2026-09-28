import { useQuery } from '@tanstack/react-query'
import { del, get, patch, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export interface CorporateProfile {
  id: number
  client_id: number
  client_name: string | null
  client_tin: string | null
  sec_registration_no: string | null
  incorporated_on: string | null
  fiscal_year_end: string
  annual_meeting_date: string | null
  principal_office: string | null
  corporate_secretary: string | null
  responsible_lawyer_id: number | null
  responsible_lawyer: string | null
  notes: string | null
}

export type ObligationKind = 'annual_meeting' | 'gis' | 'afs' | 'annual_itr' | 'custom'

export interface CorporateObligation {
  id: number
  client_id: number
  client_name?: string | null
  kind: ObligationKind
  title: string
  year: number
  due_on: string
  status: 'pending' | 'done' | 'not_applicable'
  is_overdue: boolean
  done_on: string | null
  reference: string | null
  notes: string | null
  completed_by: string | null
}

export interface CorporateOverview {
  year: number
  kinds: Record<ObligationKind, string>
  companies: {
    profile: CorporateProfile
    obligations: CorporateObligation[]
  }[]
  upcoming: CorporateObligation[]
}

export type ProfileInput = Omit<CorporateProfile, 'id' | 'client_id' | 'client_name' | 'client_tin' | 'responsible_lawyer'>

export function useCorporateOverview(year: string) {
  return useQuery({
    queryKey: ['corporate', 'overview', year],
    queryFn: () => get<CorporateOverview>('/v1/corporate', { year }),
  })
}

export function useClientCorporate(clientId: number, enabled = true) {
  return useQuery({
    queryKey: ['corporate', 'client', clientId],
    queryFn: () =>
      get<{
        profile: CorporateProfile | null
        obligations: CorporateObligation[]
      }>(`/v1/clients/${clientId}/corporate`),
    enabled,
  })
}

export function useSaveCorporateProfile(clientId: number) {
  return useApiMutation((input: ProfileInput) => put<CorporateProfile>(`/v1/clients/${clientId}/corporate`, input), {
    invalidate: [['corporate']],
    success: 'Corporate profile saved',
    toastErrors: false,
  })
}

export function useUpdateObligation(id: number) {
  return useApiMutation((input: object) => patch<CorporateObligation>(`/v1/corporate-obligations/${id}`, input), {
    invalidate: [['corporate']],
    success: 'Saved',
    toastErrors: false,
  })
}

export function useAddObligation(clientId: number) {
  return useApiMutation((input: { title: string; due_on: string; notes?: string | null }) => post<CorporateObligation>(`/v1/clients/${clientId}/corporate/obligations`, input), {
    invalidate: [['corporate']],
    success: 'Obligation added',
    toastErrors: false,
  })
}

export function useDeleteObligation() {
  return useApiMutation((id: number) => del(`/v1/corporate-obligations/${id}`), { invalidate: [['corporate']], success: 'Removed' })
}

export function useInstallCorporateTemplates() {
  return useApiMutation(() => post<{ added: number }>('/v1/corporate/templates'), {
    invalidate: [['templates']],
    success: (d) => (d.added ? `${d.added} corporate templates added to Documents` : 'The corporate templates are already installed'),
  })
}

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']

/** "04-15" -> "April 15". */
export function monthDay(value: string | null): string {
  if (!value) return '—'
  const [m = 1, d = 1] = value.split('-').map(Number)
  return `${MONTHS[m - 1]} ${d}`
}
