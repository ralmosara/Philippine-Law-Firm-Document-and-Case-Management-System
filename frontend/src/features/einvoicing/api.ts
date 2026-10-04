import { useQuery } from '@tanstack/react-query'
import { get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type EInvoiceStatus = 'pending' | 'recorded' | 'submitted' | 'accepted' | 'rejected' | 'failed' | 'withdrawn'

export interface EInvoiceRow {
  id: number
  kind: 'invoice' | 'cancellation'
  status: EInvoiceStatus
  driver: string
  invoice: { id: number; number: string; total_cents: number; client: string | null } | null
  provider_reference: string | null
  error: string | null
  attempts: number
  payload_sha256: string
  due_on: string
  overdue: boolean
  submitted_at: string | null
  accepted_at: string | null
  created_at: string
  can_retry: boolean
}

export interface EInvoicingSettings {
  einvoicing_enabled: boolean
  tin: string | null
  tin_branch_code: string
  deadline_days: number
  driver: string
  ready: boolean
  problem: string | null
}

export const STATUS_LABELS: Record<EInvoiceStatus, string> = {
  pending: 'Waiting to send',
  recorded: 'Kept on file',
  submitted: 'Sent',
  accepted: 'Accepted',
  rejected: 'Rejected',
  failed: 'Not sent yet',
  withdrawn: 'Withdrawn',
}

export const STATUS_TONES: Record<EInvoiceStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  pending: 'neutral',
  recorded: 'neutral',
  submitted: 'primary',
  accepted: 'success',
  rejected: 'danger',
  failed: 'warning',
  withdrawn: 'neutral',
}

export const payloadUrl = (id: number) => `/api/v1/e-invoices/${id}/payload`

export function useEInvoicing(status: string) {
  return useQuery({
    queryKey: ['e-invoicing', status],
    queryFn: () => get<{ settings: EInvoicingSettings; counts: Record<string, number>; data: EInvoiceRow[]; meta: { total: number } }>('/v1/e-invoicing', { status: status || undefined }),
  })
}

export function useInvoiceEInvoices(invoiceId: number, enabled: boolean) {
  return useQuery({ queryKey: ['e-invoicing', 'invoice', invoiceId], queryFn: () => get<{ enabled: boolean; data: EInvoiceRow[] }>(`/v1/invoices/${invoiceId}/e-invoices`), enabled })
}

export function useSaveEInvoicingSettings() {
  return useApiMutation((input: { einvoicing_enabled: boolean; tin_branch_code: string }) => put<{ settings: EInvoicingSettings }>('/v1/e-invoicing/settings', input), {
    invalidate: [['e-invoicing']],
    success: (r) => (r.settings.einvoicing_enabled ? 'E-invoicing is on for invoices you issue from now' : 'E-invoicing is off'),
    toastErrors: false,
  })
}

export function useRetryEInvoice() {
  return useApiMutation((id: number) => post<EInvoiceRow>(`/v1/e-invoices/${id}/retry`), { invalidate: [['e-invoicing']], success: 'Sending again' })
}
