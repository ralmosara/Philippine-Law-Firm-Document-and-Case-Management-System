import { Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import type { Matter } from '@/shared/api/types'
import { money, toCents } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { useGenerateInvoice } from '../api'

interface FeeLine {
  key: number
  description: string
  amount: string
}

/**
 * Compose a draft invoice: unbilled time and expenses plus fee lines for
 * non-hourly arrangements, suggested from the matter's engagement terms.
 */
export function InvoiceDraftDialog({ matter, onClose }: { matter: Matter; onClose: () => void }) {
  const generate = useGenerateInvoice()
  const navigate = useNavigate()
  const unbilledTime = matter.unbilled_cents ?? 0
  const unbilledExpenses = matter.unbilled_expenses_cents ?? 0
  const [includeTime, setIncludeTime] = useState(unbilledTime > 0)
  const [includeExpenses, setIncludeExpenses] = useState(unbilledExpenses > 0)
  const [lines, setLines] = useState<FeeLine[]>([])
  const [recovered, setRecovered] = useState('')
  const [dueInDays, setDueInDays] = useState('30')
  const [error, setError] = useState<ApiError | null>(null)
  let nextKey = lines.reduce((max, l) => Math.max(max, l.key), 0) + 1

  const add = (description = '', cents?: number | null) =>
    setLines((current) => [...current, { key: nextKey++, description, amount: cents ? (cents / 100).toFixed(2) : '' }])
  const update = (key: number, patch: Partial<FeeLine>) => setLines((current) => current.map((l) => (l.key === key ? { ...l, ...patch } : l)))

  const month = new Date().toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
  const suggestions: { label: string; description: string; cents: number | null | undefined }[] = [
    ...(matter.acceptance_fee_cents ? [{ label: 'Acceptance fee', description: 'Acceptance fee', cents: matter.acceptance_fee_cents }] : []),
    ...(matter.fee_arrangement === 'flat' && matter.fixed_fee_cents ? [{ label: 'Flat fee', description: 'Flat fee', cents: matter.fixed_fee_cents }] : []),
    ...(matter.fee_arrangement === 'retainer' && matter.fixed_fee_cents ? [{ label: 'Monthly retainer', description: `Retainer for ${month}`, cents: matter.fixed_fee_cents }] : []),
    ...(matter.appearance_fee_cents ? [{ label: 'Appearance fee', description: 'Appearance fee, hearing on ', cents: matter.appearance_fee_cents }] : []),
  ]
  const percent = (matter.contingency_basis_points ?? 0) / 100

  const feesTotal = lines.reduce((s, l) => s + (toCents(l.amount) || 0), 0) + (includeTime ? unbilledTime : 0)
  const nothing = !includeTime && !includeExpenses && lines.length === 0

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    const feeLines = lines.map((l) => ({ description: l.description.trim(), amount_cents: toCents(l.amount) }))
    if (feeLines.some((l) => !l.description || !(l.amount_cents > 0))) {
      setError(new ApiError(422, 'Every fee line needs a description and an amount.'))
      return
    }
    try {
      const invoice = await generate.mutateAsync({
        matter_id: matter.id,
        ...(includeTime ? {} : { time_entry_ids: [] }),
        ...(includeExpenses ? {} : { expense_ids: [] }),
        fee_lines: feeLines,
        due_in_days: Number(dueInDays) || 30,
      })
      navigate(`/billing/invoices/${invoice.id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      size="lg"
      title="Draft an invoice"
      description={matter.fee_arrangement_label ? `Fee arrangement: ${matter.fee_arrangement_label}` : undefined}
      footer={
        <>
          <span className="mr-auto text-sm text-on-surface-variant">Fees before VAT: <strong className="text-on-surface tabular-nums">{money(feesTotal)}</strong>{includeExpenses && unbilledExpenses > 0 && <> · expenses {money(unbilledExpenses)}</>}</span>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="invoice-draft" loading={generate.isPending} disabled={nothing}>Create draft</Button>
        </>
      }
    >
      <form id="invoice-draft" onSubmit={submit} className="flex flex-col gap-5">
        <FormError message={error ? (error.field('time_entry_ids') ?? error.message) : undefined} />

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">Include</legend>
          <Checkbox label={`Unbilled time: ${money(unbilledTime)}`} checked={includeTime} disabled={unbilledTime === 0} onChange={(e) => setIncludeTime(e.target.checked)} />
          <Checkbox label={`Unbilled expenses, at cost and outside VAT: ${money(unbilledExpenses)}`} checked={includeExpenses} disabled={unbilledExpenses === 0} onChange={(e) => setIncludeExpenses(e.target.checked)} />
        </fieldset>

        <fieldset className="flex flex-col gap-3">
          <legend className="mb-1 text-sm font-medium">Fee lines</legend>
          {(suggestions.length > 0 || percent > 0) && (
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs text-on-surface-variant">Add:</span>
              {suggestions.map((s) => (
                <Button key={s.label} variant="outlined" size="sm" icon={<Plus className="size-3.5" />} onClick={() => add(s.description, s.cents)}>
                  {s.label} {s.cents ? money(s.cents) : ''}
                </Button>
              ))}
            </div>
          )}
          {percent > 0 && (
            <div className="flex flex-col gap-2 rounded-xl bg-surface-container p-3 sm:flex-row sm:items-end">
              <Field label={`Amount recovered (₱) for the ${percent}% contingency fee`} className="flex-1">
                {(a) => <Input {...a} inputMode="decimal" placeholder="0.00" value={recovered} onChange={(e) => setRecovered(e.target.value)} />}
              </Field>
              <Button
                variant="tonal"
                disabled={!(toCents(recovered) > 0)}
                onClick={() => {
                  const base = toCents(recovered)
                  add(`Contingency fee: ${percent}% of ${money(base)} recovered`, Math.round((base * (matter.contingency_basis_points ?? 0)) / 10000))
                  setRecovered('')
                }}
              >
                Add contingency fee
              </Button>
            </div>
          )}
          {lines.map((line) => (
            <div key={line.key} className="grid grid-cols-[1fr_9rem_auto] items-center gap-2">
              <Input aria-label="Fee description" placeholder="Description" value={line.description} onChange={(e) => update(line.key, { description: e.target.value })} maxLength={500} />
              <Input aria-label="Fee amount in pesos" inputMode="decimal" placeholder="0.00" value={line.amount} onChange={(e) => update(line.key, { amount: e.target.value })} className="text-right tabular-nums" />
              <IconButton label="Remove fee line" onClick={() => setLines((current) => current.filter((l) => l.key !== line.key))}><Trash2 className="size-4" /></IconButton>
            </div>
          ))}
          <Button variant="text" size="sm" className="self-start" icon={<Plus className="size-4" />} onClick={() => add()}>Add a fee line</Button>
        </fieldset>

        <Field label="Due in (days)" className="max-w-40">
          {(a) => <Input {...a} type="number" min={0} max={365} value={dueInDays} onChange={(e) => setDueInDays(e.target.value)} />}
        </Field>
      </form>
    </Dialog>
  )
}
