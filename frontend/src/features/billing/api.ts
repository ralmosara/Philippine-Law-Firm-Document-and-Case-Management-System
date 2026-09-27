import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, patch, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Expense, Invoice, InvoiceStatus, Paginated, TimeEntry } from '@/shared/api/types'

type WithTotals<T> = Paginated<T> & { totals: { minutes: number; amount_cents: number } }

export interface TimeFilters {
  matter_id?: number
  mine?: boolean
  unbilled?: boolean
  from?: string
  to?: string
  page?: number
}

export function useTimeEntries(filters: TimeFilters) {
  return useQuery({
    queryKey: ['time-entries', filters],
    queryFn: () => get<WithTotals<TimeEntry>>('/v1/time-entries', { ...filters }),
    placeholderData: keepPreviousData,
  })
}

export interface TimeEntryInput {
  matter_id: number
  work_date: string
  minutes: number
  description: string
  is_billable: boolean
  rate_cents?: number
}

const timeInvalidate = [['time-entries'], ['matters'], ['dashboard']]

export function useSaveTimeEntry(id?: number) {
  return useApiMutation((input: TimeEntryInput) => (id ? put<TimeEntry>(`/v1/time-entries/${id}`, input) : post<TimeEntry>('/v1/time-entries', input)), {
    invalidate: timeInvalidate,
    success: id ? 'Time entry updated' : 'Time logged',
    toastErrors: false,
  })
}

export function useDeleteTimeEntry() {
  return useApiMutation((id: number) => del(`/v1/time-entries/${id}`), { invalidate: timeInvalidate, success: 'Time entry deleted' })
}

export function useInvoices(params: { status?: InvoiceStatus | ''; matter_id?: number; client_id?: number; page?: number; enabled?: boolean }) {
  const { enabled = true, ...query } = params
  return useQuery({
    queryKey: ['invoices', query],
    queryFn: () => get<Paginated<Invoice>>('/v1/invoices', { ...query }),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useInvoice(id: number) {
  return useQuery({ queryKey: ['invoices', id], queryFn: () => get<Invoice>(`/v1/invoices/${id}`) })
}

const invoiceInvalidate = [['invoices'], ['time-entries'], ['matters'], ['trust'], ['dashboard']]

export function useGenerateInvoice() {
  return useApiMutation((input: InvoiceDraftInput) => post<Invoice>('/v1/invoices', input), {
    invalidate: invoiceInvalidate,
    success: (i) => `Draft invoice ${i.number} created`,
  })
}

export function useInvoiceAction(id: number) {
  return {
    issue: useApiMutation(() => post<Invoice>(`/v1/invoices/${id}/issue`), { invalidate: invoiceInvalidate, success: 'Invoice issued' }),
    pay: useApiMutation((input: { payment_reference?: string; trust_account_id?: number }) => post<Invoice>(`/v1/invoices/${id}/pay`, input), {
      invalidate: invoiceInvalidate,
      success: 'Payment recorded',
    }),
    void: useApiMutation(() => post<Invoice>(`/v1/invoices/${id}/void`), { invalidate: invoiceInvalidate, success: 'Invoice voided; its time is billable again' }),
  }
}

/** A PayMongo checkout link to send to the client; the invoice updates itself when paid. */
export function usePaymentLink(invoiceId: number) {
  return useApiMutation(() => post<{ checkout_url: string }>(`/v1/invoices/${invoiceId}/payment-link`), {
    invalidate: [['invoices', invoiceId]],
    toastErrors: true,
  })
}

export function useExpenses(params: { matter_id?: number; unbilled?: boolean; page?: number }) {
  return useQuery({
    queryKey: ['expenses', params],
    queryFn: () => get<Paginated<Expense> & { totals: { amount_cents: number } }>('/v1/expenses', { ...params }),
    placeholderData: keepPreviousData,
  })
}

export interface ExpenseInput {
  matter_id: number
  expense_date: string
  category: string
  description: string
  amount_cents: number
  is_billable: boolean
  receipt_file_id?: number | null
}

export function useSaveExpense(id?: number) {
  return useApiMutation((input: ExpenseInput) => (id ? patch<Expense>(`/v1/expenses/${id}`, input) : post<Expense>('/v1/expenses', input)), {
    invalidate: [['expenses'], ['matters']],
    success: id ? 'Expense updated' : 'Expense recorded',
    toastErrors: false,
  })
}

export function useDeleteExpense() {
  return useApiMutation((id: number) => del(`/v1/expenses/${id}`), { invalidate: [['expenses'], ['matters']], success: 'Expense deleted' })
}

/** Omitted id lists bill everything unbilled; an empty list bills none of that kind. */
export interface InvoiceDraftInput {
  matter_id: number
  time_entry_ids?: number[]
  expense_ids?: number[]
  fee_lines?: { description: string; amount_cents: number }[]
  due_in_days?: number
  notes?: string
}
