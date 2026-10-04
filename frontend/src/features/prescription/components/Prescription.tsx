import { AlarmClock, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { date, longDate, today } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, Table, Td, Th } from '@/shared/ui/Layout'
import {
  useDeletePrescription,
  useFirmPrescriptions,
  useInterruptPrescription,
  useMarkFiled,
  useMatterPrescriptions,
  usePrescriptionPeriods,
  usePrescriptionPreview,
  useSavePrescription,
  type Prescription,
  type PrescriptionState,
} from '../api'

const STATE: Record<PrescriptionState, { label: string; tone: 'neutral' | 'primary' | 'warning' | 'danger' | 'success' }> = {
  running: { label: 'Running', tone: 'neutral' },
  soon: { label: 'Within 6 months', tone: 'primary' },
  urgent: { label: 'Within 30 days', tone: 'warning' },
  prescribed: { label: 'Prescribed', tone: 'danger' },
  filed: { label: 'Filed', tone: 'success' },
}

function countdown(p: Prescription): string {
  if (p.days_left === null) return p.filed_on ? `Filed ${date(p.filed_on)}` : 'Filed'
  if (p.days_left < 0) return `Prescribed ${Math.abs(p.days_left)} day${p.days_left === -1 ? '' : 's'} ago`
  if (p.days_left === 0) return 'Prescribes today'
  return `${p.days_left.toLocaleString()} day${p.days_left === 1 ? '' : 's'} left`
}

function period(p: { years: number; months: number }): string {
  return [p.years && `${p.years} year${p.years === 1 ? '' : 's'}`, p.months && `${p.months} month${p.months === 1 ? '' : 's'}`].filter(Boolean).join(' and ')
}

/** On a matter's Deadlines tab: each cause of action and when it prescribes. */
export function PrescriptionCard({ matterId }: { matterId: number }) {
  const abilities = useAbilities()
  const query = useMatterPrescriptions(matterId)
  const [editing, setEditing] = useState<Prescription | 'new' | null>(null)
  const [interrupting, setInterrupting] = useState<Prescription | null>(null)
  const [removing, setRemoving] = useState<Prescription | null>(null)
  const filed = useMarkFiled()
  const remove = useDeletePrescription()
  const canEdit = abilities.work_matters

  return (
    <Card className="mb-6">
      <CardHeader
        title="Prescription"
        description="When each cause of action prescribes. Reminders start six months before the last day."
        actions={canEdit && <Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add a cause of action</Button>}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} /> : query.data.length === 0 ? (
        <EmptyState icon={<AlarmClock className="size-6" />} title="No prescription tracked" description="Record when the cause of action arose and the type of action; the last day to file is worked out for you." />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {query.data.map((p) => (
            <li key={p.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start">
              <div className="min-w-0 flex-1 text-sm">
                <p className="flex flex-wrap items-center gap-2 font-medium">
                  {p.label}
                  <Badge tone={STATE[p.state].tone}>{STATE[p.state].label}</Badge>
                </p>
                {p.state !== 'filed' && (
                  <p className="mt-1 text-base">
                    Last day <strong>{longDate(p.last_day)}</strong>
                    <span className={`ml-2 text-sm ${p.state === 'prescribed' ? 'text-danger' : p.state === 'urgent' ? 'font-medium text-warning' : 'text-on-surface-variant'}`}>{countdown(p)}</span>
                  </p>
                )}
                {p.state !== 'filed' && p.file_by !== p.last_day && (
                  <p className="text-xs text-on-surface-variant">Falls on a non-working day; Rule 22 moves it to {longDate(p.file_by)}. Aim for the earlier date.</p>
                )}
                <p className="mt-1 text-on-surface-variant">
                  {period(p)} from {date(p.runs_from)}{p.runs_from !== p.accrued_on ? ` (restarted; arose ${date(p.accrued_on)})` : ''}{p.basis && ` · ${p.basis}`}
                </p>
                {p.runs_from_hint && <p className="text-xs text-on-surface-variant">Runs from {p.runs_from_hint}.</p>}
                {p.interruptions.map((i) => (
                  <p key={`${i.date}-${i.kind}`} className="text-xs text-on-surface-variant">Interrupted {date(i.date)}: {i.kind_label}{i.note && ` (${i.note})`}</p>
                ))}
                {p.notes && <p className="mt-1 whitespace-pre-line">{p.notes}</p>}
                {p.state === 'filed' && <p className="mt-1 text-on-surface-variant">{countdown(p)}. Prescription no longer runs.</p>}
              </div>
              {canEdit && (
                <div className="flex shrink-0 flex-wrap gap-1">
                  {p.state !== 'filed' ? (
                    <>
                      <Button size="sm" variant="tonal" loading={filed.isPending && filed.variables?.id === p.id} onClick={() => filed.mutate({ id: p.id, filed_on: today() })}>Filed today</Button>
                      {p.interruptible && <Button size="sm" variant="text" onClick={() => setInterrupting(p)}>Interrupted…</Button>}
                    </>
                  ) : (
                    <Button size="sm" variant="text" onClick={() => filed.mutate({ id: p.id, reopen: true })}>Running again</Button>
                  )}
                  <IconButton label={`Edit ${p.label}`} onClick={() => setEditing(p)}><Pencil className="size-4" /></IconButton>
                  <IconButton label={`Remove ${p.label}`} onClick={() => setRemoving(p)}><Trash2 className="size-4" /></IconButton>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      <p className="border-t border-outline-variant px-5 py-3 text-xs text-on-surface-variant">
        Years and months are calendar years and months (Administrative Code, Book I, Sec. 31). Check each period against the facts and current law: special laws, when the cause truly accrued, suspension and interruption all change the answer.
      </p>

      {editing && <PrescriptionDialog matterId={matterId} prescription={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {interrupting && <InterruptDialog prescription={interrupting} onClose={() => setInterrupting(null)} />}
      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        title="Stop tracking this?"
        description={removing ? `${removing.label}: its reminders stop.` : ''}
        destructive
        confirmLabel="Remove"
        loading={remove.isPending}
        onConfirm={() => removing && remove.mutate(removing.id, { onSuccess: () => setRemoving(null) })}
      />
    </Card>
  )
}

function PrescriptionDialog({ matterId, prescription, onClose }: { matterId: number; prescription: Prescription | null; onClose: () => void }) {
  const periods = usePrescriptionPeriods()
  const save = useSavePrescription(matterId, prescription?.id)
  const [key, setKey] = useState(prescription ? (prescription.period_key ?? 'custom') : '')
  const [accrued, setAccrued] = useState(prescription?.accrued_on ?? '')
  const [label, setLabel] = useState(prescription?.period_key ? '' : (prescription?.label ?? ''))
  const [years, setYears] = useState(String(prescription && !prescription.period_key ? prescription.years : ''))
  const [months, setMonths] = useState(String(prescription && !prescription.period_key ? prescription.months : ''))
  const [basis, setBasis] = useState(prescription?.period_key ? '' : (prescription?.basis ?? ''))
  const [notes, setNotes] = useState(prescription?.notes ?? '')
  const error = save.error ? ApiError.from(save.error) : null

  const chosen = periods.data?.groups.flatMap((g) => g.periods).find((p) => p.key === key)
  const custom = key === 'custom'
  const y = chosen ? chosen.years : Number(years) || 0
  const m = chosen ? chosen.months : Number(months) || 0
  // Preview from the latest restart when editing an interrupted period.
  const from = prescription && prescription.runs_from !== prescription.accrued_on && accrued === prescription.accrued_on ? prescription.runs_from : accrued
  const preview = usePrescriptionPreview(from, y, m)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(
      custom
        ? { period_key: null, label: label.trim(), years: Number(years) || 0, months: Number(months) || 0, basis: basis.trim() || null, accrued_on: accrued, notes: notes.trim() || null }
        : { period_key: key, label: null, years: null, months: null, basis: null, accrued_on: accrued, notes: notes.trim() || null },
      { onSuccess: onClose },
    )
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={prescription ? 'Edit cause of action' : 'Add a cause of action'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="prescription-form" loading={save.isPending} disabled={!key}>Save</Button></>}
    >
      <form id="prescription-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        <Field label="Type of action" required error={error?.field('period_key')}>
          {(a) => (
            <Select {...a} required value={key} onChange={(e) => setKey(e.target.value)}>
              <option value="">Choose…</option>
              {periods.data?.groups.map((g) => (
                <optgroup key={g.group} label={g.label}>
                  {g.periods.map((p) => <option key={p.key} value={p.key}>{p.label} ({period(p)})</option>)}
                </optgroup>
              ))}
              <option value="custom">Another period…</option>
            </Select>
          )}
        </Field>
        {chosen && <p className="-mt-2 text-xs text-on-surface-variant">{chosen.basis}. Runs from {chosen.runs_from}.</p>}
        {custom && (
          <>
            <Field label="Cause of action" required error={error?.field('label')}>{(a) => <Input {...a} required maxLength={255} value={label} onChange={(e) => setLabel(e.target.value)} />}</Field>
            <div className="grid grid-cols-2 gap-4">
              <Field label="Years" error={error?.field('years')}>{(a) => <Input {...a} type="number" min={0} max={100} value={years} onChange={(e) => setYears(e.target.value)} />}</Field>
              <Field label="Months" error={error?.field('months')}>{(a) => <Input {...a} type="number" min={0} max={1200} value={months} onChange={(e) => setMonths(e.target.value)} />}</Field>
            </div>
            <Field label="Legal basis" error={error?.field('basis')}>{(a) => <Input {...a} maxLength={500} value={basis} onChange={(e) => setBasis(e.target.value)} placeholder="e.g. Insurance Code, Sec. 63" />}</Field>
          </>
        )}
        <Field label="The cause of action arose on" required error={error?.field('accrued_on')}>
          {(a) => <Input {...a} type="date" required max={today()} value={accrued} onChange={(e) => setAccrued(e.target.value)} />}
        </Field>
        {preview.data && (
          <p role="status" className="rounded-[3px] bg-surface-container p-3 text-sm">
            Last day: <strong>{longDate(preview.data.last_day)}</strong>
            {preview.data.file_by !== preview.data.last_day && <> ({preview.data.adjustments.map((x) => x.reason).join(', ')}; Rule 22 moves it to {longDate(preview.data.file_by)})</>}
          </p>
        )}
        <Field label="Notes" error={error?.field('notes')}>{(a) => <Textarea {...a} rows={2} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Why this date, e.g. date of the demand letter's receipt" />}</Field>
      </form>
    </Dialog>
  )
}

function InterruptDialog({ prescription, onClose }: { prescription: Prescription; onClose: () => void }) {
  const periods = usePrescriptionPeriods()
  const interrupt = useInterruptPrescription()
  const [form, setForm] = useState({ date: today(), kind: 'demand', note: '' })
  const error = interrupt.error ? ApiError.from(interrupt.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    interrupt.mutate({ id: prescription.id, date: form.date, kind: form.kind, note: form.note.trim() || null }, { onSuccess: onClose })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Prescription interrupted"
      description="Under Art. 1155 of the Civil Code, filing in court, a written extrajudicial demand, or a written acknowledgment of the debt interrupts prescription; the full period starts again from that day."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="interrupt-form" loading={interrupt.isPending}>Save</Button></>}
    >
      <form id="interrupt-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : (error?.field('kind') ?? null)} />
        <Field label="What happened">
          {(a) => (
            <Select {...a} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
              {periods.data?.interruptions.map((i) => <option key={i.value} value={i.value}>{i.label}</option>)}
            </Select>
          )}
        </Field>
        <Field label="On" error={error?.field('date')}>{(a) => <Input {...a} type="date" required max={today()} min={prescription.accrued_on} value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />}</Field>
        <Field label="Note">{(a) => <Input {...a} maxLength={500} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} placeholder="e.g. demand letter received by registered mail" />}</Field>
      </form>
    </Dialog>
  )
}

/** Firm-wide: causes of action on open matters, soonest to prescribe first. */
export function PrescriptionsPage() {
  const [within, setWithin] = useUrlState('within', '365')
  const query = useFirmPrescriptions(Number(within) || 365)

  return (
    <>
      <PageHeader title="Prescription" description="Causes of action still running on open matters, soonest to prescribe first. Record filing or interruptions on each matter's Deadlines tab." />
      <Card>
        <CardHeader
          title="Prescribing"
          actions={
            <div className="w-48">
              <Select aria-label="Show" value={within} onChange={(e) => setWithin(e.target.value)}>
                <option value="90">Within 3 months</option>
                <option value="180">Within 6 months</option>
                <option value="365">Within a year</option>
                <option value="3650">Within 10 years</option>
              </Select>
            </div>
          }
        />
        {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} /> : query.data.length === 0 ? (
          <EmptyState icon={<AlarmClock className="size-6" />} title="Nothing prescribing in this window" />
        ) : (
          <Table caption="Prescribing causes of action" compact>
            <thead><tr><Th>Last day</Th><Th>Matter</Th><Th>Cause of action</Th><Th>Lawyer</Th><Th align="right">Left</Th></tr></thead>
            <tbody>
              {query.data.map((p) => (
                <tr key={p.id}>
                  <Td className="whitespace-nowrap">
                    {date(p.last_day)}
                    {p.file_by !== p.last_day && <div className="text-xs text-on-surface-variant">Rule 22: {date(p.file_by)}</div>}
                  </Td>
                  <Td>{p.matter && <Link to={`/matters/${p.matter.id}?tab=deadlines`} className="font-medium text-primary hover:underline">{p.matter.reference}</Link>}<div className="text-xs text-on-surface-variant">{p.matter?.title}</div></Td>
                  <Td>{p.label}<div className="text-xs text-on-surface-variant">{p.basis}</div></Td>
                  <Td>{p.matter?.lawyer ?? '—'}</Td>
                  <Td align="right"><Badge tone={STATE[p.state].tone}>{countdown(p)}</Badge></Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  )
}
