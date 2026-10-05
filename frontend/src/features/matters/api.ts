import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Deadline, FeeArrangement, Matter, MatterParty, MatterStatus, Paginated, StatusEvent } from '@/shared/api/types'

export interface MatterFilters {
  search?: string
  status?: MatterStatus | ''
  active_only?: boolean
  mine?: boolean
  client_id?: number
  page?: number
  per_page?: number
}

export const matterKeys = {
  all: ['matters'] as const,
  list: (filters: MatterFilters) => ['matters', 'list', filters] as const,
  detail: (id: number) => ['matters', id] as const,
}

export function useMatters(filters: MatterFilters) {
  return useQuery({
    queryKey: matterKeys.list(filters),
    queryFn: () => get<Paginated<Matter>>('/v1/matters', { ...filters }),
    placeholderData: keepPreviousData,
  })
}

export function useMatter(id: number) {
  return useQuery({ queryKey: matterKeys.detail(id), queryFn: () => get<Matter>(`/v1/matters/${id}`) })
}

export function useMatterTimeline(id: number) {
  return useQuery({ queryKey: ['matters', id, 'timeline'], queryFn: () => get<StatusEvent[]>(`/v1/matters/${id}/timeline`) })
}

export function useMatterDeadlines(id: number) {
  return useQuery({ queryKey: ['deadlines', 'matter', id], queryFn: () => get<Deadline[]>(`/v1/matters/${id}/deadlines`) })
}

export interface MatterInput {
  client_id: number
  title: string
  case_type: string
  case_number?: string | null
  court?: string | null
  court_branch?: string | null
  judge?: string | null
  description?: string | null
  responsible_lawyer_id?: number | null
  opened_at?: string | null
  parties?: { role: string; name: string; counsel_name?: string | null }[]
  fee_arrangement?: FeeArrangement
  fixed_fee_cents?: number | null
  acceptance_fee_cents?: number | null
  appearance_fee_cents?: number | null
  contingency_basis_points?: number | null
}

export function useSaveMatter(id?: number) {
  return useApiMutation((input: MatterInput) => (id ? put<Matter>(`/v1/matters/${id}`, input) : post<Matter>('/v1/matters', input)), {
    invalidate: [matterKeys.all, ['clients'], ['deadlines']],
    success: id ? 'Matter updated' : (m) => `Matter ${m.reference} opened`,
    toastErrors: false,
  })
}

export function useTransitionMatter(id: number) {
  return useApiMutation((input: { status: MatterStatus; reason?: string; ask_feedback?: boolean }) => post<Matter>(`/v1/matters/${id}/status`, input), {
    invalidate: [matterKeys.all],
    success: (m) => `Status changed to ${m.status_label}`,
  })
}

export function useSaveParty(matterId: number, partyId?: number) {
  return useApiMutation(
    (input: Partial<MatterParty>) => (partyId ? put(`/v1/matters/${matterId}/parties/${partyId}`, input) : post(`/v1/matters/${matterId}/parties`, input)),
    { invalidate: [matterKeys.detail(matterId)], success: 'Party saved', toastErrors: false },
  )
}

export function useDeleteParty(matterId: number) {
  return useApiMutation((partyId: number) => del(`/v1/matters/${matterId}/parties/${partyId}`), {
    invalidate: [matterKeys.detail(matterId)],
    success: 'Party removed',
  })
}
