import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { AuditEntry, DeadlineRule, Holiday, Paginated, WorkflowTemplate } from '@/shared/api/types'

export function useAllDeadlineRules() {
  return useQuery({ queryKey: ['deadline-rules', 'all'], queryFn: () => get<DeadlineRule[]>('/v1/deadline-rules') })
}

export function useSaveRule(id?: number) {
  return useApiMutation((input: Omit<DeadlineRule, 'id' | 'is_system'>) => (id ? put<DeadlineRule>(`/v1/deadline-rules/${id}`, input) : post<DeadlineRule>('/v1/deadline-rules', input)), {
    invalidate: [['deadline-rules']],
    success: 'Rule saved',
    toastErrors: false,
  })
}

export function useDeleteRule() {
  return useApiMutation((id: number) => del(`/v1/deadline-rules/${id}`), { invalidate: [['deadline-rules']], success: 'Rule deleted' })
}

export function useHolidays(year: number) {
  return useQuery({ queryKey: ['holidays', year], queryFn: async () => (await get<{ data: Holiday[] }>('/v1/holidays', { year })).data })
}

export function useAddHoliday() {
  return useApiMutation((input: Omit<Holiday, 'id'>) => post<Holiday>('/v1/holidays', input), {
    invalidate: [['holidays'], ['deadlines', 'compute']],
    success: (h) => `${h.name} added`,
    toastErrors: false,
  })
}

export function useDeleteHoliday() {
  return useApiMutation((id: number) => del(`/v1/holidays/${id}`), { invalidate: [['holidays'], ['deadlines', 'compute']], success: 'Holiday removed' })
}

export function useWorkflows() {
  return useQuery({ queryKey: ['workflows'], queryFn: async () => (await get<{ data: WorkflowTemplate[] }>('/v1/workflow-templates')).data })
}

export function useSaveWorkflow(id?: number) {
  return useApiMutation((input: Omit<WorkflowTemplate, 'id'>) => (id ? put<WorkflowTemplate>(`/v1/workflow-templates/${id}`, input) : post<WorkflowTemplate>('/v1/workflow-templates', input)), {
    invalidate: [['workflows']],
    success: 'Workflow saved',
    toastErrors: false,
  })
}

export function useDeleteWorkflow() {
  return useApiMutation((id: number) => del(`/v1/workflow-templates/${id}`), { invalidate: [['workflows']], success: 'Workflow deleted' })
}

export function useAuditLog(page: number, subjectType: string) {
  return useQuery({
    queryKey: ['audit', page, subjectType],
    queryFn: () => get<Paginated<AuditEntry>>('/v1/audit-logs', { page, subject_type: subjectType }),
    placeholderData: keepPreviousData,
  })
}

export interface FirmSettings {
  id: number
  name: string
  tin: string | null
  address: string | null
  email: string | null
  phone: string | null
  vat_registered: boolean
  /** Usual creditable withholding on fees, in basis points (1000 = 10%). */
  default_withholding_bps: number
  payment_reminders_enabled: boolean
  client_hearing_reminders: boolean
  statements_enabled: boolean
  /** Day of the month (1-28) statements of account are emailed. */
  statement_day: number
  time_reminders_enabled: boolean
  daily_target_minutes: number
  pleading_paper: 'folio' | 'a4' | 'letter'
  pleading_font: string
  pleading_font_size: number
  taxpayer_type: 'individual' | 'juridical'
  withholding_atc: string
  has_employees: boolean
  require_two_factor: boolean
  slug: string | null
  intake_enabled: boolean
  intake_message: string | null
  ai_enabled: boolean
  ai_configured: boolean
  users_without_two_factor: number
}

export function useFirmSettings() {
  return useQuery({ queryKey: ['firm'], queryFn: () => get<FirmSettings>('/v1/firm') })
}

export function useSaveFirmSettings() {
  return useApiMutation((input: Partial<Omit<FirmSettings, 'id' | 'users_without_two_factor' | 'ai_configured'>>) => put<FirmSettings>('/v1/firm', input), {
    invalidate: [['firm'], ['session'], ['assistant']],
    success: 'Firm settings saved',
    toastErrors: false,
  })
}
