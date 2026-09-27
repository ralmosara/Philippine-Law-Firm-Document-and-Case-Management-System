import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { ConflictCheck, McleCompliancePeriod, McleCredit, McleStatus, Paginated } from '@/shared/api/types'

export function useConflictChecks(page: number) {
  return useQuery({
    queryKey: ['conflict-checks', page],
    queryFn: () => get<Paginated<ConflictCheck>>('/v1/conflict-checks', { page }),
    placeholderData: keepPreviousData,
  })
}

export function useRunConflictCheck() {
  return useApiMutation((name: string) => post<ConflictCheck>('/v1/conflict-checks', { name }), { invalidate: [['conflict-checks']] })
}

export function useResolveConflict(id: number) {
  return useApiMutation((input: { status: 'waived' | 'declined'; notes: string }) => post<ConflictCheck>(`/v1/conflict-checks/${id}/resolve`, input), {
    invalidate: [['conflict-checks']],
    success: 'Resolution recorded',
  })
}

export function useMclePeriods() {
  return useQuery({
    queryKey: ['mcle', 'periods'],
    queryFn: () => get<{ data: McleCompliancePeriod[]; current_id: number | null }>('/v1/mcle/periods'),
    staleTime: 10 * 60_000,
  })
}

export function useMcleStatus(periodId?: number) {
  return useQuery({
    queryKey: ['mcle', 'status', periodId ?? 'current'],
    queryFn: () => get<{ status: McleStatus | null; credits: McleCredit[] }>('/v1/mcle/status', { period_id: periodId }),
  })
}

export function useFirmMcle(periodId?: number, enabled = true) {
  return useQuery({
    queryKey: ['mcle', 'firm', periodId ?? 'current'],
    queryFn: () => get<{ period: McleCompliancePeriod | null; data: (McleStatus & { user_id: number; name: string; roll_number: string | null })[] }>('/v1/mcle/firm', { period_id: periodId }),
    enabled,
  })
}

export function useAddMcleCredit() {
  return useApiMutation((input: Record<string, unknown>) => post<McleCredit>('/v1/mcle/credits', input), {
    invalidate: [['mcle']],
    success: 'Credit added',
    toastErrors: false,
  })
}

export function useDeleteMcleCredit() {
  return useApiMutation((id: number) => del(`/v1/mcle/credits/${id}`), { invalidate: [['mcle']], success: 'Credit removed' })
}
