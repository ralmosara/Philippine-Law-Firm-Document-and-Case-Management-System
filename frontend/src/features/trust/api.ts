import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Paginated, TrustAccount, TrustTransaction } from '@/shared/api/types'

export function useTrustAccounts(params: { client_id?: number; matter_id?: number; page?: number; enabled?: boolean } = {}) {
  const { enabled = true, ...query } = params
  return useQuery({
    queryKey: ['trust', 'accounts', query],
    queryFn: () => get<Paginated<TrustAccount> & { totals: { balance_cents: number } }>('/v1/trust-accounts', { ...query }),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useTrustAccount(id: number) {
  return useQuery({ queryKey: ['trust', 'accounts', id], queryFn: () => get<TrustAccount>(`/v1/trust-accounts/${id}`) })
}

export function useTrustTransactions(id: number, page: number) {
  return useQuery({
    queryKey: ['trust', 'transactions', id, page],
    queryFn: () => get<Paginated<TrustTransaction>>(`/v1/trust-accounts/${id}/transactions`, { page }),
    placeholderData: keepPreviousData,
  })
}

export function useOpenTrustAccount() {
  return useApiMutation((input: { client_id: number; matter_id?: number | null }) => post<TrustAccount>('/v1/trust-accounts', input), {
    invalidate: [['trust']],
    success: (a) => `Trust account ${a.account_number} opened`,
    toastErrors: false,
  })
}

export function usePostTrustTransaction(id: number) {
  return useApiMutation(
    (input: { type: 'deposit' | 'disbursement'; amount_cents: number; description: string; reference?: string }) =>
      post<{ transaction: TrustTransaction; account: TrustAccount }>(`/v1/trust-accounts/${id}/transactions`, input),
    { invalidate: [['trust'], ['dashboard']], success: (r) => `${r.transaction.type === 'deposit' ? 'Deposit' : 'Disbursement'} posted`, toastErrors: false },
  )
}

export function useReconcile(id: number) {
  return useApiMutation(() => get<{ reconciled: boolean; problems: string[] }>(`/v1/trust-accounts/${id}/reconcile`))
}

export function useCloseTrustAccount(id: number) {
  return useApiMutation(() => post<TrustAccount>(`/v1/trust-accounts/${id}/close`), { invalidate: [['trust']], success: 'Trust account closed' })
}

/** Active matters as {id, reference, title, client_id} for pickers. */
export function useMatterOptions(params: { client_id?: number; enabled?: boolean } = {}) {
  const { enabled = true, client_id } = params
  return useQuery({
    queryKey: ['matters', 'options', client_id ?? null],
    queryFn: () => get<{ id: number; reference: string; title: string; client_id: number }[]>('/v1/matters/options', { client_id }),
    enabled,
    staleTime: 60_000,
  })
}
