import { useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type AgingBucket = 'current' | '1_30' | '31_60' | '61_90' | 'over_90'

export const AGING_LABELS: Record<AgingBucket, string> = {
  current: 'Not yet due',
  '1_30': '1–30 days',
  '31_60': '31–60 days',
  '61_90': '61–90 days',
  over_90: 'Over 90 days',
}

/** A client's statement of account as of today, in centavos. */
export interface StatementSummary {
  total_due: number
  aging: Record<AgingBucket, number>
  open_invoices: number
  trust_total: number
  has_content: boolean
  sent_on: string | null
}

export function useClientStatement(clientId: number, enabled = true) {
  return useQuery({
    queryKey: ['statements', clientId],
    queryFn: () => get<StatementSummary>(`/v1/clients/${clientId}/statement`),
    enabled,
  })
}

export function useSendStatement(clientId: number) {
  return useApiMutation(() => post(`/v1/clients/${clientId}/statement/send`), {
    invalidate: [['statements', clientId]],
    success: 'Statement of account emailed',
  })
}

export function useSendAllStatements() {
  return useApiMutation(() => post<{ sent: number }>('/v1/statements/send'), {
    invalidate: [['statements']],
    success: (r) => (r.sent === 1 ? 'Sent 1 statement of account' : `Sent ${r.sent} statements of account`),
  })
}
