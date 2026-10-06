import { useQuery } from '@tanstack/react-query'
import { Calculator, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, money, toCents } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'

interface Schedule {
  court: string
  brackets: { under_cents: number; fee_cents: number }[]
  excess: { from_cents: number; base_cents: number; per_thousand_cents: number } | null
  extras: { label: string; fixed_cents?: number | null; percent_bps?: number | null; minimum_cents?: number | null }[]
}
interface FeeTable { schedule: Schedule; confirmed: boolean; confirmed_at: string | null; confirmed_by: string | null }
interface Estimate { lines: { label: string; amount_cents: number }[]; total_cents: number }

export function useFilingFeeTable(enabled = true) {
  return useQuery({ queryKey: ['filing-fees'], queryFn: () => get<FeeTable>('/v1/filing-fees'), enabled })
}

/**
 * Estimate the docket and other lawful fees for a money claim from the
 * firm's reviewed fee table, then record what was actually paid as an expense.
 */
export function FilingFeeEstimateDialog({ matterId, onRecord, onClose }: { matterId: number; onRecord: (preset: { amount_cents: number; description: string }) => void; onClose: () => void }) {
  const table = useFilingFeeTable()
  const [claim, setClaim] = useState('')
  const estimate = useApiMutation((claim_cents: number) => post<Estimate>(`/v1/matters/${matterId}/filing-fees/estimate`, { claim_cents }), { toastErrors: false })
  const error = estimate.error ? ApiError.from(estimate.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const cents = toCents(claim)
    if (Number.isFinite(cents) && cents >= 0) estimate.mutate(cents)
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Estimate filing fees"
      description="For a complaint for a sum of money: enter the total claimed, including interest, damages of every kind and attorney's fees, as the docket fees are computed on all of them."
      footer={<>
        <Button variant="text" onClick={onClose}>Close</Button>
        {estimate.data && <Button onClick={() => onRecord({ amount_cents: estimate.data!.total_cents, description: `Docket and lawful fees on a claim of ${money(toCents(claim))} (estimate: adjust to the official receipt)` })}>Record as an expense</Button>}
      </>}
    >
      {table.isPending ? <PageLoader /> : !table.data?.confirmed ? (
        <p className="rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">The firm's filing fee table has not been reviewed yet, so no estimate can be given. A managing partner checks it against the current Rule 141 and circulars under Firm Settings → Firm.</p>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <div className="flex items-end gap-2">
            <Field label="Total amount claimed (₱)" className="flex-1" error={error?.field('claim_cents')}>{(a) => <Input {...a} inputMode="decimal" value={claim} onChange={(e) => setClaim(e.target.value)} placeholder="1,250,000.00" />}</Field>
            <Button type="submit" variant="tonal" icon={<Calculator className="size-4" />} loading={estimate.isPending}>Estimate</Button>
          </div>
          <FormError message={error && !error.field('claim_cents') ? (error.field('schedule') ?? error.message) : null} />
          {estimate.data && (
            <dl className="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 rounded-[3px] bg-surface-container p-4 text-sm" aria-live="polite">
              {estimate.data.lines.map((l) => <div key={l.label} className="contents"><dt>{l.label}</dt><dd className="text-right tabular-nums">{money(l.amount_cents)}</dd></div>)}
              <dt className="border-t border-outline-variant pt-1 font-semibold">Estimated total</dt><dd className="border-t border-outline-variant pt-1 text-right font-semibold tabular-nums">{money(estimate.data.total_cents)}</dd>
            </dl>
          )}
          <p className="text-xs text-on-surface-variant">
            From the firm's table ({table.data.schedule.court}), reviewed by {table.data.confirmed_by} on {date(table.data.confirmed_at)}. An estimate only: the clerk of court's assessment governs, and other fees (sheriff's, summons, other kinds of action) are not included.
          </p>
        </form>
      )}
    </Dialog>
  )
}

const pesos = (c?: number | null) => (c ? (c / 100).toFixed(2) : '')

/** The managing partner keeps the firm's filing fee table and vouches that it is current. */
export function FilingFeeTableCard() {
  const table = useFilingFeeTable()
  if (table.isPending || !table.data) return <Card><PageLoader /></Card>
  return <FilingFeeTableForm key={table.data.confirmed_at ?? 'new'} table={table.data} />
}

function FilingFeeTableForm({ table }: { table: FeeTable }) {
  const s = table.schedule
  const [court, setCourt] = useState(s.court)
  const [brackets, setBrackets] = useState(s.brackets.map((b) => ({ under: pesos(b.under_cents), fee: pesos(b.fee_cents) })))
  const [excess, setExcess] = useState({ from: pesos(s.excess?.from_cents), base: pesos(s.excess?.base_cents), per: pesos(s.excess?.per_thousand_cents) })
  const [extras, setExtras] = useState(s.extras.map((x) => ({ label: x.label, fixed: pesos(x.fixed_cents), percent: x.percent_bps ? String(x.percent_bps / 100) : '', minimum: pesos(x.minimum_cents) })))
  const [confirm, setConfirm] = useState(false)
  const save = useApiMutation((input: object) => put<FeeTable>('/v1/filing-fees', input), { invalidate: [['filing-fees']], success: 'Filing fee table saved and marked reviewed', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null

  const c = (v: string) => (v.trim() ? toCents(v) : null)
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({
      court: court.trim(),
      brackets: brackets.filter((b) => b.under.trim()).map((b) => ({ under_cents: c(b.under), fee_cents: c(b.fee) ?? 0 })),
      excess: excess.from.trim() ? { from_cents: c(excess.from), base_cents: c(excess.base) ?? 0, per_thousand_cents: c(excess.per) ?? 0 } : null,
      extras: extras.filter((x) => x.label.trim()).map((x) => ({ label: x.label.trim(), fixed_cents: x.percent.trim() ? null : c(x.fixed), percent_bps: x.percent.trim() ? Math.round(Number(x.percent) * 100) : null, minimum_cents: c(x.minimum) })),
      confirm,
    }, { onSuccess: () => setConfirm(false) })
  }

  return (
    <Card className="lg:col-span-2">
      <CardHeader
        title="Filing fee table (Rule 141)"
        description="Used to estimate the docket and lawful fees for money claims from a matter's Time & expenses tab. Fees change by Supreme Court resolution and OCA circular, so no estimate is given until a managing partner has checked this table against the current rules."
        actions={table.confirmed ? <Badge tone="success">Reviewed {date(table.confirmed_at)}{table.confirmed_by && ` by ${table.confirmed_by}`}</Badge> : <Badge tone="warning">Not reviewed: estimates are off</Badge>}
      />
      <form onSubmit={submit} className="flex flex-col gap-5 p-5 text-sm" noValidate>
        {!table.confirmed && <p className="rounded-[3px] bg-warning-container p-3 text-on-warning-container">The figures below are only a starting point to edit. They have not been checked against the current schedule; correct every amount before confirming.</p>}
        <Field label="Applies to" error={error?.field('court')}>{(a) => <Input {...a} value={court} onChange={(e) => setCourt(e.target.value)} />}</Field>

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 font-medium">Amount claimed → filing fee</legend>
          {brackets.map((b, i) => (
            <div key={i} className="flex items-center gap-2">
              <span className="w-24 text-on-surface-variant">Less than ₱</span>
              <Input aria-label={`Bracket ${i + 1}: less than`} inputMode="decimal" value={b.under} onChange={(e) => setBrackets(brackets.map((x, j) => (j === i ? { ...x, under: e.target.value } : x)))} className="w-40" />
              <span className="text-on-surface-variant">fee ₱</span>
              <Input aria-label={`Bracket ${i + 1}: fee`} inputMode="decimal" value={b.fee} onChange={(e) => setBrackets(brackets.map((x, j) => (j === i ? { ...x, fee: e.target.value } : x)))} className="w-32" />
              <IconButton label={`Remove bracket ${i + 1}`} onClick={() => setBrackets(brackets.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
            </div>
          ))}
          <div><Button type="button" size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => setBrackets([...brackets, { under: '', fee: '' }])}>Add a bracket</Button></div>
        </fieldset>

        <fieldset className="flex flex-wrap items-center gap-2">
          <legend className="mb-1 font-medium">Above the last bracket</legend>
          <span className="text-on-surface-variant">From ₱</span>
          <Input aria-label="Excess: from" inputMode="decimal" value={excess.from} onChange={(e) => setExcess({ ...excess, from: e.target.value })} className="w-40" />
          <span className="text-on-surface-variant">₱</span>
          <Input aria-label="Excess: base fee" inputMode="decimal" value={excess.base} onChange={(e) => setExcess({ ...excess, base: e.target.value })} className="w-28" />
          <span className="text-on-surface-variant">plus ₱</span>
          <Input aria-label="Excess: per thousand" inputMode="decimal" value={excess.per} onChange={(e) => setExcess({ ...excess, per: e.target.value })} className="w-24" />
          <span className="text-on-surface-variant">for each ₱1,000 or part above it</span>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 font-medium">Other fees</legend>
          {extras.map((x, i) => (
            <div key={i} className="flex flex-wrap items-center gap-2">
              <Input aria-label={`Other fee ${i + 1}: name`} value={x.label} onChange={(e) => setExtras(extras.map((y, j) => (j === i ? { ...y, label: e.target.value } : y)))} className="w-64" />
              <span className="text-on-surface-variant">₱</span>
              <Input aria-label={`Other fee ${i + 1}: fixed amount`} inputMode="decimal" value={x.fixed} disabled={!!x.percent.trim()} onChange={(e) => setExtras(extras.map((y, j) => (j === i ? { ...y, fixed: e.target.value } : y)))} className="w-28" />
              <span className="text-on-surface-variant">or</span>
              <Input aria-label={`Other fee ${i + 1}: percent of filing fee`} inputMode="decimal" value={x.percent} onChange={(e) => setExtras(extras.map((y, j) => (j === i ? { ...y, percent: e.target.value } : y)))} className="w-20" />
              <span className="text-on-surface-variant">% of the filing fee, at least ₱</span>
              <Input aria-label={`Other fee ${i + 1}: minimum`} inputMode="decimal" value={x.minimum} onChange={(e) => setExtras(extras.map((y, j) => (j === i ? { ...y, minimum: e.target.value } : y)))} className="w-24" />
              <IconButton label={`Remove other fee ${i + 1}`} onClick={() => setExtras(extras.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
            </div>
          ))}
          <div><Button type="button" size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => setExtras([...extras, { label: '', fixed: '', percent: '', minimum: '' }])}>Add a fee</Button></div>
        </fieldset>

        <Checkbox label="I have checked this table against the current Rule 141 and the Supreme Court and OCA issuances that amend it." checked={confirm} onChange={(e) => setConfirm(e.target.checked)} />
        <FormError message={error ? (error.field('confirm') ?? error.message) : null} />
        <div><Button type="submit" disabled={!confirm} loading={save.isPending}>Save and mark reviewed</Button></div>
      </form>
    </Card>
  )
}
