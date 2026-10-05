import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, FileDown, Plus, Scale, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { dateTime, money, toCents } from '@/shared/lib/format'
import { Button, DownloadButton, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

interface Item { description: string; amount_cents: number }

export interface Reconciliation {
  id: number
  period_end: string
  bank_account: string
  statement_balance_cents: number
  deposits_in_transit: Item[]
  outstanding_checks: Item[]
  adjusted_bank_cents: number
  ledger_cents: number
  client_total_cents: number
  difference_cents: number
  balances: boolean
  exceptions: string[]
  notes: string | null
  prepared_by: string | null
  signed_off_by: string | null
  signed_off_at: string | null
}

interface ReconciliationList {
  data: Reconciliation[]
  next: { period_end: string; ledger_cents: number; client_total_cents: number; exceptions: string[] }
}

const month = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
const signed = (c: number) => (c < 0 ? `(${money(-c)})` : money(c))

function useReconciliations() {
  return useQuery({ queryKey: ['trust', 'reconciliations'], queryFn: () => get<ReconciliationList>('/v1/trust-reconciliations') })
}

/**
 * The monthly three-way reconciliation: the bank statement (adjusted for
 * deposits in transit and uncleared cheques), the trust ledger, and the
 * sum of the client balances, signed off by a partner.
 */
export function TrustReconciliationPanel() {
  const query = useReconciliations()
  const [editing, setEditing] = useState<{ period_end: string; existing?: Reconciliation } | null>(null)
  const [signing, setSigning] = useState<Reconciliation | null>(null)

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const { data, next } = query.data
  const nextDone = data.find((r) => r.period_end === next.period_end)

  return (
    <div className="flex flex-col gap-6">
      {!nextDone?.signed_off_at && (
        <Card>
          <CardHeader
            title={`Reconcile ${month(next.period_end)}`}
            description={`The trust ledger shows ${money(next.ledger_cents)} held at the end of the month. Enter the bank statement balance and anything not yet on it; the three figures must agree.`}
            actions={<Button icon={<Scale className="size-4" />} onClick={() => setEditing({ period_end: next.period_end, existing: nextDone })}>{nextDone ? 'Continue' : 'Start'}</Button>}
          />
        </Card>
      )}

      <Card>
        <CardHeader title="Reconciliations" description="Signed-off months cannot be changed. Download any month as a PDF for the auditor." />
        {data.length === 0 ? <EmptyState icon={<Scale className="size-6" />} title="None yet" description="Reconcile each month once the bank statement arrives." /> : (
          <Table caption="Trust reconciliations">
            <thead><tr><Th>Month</Th><Th align="right">Adjusted bank</Th><Th align="right">Trust ledger</Th><Th align="right">Client balances</Th><Th>Result</Th><Th>Signed off</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
            <tbody>
              {data.map((r) => (
                <tr key={r.id}>
                  <Td className="font-medium">{month(r.period_end)}<div className="text-xs text-on-surface-variant">{r.bank_account}</div></Td>
                  <Td align="right">{signed(r.adjusted_bank_cents)}</Td>
                  <Td align="right">{signed(r.ledger_cents)}</Td>
                  <Td align="right">{signed(r.client_total_cents)}</Td>
                  <Td>{r.balances ? <Badge tone="success">Balances</Badge> : <Badge tone="danger">Off by {signed(r.difference_cents || r.ledger_cents - r.client_total_cents)}</Badge>}</Td>
                  <Td className="text-sm">{r.signed_off_at ? <>{r.signed_off_by}<div className="text-xs text-on-surface-variant">{dateTime(r.signed_off_at)}</div></> : <span className="text-on-surface-variant">Not yet</span>}</Td>
                  <Td align="right">
                    <div className="flex justify-end gap-1">
                      {!r.signed_off_at && <Button size="sm" variant="text" onClick={() => setEditing({ period_end: r.period_end, existing: r })}>Edit</Button>}
                      {!r.signed_off_at && <Button size="sm" variant="tonal" icon={<CheckCircle2 className="size-4" />} onClick={() => setSigning(r)}>Sign off</Button>}
                      <DownloadButton size="sm" href={`/api/v1/trust-reconciliations/${r.id}/pdf`} icon={<FileDown className="size-4" />}>PDF</DownloadButton>
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {editing && <ReconcileDialog periodEnd={editing.period_end} existing={editing.existing} ledger={next.period_end === editing.period_end ? next.ledger_cents : editing.existing?.ledger_cents ?? 0} onClose={() => setEditing(null)} onSaved={(r) => { setEditing(null); setSigning(r) }} />}
      {signing && <SignOffDialog reconciliation={signing} onClose={() => setSigning(null)} />}
    </div>
  )
}

function ItemList({ label, add, items, onChange }: { label: string; add: string; items: { description: string; amount: string }[]; onChange: (items: { description: string; amount: string }[]) => void }) {
  return (
    <fieldset className="flex flex-col gap-2">
      <legend className="mb-1 text-sm font-medium">{label}</legend>
      {items.map((item, i) => (
        <div key={i} className="flex items-end gap-2">
          <Input aria-label={`${label} ${i + 1}: description`} placeholder="Date and reference" value={item.description} onChange={(e) => onChange(items.map((x, j) => (j === i ? { ...x, description: e.target.value } : x)))} className="flex-1" />
          <Input aria-label={`${label} ${i + 1}: amount`} inputMode="decimal" placeholder="0.00" value={item.amount} onChange={(e) => onChange(items.map((x, j) => (j === i ? { ...x, amount: e.target.value } : x)))} className="w-36" />
          <IconButton label={`Remove ${label.toLowerCase()} ${i + 1}`} onClick={() => onChange(items.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
        </div>
      ))}
      <div><Button type="button" size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => onChange([...items, { description: '', amount: '' }])}>{add}</Button></div>
    </fieldset>
  )
}

function ReconcileDialog({ periodEnd, existing, ledger, onClose, onSaved }: { periodEnd: string; existing?: Reconciliation; ledger: number; onClose: () => void; onSaved: (r: Reconciliation) => void }) {
  const toRows = (items?: Item[]) => (items ?? []).map((i) => ({ description: i.description, amount: (i.amount_cents / 100).toFixed(2) }))
  const [bank, setBank] = useState(existing?.bank_account ?? '')
  const [statement, setStatement] = useState(existing ? (existing.statement_balance_cents / 100).toFixed(2) : '')
  const [deposits, setDeposits] = useState(toRows(existing?.deposits_in_transit))
  const [checks, setChecks] = useState(toRows(existing?.outstanding_checks))
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const save = useApiMutation((input: object) => post<Reconciliation>('/v1/trust-reconciliations', input), { invalidate: [['trust', 'reconciliations']], success: 'Reconciliation saved', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null

  const cents = (v: string) => (v.trim() === '' ? 0 : toCents(v))
  const items = (rows: { description: string; amount: string }[]) => rows.filter((r) => r.description.trim() || r.amount.trim()).map((r) => ({ description: r.description.trim(), amount_cents: cents(r.amount) }))
  const adjusted = cents(statement) + items(deposits).reduce((s, i) => s + (i.amount_cents || 0), 0) - items(checks).reduce((s, i) => s + (i.amount_cents || 0), 0)
  const difference = Number.isFinite(adjusted) ? adjusted - ledger : NaN

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ period_end: periodEnd, bank_account: bank.trim(), statement_balance_cents: cents(statement), deposits_in_transit: items(deposits), outstanding_checks: items(checks), notes: notes.trim() || null }, { onSuccess: onSaved })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      size="lg"
      title={`Reconcile ${month(periodEnd)}`}
      description="From the trust bank account's statement for the month."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="reconcile" loading={save.isPending}>Save</Button></>}
    >
      <form id="reconcile" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Bank account" required error={error?.field('bank_account')}>{(a) => <Input {...a} value={bank} onChange={(e) => setBank(e.target.value)} placeholder="BPI Client Trust 1234-5678-90" />}</Field>
          <Field label="Balance per bank statement (₱)" required error={error?.field('statement_balance_cents')}>{(a) => <Input {...a} inputMode="decimal" value={statement} onChange={(e) => setStatement(e.target.value)} placeholder="0.00" />}</Field>
        </div>
        <ItemList label="Deposits in transit" add="Add a deposit not yet on the statement" items={deposits} onChange={setDeposits} />
        <ItemList label="Outstanding cheques" add="Add a cheque issued but not yet cleared" items={checks} onChange={setChecks} />

        <dl className="grid grid-cols-1 gap-2 rounded-[3px] bg-surface-container p-4 text-sm sm:grid-cols-3" aria-live="polite">
          <div><dt className="text-on-surface-variant">Adjusted bank balance</dt><dd className="text-lg font-semibold tabular-nums">{Number.isFinite(adjusted) ? signed(adjusted) : '—'}</dd></div>
          <div><dt className="text-on-surface-variant">Trust ledger</dt><dd className="text-lg font-semibold tabular-nums">{signed(ledger)}</dd></div>
          <div><dt className="text-on-surface-variant">Difference</dt><dd className={`text-lg font-semibold tabular-nums ${difference === 0 ? 'text-success' : 'text-danger'}`}>{Number.isFinite(difference) ? (difference === 0 ? 'None' : signed(difference)) : '—'}</dd></div>
        </dl>

        <Field label="Notes" hint="Explain any difference and what is being done about it.">{(a) => <Textarea {...a} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
        <FormError message={error && !Object.keys(error.errors).some((k) => ['bank_account', 'statement_balance_cents'].includes(k)) ? error.message : null} />
      </form>
    </Dialog>
  )
}

function SignOffDialog({ reconciliation: r, onClose }: { reconciliation: Reconciliation; onClose: () => void }) {
  const [notes, setNotes] = useState(r.notes ?? '')
  const sign = useApiMutation((input: { notes: string | null }) => post<Reconciliation>(`/v1/trust-reconciliations/${r.id}/sign-off`, input), { invalidate: [['trust', 'reconciliations']], success: 'Signed off', toastErrors: false })
  const error = sign.error ? ApiError.from(sign.error) : null

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Sign off ${month(r.period_end)}?`}
      description="Once signed off, this month cannot be changed. The figures are checked again against the ledger first."
      footer={<><Button variant="text" onClick={onClose}>Not yet</Button><Button loading={sign.isPending} onClick={() => sign.mutate({ notes: notes.trim() || null }, { onSuccess: onClose })}>Sign off</Button></>}
    >
      <div className="flex flex-col gap-4 text-sm">
        <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1">
          <dt>Adjusted bank balance</dt><dd className="text-right tabular-nums">{signed(r.adjusted_bank_cents)}</dd>
          <dt>Trust ledger</dt><dd className="text-right tabular-nums">{signed(r.ledger_cents)}</dd>
          <dt>Total of client balances</dt><dd className="text-right tabular-nums">{signed(r.client_total_cents)}</dd>
        </dl>
        {r.balances ? <p className="rounded-[3px] bg-success-container p-3 text-on-success-container">All three agree and no client is below zero.</p> : (
          <div className="rounded-[3px] bg-danger-container p-3 text-on-danger-container">
            <p className="font-medium">Does not balance{r.difference_cents ? `: the bank is ${signed(r.difference_cents)} off the ledger` : ''}.</p>
            {r.exceptions.map((e) => <p key={e}>{e}</p>)}
          </div>
        )}
        <Field label="Notes" required={!r.balances} error={error?.field('notes')}>{(a) => <Textarea {...a} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
        <FormError message={error && !error.field('notes') ? error.message : null} />
      </div>
    </Dialog>
  )
}
