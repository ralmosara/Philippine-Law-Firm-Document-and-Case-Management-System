import { useQuery } from '@tanstack/react-query'
import { del, get, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type BudgetBasis = 'amount' | 'hours'

/** `total` and `used` are centavos for a peso budget, minutes for an hours budget. */
export interface MatterBudget {
  basis: BudgetBasis
  total: number
  include_expenses: boolean
  stages: { stage: string; total: number }[]
  shared_with_client: boolean
  notes: string | null
  alerted_80_at: string | null
  alerted_100_at: string | null
  updated_by: string | null
  updated_at: string | null
}

export interface BudgetUsage {
  used: number
  total: number
  percent: number
  fees_cents: number
  expenses_cents: number
  minutes: number
  by_stage: { stage: string; label: string; total: number | null; used: number; percent: number | null }[]
}

export interface BudgetInput {
  basis: BudgetBasis
  total: number
  include_expenses: boolean
  stages: { stage: string; total: number }[]
  shared_with_client: boolean
  notes: string | null
}

export function useMatterBudget(matterId: number) {
  return useQuery({ queryKey: ['budget', matterId], queryFn: () => get<{ budget: MatterBudget | null; usage: BudgetUsage | null }>(`/v1/matters/${matterId}/budget`) })
}

export function useSaveBudget(matterId: number) {
  return useApiMutation((input: BudgetInput) => put<{ budget: MatterBudget; usage: BudgetUsage }>(`/v1/matters/${matterId}/budget`, input), {
    invalidate: [['budget', matterId]],
    success: 'Budget saved',
    toastErrors: false,
  })
}

export function useRemoveBudget(matterId: number) {
  return useApiMutation(() => del(`/v1/matters/${matterId}/budget`), { invalidate: [['budget', matterId]], success: 'Budget removed' })
}
