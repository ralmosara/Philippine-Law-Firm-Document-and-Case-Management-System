import { useState, type FormEvent } from 'react'
import { useLawyerOptions } from '@/features/users/api'
import { ApiError } from '@/shared/api/axios'
import { date, today } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { MONTHS, useAddObligation, useSaveCorporateProfile, useUpdateObligation, type CorporateObligation, type CorporateProfile, type ProfileInput } from '../api'

export function ObligationStatus({ o }: { o: CorporateObligation }) {
  if (o.status === 'done') return <Badge tone="success">Done {date(o.done_on)}</Badge>
  if (o.status === 'not_applicable') return <Badge>Not applicable</Badge>
  if (o.is_overdue) return <Badge tone="danger">Overdue</Badge>
  if (o.due_on <= today()) return <Badge tone="warning">Due today</Badge>
  return <Badge tone="primary">Pending</Badge>
}

/** A month and a day, stored as "MM-DD". */
function MonthDayInput({ value, onChange, optional, id }: { value: string | null; onChange: (v: string | null) => void; optional?: boolean; id?: string }) {
  const [m, d] = value ? value.split('-') : ['', '']
  const set = (month: string, day: string) => onChange(month && day ? `${month}-${day}` : null)
  return (
    <div className="flex gap-1.5">
      <Select id={id} aria-label="Month" value={m} onChange={(e) => set(e.target.value, d || '01')} className="flex-1">
        {optional && <option value="">Not set</option>}
        {MONTHS.map((name, i) => (
          <option key={name} value={String(i + 1).padStart(2, '0')}>
            {name}
          </option>
        ))}
      </Select>
      <Select aria-label="Day" value={d} onChange={(e) => set(m || '01', e.target.value)} className="w-20" disabled={!m}>
        {!m && <option value="" />}
        {Array.from({ length: 31 }, (_, i) => String(i + 1).padStart(2, '0')).map((day) => (
          <option key={day} value={day}>
            {Number(day)}
          </option>
        ))}
      </Select>
    </div>
  )
}

export function ProfileDialog({ clientId, clientName, profile, onClose }: { clientId: number; clientName: string; profile: CorporateProfile | null; onClose: () => void }) {
  const lawyers = useLawyerOptions()
  const save = useSaveCorporateProfile(clientId)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState<ProfileInput>({
    sec_registration_no: profile?.sec_registration_no ?? '',
    incorporated_on: profile?.incorporated_on ?? '',
    fiscal_year_end: profile?.fiscal_year_end ?? '12-31',
    annual_meeting_date: profile?.annual_meeting_date ?? null,
    principal_office: profile?.principal_office ?? '',
    corporate_secretary: profile?.corporate_secretary ?? '',
    responsible_lawyer_id: profile?.responsible_lawyer_id ?? null,
    notes: profile?.notes ?? '',
  })
  const set = <K extends keyof ProfileInput>(key: K, value: ProfileInput[K]) => setForm({ ...form, [key]: value })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ ...form, incorporated_on: form.incorporated_on || null }, { onSuccess: onClose })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      size="lg"
      title={profile ? 'Corporate profile' : 'Add corporate profile'}
      description={clientName}
      footer={
        <>
          <Button variant="text" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="corp-profile" loading={save.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form id="corp-profile" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="SEC registration no." error={error?.field('sec_registration_no')}>
          {(a) => <Input {...a} value={form.sec_registration_no ?? ''} onChange={(e) => set('sec_registration_no', e.target.value)} />}
        </Field>
        <Field label="Incorporated on" error={error?.field('incorporated_on')}>
          {(a) => <Input {...a} type="date" max={today()} value={form.incorporated_on ?? ''} onChange={(e) => set('incorporated_on', e.target.value)} />}
        </Field>
        <Field label="Fiscal year ends" hint="The AFS and the annual ITR are counted from this." error={error?.field('fiscal_year_end')}>
          {(a) => <MonthDayInput id={a.id} value={form.fiscal_year_end} onChange={(v) => set('fiscal_year_end', v ?? '12-31')} />}
        </Field>
        <Field label="Annual stockholders' meeting" hint="The date fixed in the by-laws; the GIS is due 30 days after." error={error?.field('annual_meeting_date')}>
          {(a) => <MonthDayInput id={a.id} optional value={form.annual_meeting_date} onChange={(v) => set('annual_meeting_date', v)} />}
        </Field>
        <Field label="Principal office" className="sm:col-span-2" error={error?.field('principal_office')}>
          {(a) => <Input {...a} value={form.principal_office ?? ''} onChange={(e) => set('principal_office', e.target.value)} />}
        </Field>
        <Field label="Corporate secretary" error={error?.field('corporate_secretary')}>
          {(a) => <Input {...a} value={form.corporate_secretary ?? ''} onChange={(e) => set('corporate_secretary', e.target.value)} />}
        </Field>
        <Field label="Responsible lawyer" hint="Gets the reminders." error={error?.field('responsible_lawyer_id')}>
          {(a) => (
            <Select {...a} value={form.responsible_lawyer_id ?? ''} onChange={(e) => set('responsible_lawyer_id', e.target.value ? Number(e.target.value) : null)}>
              <option value="">No one (no reminders)</option>
              {lawyers.data?.map((l) => (
                <option key={l.id} value={l.id}>
                  {l.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label="Notes" className="sm:col-span-2">
          {(a) => <Textarea {...a} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />}
        </Field>
        {profile && <p className="text-xs text-on-surface-variant sm:col-span-2">Changing the dates regenerates this year's pending obligations; ones already done stay as recorded.</p>}
        <div className="sm:col-span-2">
          <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        </div>
      </form>
    </Dialog>
  )
}

export function ObligationDialog({ obligation, onClose }: { obligation: CorporateObligation; onClose: () => void }) {
  const [status, setStatus] = useState<CorporateObligation['status']>(obligation.status === 'pending' ? 'done' : obligation.status)
  const [doneOn, setDoneOn] = useState(obligation.done_on ?? today())
  const [reference, setReference] = useState(obligation.reference ?? '')
  const [dueOn, setDueOn] = useState(obligation.due_on)
  const [notes, setNotes] = useState(obligation.notes ?? '')
  const save = useUpdateObligation(obligation.id)
  const error = save.error ? ApiError.from(save.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(
      {
        status,
        done_on: status === 'done' ? doneOn : null,
        reference: reference || null,
        due_on: dueOn,
        notes: notes || null,
      },
      { onSuccess: onClose },
    )
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={obligation.title}
      description={obligation.client_name ?? undefined}
      footer={
        <>
          <Button variant="text" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="obligation-form" loading={save.isPending}>
            Save
          </Button>
        </>
      }
    >
      <form id="obligation-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="Status">
          {(a) => (
            <Select {...a} value={status} onChange={(e) => setStatus(e.target.value as CorporateObligation['status'])}>
              <option value="done">{obligation.kind === 'annual_meeting' ? 'Held' : 'Filed / done'}</option>
              <option value="pending">Pending</option>
              <option value="not_applicable">Not applicable this year</option>
            </Select>
          )}
        </Field>
        {status === 'done' && (
          <Field label="Date" error={error?.field('done_on')}>
            {(a) => <Input {...a} type="date" max={today()} value={doneOn} onChange={(e) => setDoneOn(e.target.value)} />}
          </Field>
        )}
        <Field label="Reference" className="sm:col-span-2">
          {(a) => <Input {...a} value={reference} onChange={(e) => setReference(e.target.value)} placeholder="SEC eFAST / BIR confirmation no." />}
        </Field>
        <Field label="Due date" hint="Adjust to the SEC's coded schedule for AFS where it applies." error={error?.field('due_on')}>
          {(a) => <Input {...a} type="date" value={dueOn} onChange={(e) => setDueOn(e.target.value)} />}
        </Field>
        <Field label="Notes" className="sm:col-span-2">
          {(a) => <Textarea {...a} value={notes} onChange={(e) => setNotes(e.target.value)} />}
        </Field>
        <div className="sm:col-span-2">
          <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        </div>
      </form>
    </Dialog>
  )
}

export function AddObligationDialog({ clientId, onClose }: { clientId: number; onClose: () => void }) {
  const [title, setTitle] = useState('')
  const [dueOn, setDueOn] = useState(today())
  const add = useAddObligation(clientId)
  const error = add.error ? ApiError.from(add.error) : null

  return (
    <Dialog
      open
      onClose={onClose}
      title="Add an obligation"
      description="Anything else the company must do by a date: permit renewals, amendments, special filings."
      footer={
        <>
          <Button variant="text" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="add-obligation" loading={add.isPending}>
            Add
          </Button>
        </>
      }
    >
      <form
        id="add-obligation"
        onSubmit={(e) => {
          e.preventDefault()
          add.mutate({ title, due_on: dueOn }, { onSuccess: onClose })
        }}
        className="grid grid-cols-1 gap-3"
      >
        <Field label="What" error={error?.field('title')} required>
          {(a) => <Input {...a} required value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Renew mayor's permit" />}
        </Field>
        <Field label="Due" error={error?.field('due_on')} required>
          {(a) => <Input {...a} type="date" required value={dueOn} onChange={(e) => setDueOn(e.target.value)} />}
        </Field>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </form>
    </Dialog>
  )
}
