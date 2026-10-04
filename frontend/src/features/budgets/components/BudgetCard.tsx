import { AlertTriangle, Gauge, Trash2, X } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useAbilities, useLookups } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { dateTime, duration, money, toCents } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useMatterBudget, useRemoveBudget, useSaveBudget, type BudgetBasis, type BudgetUsage, type MatterBudget } from '../api'

const fmt = (basis: BudgetBasis, n: number) => (basis === 'hours' ? duration(n) : money(n))
const tone = (percent: number) => (percent >= 100 ? 'danger' : percent >= 80 ? 'warning' : 'primary')

/**
 * A matter's budget against the time and expenses logged, overall and by
 * stage. The responsible lawyer and managing partners are alerted at 80%
 * and 100%.
 */
export function BudgetCard({ matterId }: { matterId: number }) {
  const abilities = useAbilities()
  const query = useMatterBudget(matterId)
  const remove = useRemoveBudget(matterId)
  const [editing, setEditing] = useState(false)
  const canSet = abilities.practice_law || abilities.manage_finances

  const data = query.data
  return (
    <Card className="lg:col-span-2">
      <CardHeader
        title="Budget"
        description={data?.budget ? budgetDescription(data.budget) : 'What the client expects this matter to cost, tracked as time and expenses are logged.'}
        actions={canSet && (
          <div className="flex gap-1">
            {data?.budget && <IconButton label="Remove budget" onClick={() => remove.mutate()}><Trash2 className="size-4" /></IconButton>}
            <Button variant="tonal" size="sm" icon={<Gauge className="size-4" />} onClick={() => setEditing(true)}>{data?.budget ? 'Edit budget' : 'Set a budget'}</Button>
          </div>
        )}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} /> : !data?.budget || !data.usage ? (
        <EmptyState icon={<Gauge className="size-6" />} title="No budget" description="Set one to be warned at 80% and 100%, overall and stage by stage." />
      ) : (
        <Usage budget={data.budget} usage={data.usage} />
      )}
      {editing && <BudgetDialog matterId={matterId} budget={data?.budget ?? null} onClose={() => setEditing(false)} />}
    </Card>
  )
}

function budgetDescription(b: MatterBudget): string {
  const what = b.basis === 'hours' ? 'Billable hours' : b.include_expenses ? 'Billable fees and expenses' : 'Billable fees'
  return [what, b.shared_with_client ? 'shown to the client' : null, b.updated_by && `set by ${b.updated_by}`, b.updated_at && dateTime(b.updated_at)].filter(Boolean).join(' · ')
}

function Usage({ budget, usage }: { budget: MatterBudget; usage: BudgetUsage }) {
  const left = usage.total - usage.used
  return (
    <div className="flex flex-col gap-5 p-5">
      <div>
        <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
          <p className="text-2xl font-semibold tabular-nums">
            {fmt(budget.basis, usage.used)} <span className="text-base font-normal text-on-surface-variant">of {fmt(budget.basis, usage.total)}</span>
          </p>
          <p className="flex items-center gap-2 text-sm">
            {usage.percent >= 100 ? <Badge tone="danger"><AlertTriangle className="size-3" aria-hidden /> Over by {fmt(budget.basis, -left)}</Badge>
              : usage.percent >= 80 ? <Badge tone="warning">{fmt(budget.basis, left)} left</Badge>
                : <span className="text-on-surface-variant">{fmt(budget.basis, left)} left</span>}
            <span className="font-medium tabular-nums">{usage.percent}%</span>
          </p>
        </div>
        <ProgressBar value={usage.percent} label="Budget used" tone={tone(usage.percent)} />
        {budget.basis === 'amount' && (
          <p className="mt-2 text-xs text-on-surface-variant">
            Fees {money(usage.fees_cents)}{budget.include_expenses && ` · expenses ${money(usage.expenses_cents)}`} · {duration(usage.minutes)} billable
          </p>
        )}
      </div>

      {usage.by_stage.length > 0 && (
        <div>
          <h3 className="mb-2 text-sm font-medium">By stage</h3>
          <ul className="flex flex-col gap-3">
            {usage.by_stage.map((s) => (
              <li key={s.stage} className="grid grid-cols-[8rem_1fr] items-center gap-3 text-sm sm:grid-cols-[9rem_1fr_11rem]">
                <span>{s.label}</span>
                {s.total ? <ProgressBar value={s.percent ?? 0} label={`${s.label} budget used`} tone={tone(s.percent ?? 0)} /> : <span className="text-xs text-on-surface-variant">No amount set for this stage</span>}
                <span className="col-span-2 text-right text-on-surface-variant tabular-nums sm:col-span-1">
                  {fmt(budget.basis, s.used)}{s.total ? ` of ${fmt(budget.basis, s.total)}` : ''}
                </span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-xs text-on-surface-variant">Time and expenses count toward the stage the matter was in on the day of the work.</p>
        </div>
      )}
      {budget.notes && <p className="rounded-[3px] bg-surface-container p-3 text-sm whitespace-pre-line">{budget.notes}</p>}
    </div>
  )
}

/** Hours ("12.5") or pesos ("150,000") as typed, to minutes or centavos. */
const parse = (basis: BudgetBasis, text: string) => (basis === 'hours' ? Math.round(Number(text.replace(/,/g, '')) * 60) : toCents(text))
const show = (basis: BudgetBasis, n: number) => (basis === 'hours' ? String(Math.round((n / 60) * 100) / 100) : String(n / 100))

function BudgetDialog({ matterId, budget, onClose }: { matterId: number; budget: MatterBudget | null; onClose: () => void }) {
  const lookups = useLookups()
  const save = useSaveBudget(matterId)
  const [basis, setBasis] = useState<BudgetBasis>(budget?.basis ?? 'amount')
  const [total, setTotal] = useState(budget ? show(budget.basis, budget.total) : '')
  const [includeExpenses, setIncludeExpenses] = useState(budget?.include_expenses ?? true)
  const [stages, setStages] = useState(budget?.stages.map((s) => ({ stage: s.stage, total: show(budget.basis, s.total) })) ?? [])
  const [shared, setShared] = useState(budget?.shared_with_client ?? false)
  const [notes, setNotes] = useState(budget?.notes ?? '')
  const error = save.error ? ApiError.from(save.error) : null

  const stageOptions = (lookups.data?.matter_statuses ?? []).filter((s) => s.value !== 'closed')
  const unused = stageOptions.filter((o) => !stages.some((s) => s.stage === o.value))
  const stageSum = stages.reduce((sum, s) => sum + (parse(basis, s.total) || 0), 0)
  const totalValue = parse(basis, total)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(
      {
        basis,
        total: Number.isFinite(totalValue) ? totalValue : 0,
        include_expenses: basis === 'amount' && includeExpenses,
        stages: stages.filter((s) => s.total.trim()).map((s) => ({ stage: s.stage, total: parse(basis, s.total) })),
        shared_with_client: shared,
        notes: notes.trim() || null,
      },
      { onSuccess: onClose },
    )
  }

  const unit = basis === 'hours' ? 'hours' : '₱'
  return (
    <Dialog
      open
      onClose={onClose}
      title={budget ? 'Edit budget' : 'Set a budget'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="budget-form" loading={save.isPending}>Save</Button></>}
    >
      <form id="budget-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : error?.field('stages')} />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Measured in">
            {(a) => (
              <Select {...a} value={basis} onChange={(e) => { setBasis(e.target.value as BudgetBasis); setTotal(''); setStages([]) }}>
                <option value="amount">Pesos</option>
                <option value="hours">Hours</option>
              </Select>
            )}
          </Field>
          <Field label={basis === 'hours' ? 'Budget (hours)' : 'Budget (₱)'} required error={error?.field('total')}>
            {(a) => <Input {...a} inputMode="decimal" required value={total} onChange={(e) => setTotal(e.target.value)} placeholder={basis === 'hours' ? '40' : '150,000'} />}
          </Field>
        </div>
        {basis === 'amount' && <Checkbox label="Include billable expenses (filing fees, transcripts, travel)" checked={includeExpenses} onChange={(e) => setIncludeExpenses(e.target.checked)} />}

        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">By stage <span className="font-normal text-on-surface-variant">(optional)</span></legend>
          {stages.map((s, i) => (
            <div key={s.stage} className="flex items-end gap-2">
              <span className="w-32 shrink-0 pb-2 text-sm">{stageOptions.find((o) => o.value === s.stage)?.label ?? s.stage}</span>
              <div className="min-w-0 flex-1">
                <Input aria-label={`${stageOptions.find((o) => o.value === s.stage)?.label ?? s.stage} (${unit})`} inputMode="decimal" value={s.total} onChange={(e) => setStages(stages.map((x, j) => (j === i ? { ...x, total: e.target.value } : x)))} />
              </div>
              <IconButton label="Remove stage" onClick={() => setStages(stages.filter((_, j) => j !== i))}><X className="size-4" /></IconButton>
            </div>
          ))}
          {unused.length > 0 && (
            <div className="w-56">
              <Select aria-label="Add a stage" value="" onChange={(e) => e.target.value && setStages([...stages, { stage: e.target.value, total: '' }])}>
                <option value="">Add a stage…</option>
                {unused.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            </div>
          )}
          {stages.length > 0 && Number.isFinite(totalValue) && totalValue > 0 && (
            <p className={`text-xs ${stageSum > totalValue ? 'text-danger' : 'text-on-surface-variant'}`}>
              Stages total {fmt(basis, stageSum)} of {fmt(basis, totalValue)}
            </p>
          )}
        </fieldset>

        <Checkbox label="Show the client how much of the budget is used (in the portal)" checked={shared} onChange={(e) => setShared(e.target.checked)} />
        <Field label="Notes" hint="For the firm only; the client never sees them." error={error?.field('notes')}>
          {(a) => <Textarea {...a} rows={2} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Assumptions, e.g. up to 3 pre-trial settings" />}
        </Field>
      </form>
    </Dialog>
  )
}
