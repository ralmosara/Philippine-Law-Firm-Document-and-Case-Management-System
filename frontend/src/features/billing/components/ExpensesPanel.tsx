import { Pencil, Plus, Receipt, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useAbilities, useCurrentSession, useLookups } from '@/features/auth/session'
import { fileDownloadUrl, useMatterFiles } from '@/features/documents/api'
import { ApiError } from '@/shared/api/axios'
import type { Expense } from '@/shared/api/types'
import { date, money, toCents, today } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { useDeleteExpense, useExpenses, useSaveExpense } from '../api'

/** Costs advanced for the client on a matter, billed at cost on the next invoice. */
export function ExpensesPanel({ matterId }: { matterId: number }) {
  const abilities = useAbilities()
  const { user } = useCurrentSession()
  const expenses = useExpenses({ matter_id: matterId })
  const remove = useDeleteExpense()
  const [editing, setEditing] = useState<Expense | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Expense | null>(null)
  const canModify = (e: Expense) => !e.is_invoiced && (e.user?.id === user.id || abilities.manage_finances)

  return (
    <Card>
      <CardHeader
        title="Expenses"
        description={expenses.data ? `${money(expenses.data.totals.amount_cents)} recorded · reimbursed at cost, outside VAT` : undefined}
        actions={abilities.work_matters && <Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add expense</Button>}
      />
      {expenses.isPending ? (
        <PageLoader />
      ) : expenses.isError ? (
        <div className="p-4"><ErrorState error={expenses.error} /></div>
      ) : expenses.data.data.length === 0 ? (
        <EmptyState icon={<Receipt className="size-6" />} title="No expenses yet" description="Record docket fees, sheriff’s fees, TSN and other costs advanced for the client." />
      ) : (
        <Table caption="Expenses">
          <thead><tr><Th>Date</Th><Th>Expense</Th><Th>Status</Th><Th align="right">Amount</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {expenses.data.data.map((e) => (
              <tr key={e.id}>
                <Td className="whitespace-nowrap">{date(e.expense_date)}</Td>
                <Td>
                  <span className="font-medium">{e.category_label}</span>
                  <div className="text-sm text-on-surface-variant">{e.description}{e.user && ` · ${e.user.name}`}</div>
                  {e.receipt && <a href={fileDownloadUrl(e.receipt.id)} download className="text-xs text-primary hover:underline">Receipt: {e.receipt.name}</a>}
                </Td>
                <Td>{e.is_invoiced ? <Badge tone="success">Billed</Badge> : e.is_billable ? <Badge tone="warning">Unbilled</Badge> : <Badge>Not billable</Badge>}</Td>
                <Td align="right">{money(e.amount_cents)}</Td>
                <Td align="right" className="whitespace-nowrap">
                  {canModify(e) && (
                    <>
                      <IconButton label="Edit expense" onClick={() => setEditing(e)}><Pencil className="size-4" /></IconButton>
                      <IconButton label="Delete expense" onClick={() => setDeleting(e)}><Trash2 className="size-4" /></IconButton>
                    </>
                  )}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}

      {editing && <ExpenseDialog matterId={matterId} expense={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete this expense?"
        description={deleting ? `${deleting.category_label}: ${money(deleting.amount_cents)}` : ''}
        destructive
        confirmLabel="Delete"
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
      />
    </Card>
  )
}

function ExpenseDialog({ matterId, expense, onClose }: { matterId: number; expense?: Expense; onClose: () => void }) {
  const lookups = useLookups()
  const files = useMatterFiles(matterId)
  const save = useSaveExpense(expense?.id)
  const [form, setForm] = useState({
    expense_date: expense?.expense_date ?? today(),
    category: expense?.category ?? 'filing_fee',
    description: expense?.description ?? '',
    amount: expense ? (expense.amount_cents / 100).toFixed(2) : '',
    is_billable: expense?.is_billable ?? true,
    receipt_file_id: expense?.receipt ? String(expense.receipt.id) : '',
  })
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    const amount = toCents(form.amount)
    if (!(amount > 0)) {
      setError(new ApiError(422, 'Enter the amount.', { amount_cents: ['Enter the amount in pesos.'] }))
      return
    }
    try {
      await save.mutateAsync({
        matter_id: matterId,
        expense_date: form.expense_date,
        category: form.category,
        description: form.description,
        amount_cents: amount,
        is_billable: form.is_billable,
        receipt_file_id: form.receipt_file_id ? Number(form.receipt_file_id) : null,
      })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={expense ? 'Edit expense' : 'Add expense'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="expense-form" loading={save.isPending}>Save</Button></>}
    >
      <form id="expense-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>
        <Field label="Date" required error={error?.field('expense_date')}>
          {(a) => <Input {...a} type="date" max={today()} required value={form.expense_date} onChange={(e) => setForm((f) => ({ ...f, expense_date: e.target.value }))} />}
        </Field>
        <Field label="Amount (₱)" required error={error?.field('amount_cents')}>
          {(a) => <Input {...a} inputMode="decimal" placeholder="0.00" required value={form.amount} onChange={(e) => setForm((f) => ({ ...f, amount: e.target.value }))} />}
        </Field>
        <Field label="Category" required error={error?.field('category')} className="sm:col-span-2">
          {(a) => (
            <Select {...a} value={form.category} onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}>
              {lookups.data?.expense_categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Description" required error={error?.field('description')} className="sm:col-span-2">
          {(a) => <Input {...a} required maxLength={500} placeholder="e.g. Docket fees for the complaint, OR No. 1234567" value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />}
        </Field>
        <Field label="Receipt" hint="Upload the scan under Files first." error={error?.field('receipt_file_id')} className="sm:col-span-2">
          {(a) => (
            <Select {...a} value={form.receipt_file_id} onChange={(e) => setForm((f) => ({ ...f, receipt_file_id: e.target.value }))}>
              <option value="">None</option>
              {files.data?.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </Select>
          )}
        </Field>
        <div className="sm:col-span-2">
          <Checkbox label="Bill this to the client" checked={form.is_billable} onChange={(e) => setForm((f) => ({ ...f, is_billable: e.target.checked }))} />
        </div>
      </form>
    </Dialog>
  )
}
