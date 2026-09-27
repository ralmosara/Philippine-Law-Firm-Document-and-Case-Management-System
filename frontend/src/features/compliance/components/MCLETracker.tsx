import { GraduationCap, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError } from '@/shared/api/axios'
import { date, today } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader, StatCard, Table, Td, Th } from '@/shared/ui/Layout'
import type { McleCredit } from '@/shared/api/types'
import { useAddMcleCredit, useDeleteMcleCredit, useMclePeriods, useMcleStatus } from '../api'

/** The signed-in lawyer's MCLE credits for the current compliance period. */
export function MCLETracker() {
  const periods = useMclePeriods()
  const status = useMcleStatus()
  const remove = useDeleteMcleCredit()
  const [adding, setAdding] = useState(false)
  const [deleting, setDeleting] = useState<McleCredit | null>(null)

  if (status.isPending || periods.isPending) return <PageLoader />
  if (status.isError) return <ErrorState error={status.error} />
  const s = status.data.status

  if (!s) {
    return <EmptyState icon={<GraduationCap className="size-6" />} title="No active compliance period" description="Your managing partner can add the current MCLE compliance period." />
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Units earned" value={`${s.earned_units} / ${s.required_units}`} />
        <StatCard label="Remaining" value={s.remaining_units} tone={s.is_compliant ? undefined : 'warning'} />
        <StatCard label="Period ends" value={date(s.period_end)} detail={s.period_name} />
      </div>

      <Card>
        <div className="p-5">
          <div className="mb-2 flex items-center justify-between text-sm">
            <span className="font-medium">{s.is_compliant ? 'Compliant for this period' : 'Progress toward compliance'}</span>
            <span className="tabular-nums text-on-surface-variant">{s.percent}%</span>
          </div>
          <ProgressBar value={s.percent} label="MCLE compliance" tone={s.is_compliant ? 'success' : 'primary'} />
        </div>
      </Card>

      <Card>
        <CardHeader title="Credits" actions={<Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>Add credit</Button>} />
        {status.data.credits.length === 0 ? (
          <EmptyState title="No credits recorded for this period" />
        ) : (
          <Table caption="MCLE credits">
            <thead><tr><Th>Activity</Th><Th>Subject area</Th><Th>Provider</Th><Th>Date</Th><Th align="right">Units</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
            <tbody>
              {status.data.credits.map((c) => (
                <tr key={c.id}>
                  <Td className="font-medium">{c.title}{c.certificate_number && <div className="text-xs font-normal text-on-surface-variant">Cert. {c.certificate_number}</div>}</Td>
                  <Td>{c.subject_area ?? '—'}</Td>
                  <Td className="text-on-surface-variant">{c.provider ?? '—'}</Td>
                  <Td className="whitespace-nowrap">{date(c.date_earned)}</Td>
                  <Td align="right">{Number(c.units)}</Td>
                  <Td align="right"><IconButton label={`Remove ${c.title}`} onClick={() => setDeleting(c)}><Trash2 className="size-4" /></IconButton></Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {adding && <CreditDialog periodId={s.period_id} onClose={() => setAdding(false)} />}
      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Remove this credit?"
        description={deleting?.title}
        destructive
        confirmLabel="Remove"
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
      />
    </div>
  )
}

function CreditDialog({ periodId, onClose }: { periodId: number; onClose: () => void }) {
  const add = useAddMcleCredit()
  const [form, setForm] = useState({ title: '', subject_area: '', provider: '', units: '', date_earned: today(), certificate_number: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await add.mutateAsync({ ...form, period_id: periodId, units: Number(form.units) })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title="Add MCLE credit" footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="mcle-form" loading={add.isPending}>Add credit</Button></>}>
      <form id="mcle-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>
        <Field label="Activity" required className="sm:col-span-2" error={error?.field('title')}>
          {(a) => <Input {...a} required value={form.title} onChange={set('title')} placeholder="Legal Ethics Seminar" />}
        </Field>
        <Field label="Subject area" error={error?.field('subject_area')}>
          {(a) => <Input {...a} value={form.subject_area} onChange={set('subject_area')} placeholder="Legal Ethics" />}
        </Field>
        <Field label="Provider" error={error?.field('provider')}>
          {(a) => <Input {...a} value={form.provider} onChange={set('provider')} placeholder="IBP Makati Chapter" />}
        </Field>
        <Field label="Units" required error={error?.field('units')}>
          {(a) => <Input {...a} required type="number" min={0.25} max={36} step={0.25} value={form.units} onChange={set('units')} />}
        </Field>
        <Field label="Date earned" required error={error?.field('date_earned')}>
          {(a) => <Input {...a} required type="date" max={today()} value={form.date_earned} onChange={set('date_earned')} />}
        </Field>
        <Field label="Certificate number" className="sm:col-span-2">
          {(a) => <Input {...a} value={form.certificate_number} onChange={set('certificate_number')} />}
        </Field>
      </form>
    </Dialog>
  )
}
