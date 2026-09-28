import { useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type DisbursementStatus = 'pending' | 'approved' | 'rejected' | 'released' | 'liquidated' | 'cancelled'

export interface Disbursement {
  id: number
  matter: { id: number; reference: string; title: string } | null
  requester: string | null
  category: string
  category_label: string
  description: string
  amount_cents: number
  needed_by: string | null
  source: 'firm' | 'trust'
  trust_account: { id: number; account_number: string; balance_cents: number } | null
  status: DisbursementStatus
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  released_by: string | null
  released_at: string | null
  release_reference: string | null
  liquidation_due_on: string | null
  is_overdue: boolean
  spent_cents: number | null
  returned_cents: number | null
  reimburse_cents: number | null
  liquidated_at: string | null
  liquidation_note: string | null
  expenses: { id: number; category: string; description: string; amount_cents: number; expense_date: string | null; receipt: { id: number; name: string } | null }[]
  can: { approve: boolean; reject: boolean; release: boolean; cancel: boolean; liquidate: boolean }
}

export interface DisbursementList {
  data: Disbursement[]
  counts: { pending: number; approved: number; released: number; overdue: number; outstanding_cents: number }
  categories: { value: string; label: string }[]
}

export interface LiquidationItem {
  expense_date: string
  category: string
  description: string
  amount_cents: number
  receipt_file_id: number | null
  is_billable?: boolean
}

export const STATUS: Record<DisbursementStatus, { label: string; tone: 'primary' | 'success' | 'warning' | 'danger' | 'neutral' }> = {
  pending: { label: 'For approval', tone: 'warning' },
  approved: { label: 'To release', tone: 'primary' },
  released: { label: 'To liquidate', tone: 'primary' },
  liquidated: { label: 'Liquidated', tone: 'success' },
  rejected: { label: 'Not approved', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

export function useDisbursements(params: { status?: string; matter_id?: number }) {
  return useQuery({ queryKey: ['disbursements', params], queryFn: () => get<DisbursementList>('/v1/disbursements', { ...params }) })
}

const invalidate = [['disbursements'], ['expenses'], ['trust']]

export function useRequestDisbursement() {
  return useApiMutation(
    ({ matterId, ...input }: { matterId: number; category: string; description: string; amount_cents: number; needed_by: string | null; source: 'firm' | 'trust'; trust_account_id: number | null }) =>
      post<Disbursement>(`/v1/matters/${matterId}/disbursements`, input),
    { invalidate, success: 'Request sent for approval', toastErrors: false },
  )
}

export function useDisbursementAction() {
  return useApiMutation(
    ({ id, action, ...input }: { id: number; action: 'approve' | 'reject' | 'release' | 'cancel'; reason?: string; reference?: string | null; note?: string | null }) =>
      post<Disbursement>(`/v1/disbursements/${id}/${action}`, input),
    { invalidate, success: (d) => STATUS[d.status].label === 'To release' ? 'Approved' : `Now: ${STATUS[d.status].label.toLowerCase()}`, toastErrors: false },
  )
}

export function useLiquidate() {
  return useApiMutation(({ id, ...input }: { id: number; items: LiquidationItem[]; note?: string | null }) => post<Disbursement>(`/v1/disbursements/${id}/liquidate`, input), {
    invalidate,
    success: 'Liquidated; the receipts are now expenses on the matter',
    toastErrors: false,
  })
}
