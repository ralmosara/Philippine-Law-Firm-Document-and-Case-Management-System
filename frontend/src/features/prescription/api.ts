import { useQuery } from '@tanstack/react-query'
import { del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { longDate } from '@/shared/lib/format'

export interface PrescriptionPeriod {
  key: string
  group: string
  label: string
  years: number
  months: number
  basis: string
  runs_from: string
  interruptible: boolean
}

export type PrescriptionState = 'running' | 'soon' | 'urgent' | 'prescribed' | 'filed'

export interface Prescription {
  id: number
  matter_id: number
  period_key: string | null
  label: string
  years: number
  months: number
  basis: string | null
  runs_from_hint: string | null
  interruptible: boolean
  accrued_on: string
  runs_from: string
  last_day: string
  file_by: string
  days_left: number | null
  state: PrescriptionState
  interruptions: { date: string; kind: string; kind_label: string; note: string | null }[]
  filed_on: string | null
  notes: string | null
  matter?: { id: number; reference: string; title: string; lawyer: string | null }
}

export interface PrescriptionInput {
  period_key: string | null
  label: string | null
  years: number | null
  months: number | null
  basis: string | null
  accrued_on: string
  notes: string | null
}

const invalidate = [['prescriptions']]

export function usePrescriptionPeriods(enabled = true) {
  return useQuery({
    queryKey: ['prescription-periods'],
    queryFn: () => get<{ groups: { group: string; label: string; periods: PrescriptionPeriod[] }[]; interruptions: { value: string; label: string }[] }>('/v1/prescription-periods'),
    staleTime: Infinity,
    enabled,
  })
}

export function useMatterPrescriptions(matterId: number) {
  return useQuery({ queryKey: ['prescriptions', 'matter', matterId], queryFn: async () => (await get<{ data: Prescription[] }>(`/v1/matters/${matterId}/prescriptions`)).data })
}

export function useFirmPrescriptions(within: number) {
  return useQuery({ queryKey: ['prescriptions', 'firm', within], queryFn: async () => (await get<{ data: Prescription[] }>('/v1/prescriptions', { within })).data })
}

export function usePrescriptionPreview(from: string, years: number, months: number) {
  return useQuery({
    queryKey: ['prescription-preview', from, years, months],
    queryFn: () => post<{ last_day: string; file_by: string; adjustments: { date: string; reason: string }[] }>('/v1/prescription-periods/preview', { from, years, months }),
    enabled: /^\d{4}-\d{2}-\d{2}$/.test(from) && years + months > 0,
  })
}

export function useSavePrescription(matterId: number, id?: number) {
  return useApiMutation((input: PrescriptionInput) => (id ? put<Prescription>(`/v1/prescriptions/${id}`, input) : post<Prescription>(`/v1/matters/${matterId}/prescriptions`, input)), {
    invalidate,
    success: (p) => `Last day to file: ${longDate(p.last_day)}`,
    toastErrors: false,
  })
}

export function useInterruptPrescription() {
  return useApiMutation(({ id, ...input }: { id: number; date: string; kind: string; note: string | null }) => post<Prescription>(`/v1/prescriptions/${id}/interruptions`, input), {
    invalidate,
    success: 'Interruption recorded; the period starts again',
    toastErrors: false,
  })
}

export function useMarkFiled() {
  return useApiMutation(({ id, ...input }: { id: number; filed_on?: string; reopen?: boolean }) => post<Prescription>(`/v1/prescriptions/${id}/filed`, input), {
    invalidate,
    success: (p) => (p.state === 'filed' ? 'Marked filed' : 'Running again'),
  })
}

export function useDeletePrescription() {
  return useApiMutation((id: number) => del(`/v1/prescriptions/${id}`), { invalidate, success: 'Removed' })
}
