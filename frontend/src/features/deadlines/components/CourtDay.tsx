import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Clock, Gavel, MapPin, Phone, Plus, Trash2, Users } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { isoDate, longDate, parseDate, today } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Textarea } from '@/shared/ui/Form'
import { Card, PageHeader } from '@/shared/ui/Layout'

interface Hearing {
  id: number
  title: string
  time: string | null
  location: string | null
  notes: string | null
  status: 'pending' | 'completed' | 'missed' | 'cancelled'
  assignee: string | null
  matter: {
    id: number
    reference: string
    title: string
    case_number: string | null
    court: string | null
    judge: string | null
    client: string | null
    client_phone: string | null
    client_role: string | null
    opposing: string[]
    opposing_counsel: string[]
  }
}

const shift = (date: string, days: number) => {
  const d = parseDate(date)
  d.setDate(d.getDate() + days)
  return isoDate(d)
}

const time12 = (t: string | null) => {
  if (!t) return 'Time not set'
  const [h = 0, m = 0] = t.split(':').map(Number)
  return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`
}

/** The day's hearings, designed for a phone in the courtroom corridor. */
export function CourtDay() {
  const [date, setDate] = useUrlState('date', today())
  const [who, setWho] = useUrlState('who', 'mine')
  const mine = who === 'mine'
  const [recording, setRecording] = useState<Hearing | null>(null)
  const query = useQuery({
    queryKey: ['deadlines', 'court-day', date, who],
    queryFn: () => get<{ date: string; hearings: Hearing[] }>('/v1/court-day', { date, mine: mine ? 1 : 0 }),
  })

  return (
    <>
      <PageHeader title="Court day" description="Hearings for the day, with everything you need at the courtroom door." />
      <div className="mb-4 flex flex-wrap items-center gap-2">
        {/* One row even on a phone: previous, date, next. */}
        <div className="flex min-w-0 flex-1 items-center gap-1 sm:flex-none">
          <IconButton label="Previous day" onClick={() => setDate(shift(date, -1))}><ChevronLeft className="size-5" /></IconButton>
          <Input type="date" aria-label="Date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} className="min-w-0 flex-1 sm:w-44 sm:flex-none" />
          <IconButton label="Next day" onClick={() => setDate(shift(date, 1))}><ChevronRight className="size-5" /></IconButton>
        </div>
        {date !== today() && <Button variant="text" size="sm" onClick={() => setDate(today())}>Today</Button>}
        <Checkbox label="Only mine" checked={mine} onChange={(e) => setWho(e.target.checked ? 'mine' : 'all')} className="ml-auto" />
      </div>
      <p className="mb-4 text-sm font-medium text-on-surface-variant">{longDate(date)}</p>

      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : query.data.hearings.length === 0 ? (
        <Card><EmptyState icon={<Gavel className="size-6" />} title="No hearings" description={mine ? 'None assigned to you on this day.' : 'None on this day.'} /></Card>
      ) : (
        <ol className="flex flex-col gap-4">
          {query.data.hearings.map((h) => (
            <li key={h.id}>
              <Card className="p-4 sm:p-5">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="flex items-center gap-2 text-lg font-semibold"><Clock className="size-5 text-primary" aria-hidden />{time12(h.time)}</p>
                    <p className="font-medium">{h.title}</p>
                  </div>
                  {h.status !== 'pending' && <Badge tone={h.status === 'completed' ? 'success' : 'neutral'}>{h.status === 'completed' ? 'Done' : h.status}</Badge>}
                </div>

                <dl className="mt-3 grid grid-cols-1 gap-2 text-sm">
                  <div>
                    <dt className="sr-only">Case</dt>
                    <dd><Link to={`/matters/${h.matter.id}`} className="font-medium text-primary hover:underline">{h.matter.title}</Link><span className="text-on-surface-variant"> · {h.matter.case_number ?? h.matter.reference}</span></dd>
                  </div>
                  {(h.matter.court || h.location) && (
                    <div className="flex gap-2"><MapPin className="size-4 shrink-0 text-on-surface-variant" aria-hidden /><dd>{[h.matter.court, h.location].filter(Boolean).join(' · ')}{h.matter.judge && <span className="text-on-surface-variant"> · {h.matter.judge}</span>}</dd></div>
                  )}
                  <div className="flex gap-2">
                    <Users className="size-4 shrink-0 text-on-surface-variant" aria-hidden />
                    <dd>
                      {h.matter.client}{h.matter.client_role && <span className="text-on-surface-variant"> ({h.matter.client_role})</span>}
                      {h.matter.opposing.length > 0 && <> v. {h.matter.opposing.join(', ')}</>}
                      {h.matter.opposing_counsel.length > 0 && <div className="text-on-surface-variant">Opposing counsel: {h.matter.opposing_counsel.join(', ')}</div>}
                    </dd>
                  </div>
                  {h.matter.client_phone && (
                    <div className="flex gap-2"><Phone className="size-4 shrink-0 text-on-surface-variant" aria-hidden /><dd><a href={`tel:${h.matter.client_phone.replace(/\s/g, '')}`} className="text-primary">Call client: {h.matter.client_phone}</a></dd></div>
                  )}
                  {h.notes && <div className="rounded-[3px] bg-warning-container p-3 text-on-warning-container"><dt className="font-medium">Bring / remember</dt><dd className="whitespace-pre-line">{h.notes}</dd></div>}
                  {h.assignee && <p className="text-xs text-on-surface-variant">Appearing: {h.assignee}</p>}
                </dl>

                {h.status === 'pending' && (
                  <Button className="mt-4 h-12 w-full sm:w-auto" icon={<Gavel className="size-5" />} onClick={() => setRecording(h)}>Hearing done</Button>
                )}
              </Card>
            </li>
          ))}
        </ol>
      )}
      {recording && <OutcomeDialog hearing={recording} onClose={() => setRecording(null)} />}
    </>
  )
}

interface FollowUp { title: string; days: string; kind: 'filing' | 'task' }

function OutcomeDialog({ hearing, onClose }: { hearing: Hearing; onClose: () => void }) {
  const [outcome, setOutcome] = useState<'held' | 'reset' | 'cancelled'>('held')
  const [notes, setNotes] = useState('')
  const [nextDate, setNextDate] = useState('')
  const [nextTime, setNextTime] = useState(hearing.time ?? '')
  const [minutes, setMinutes] = useState('')
  const [followUps, setFollowUps] = useState<FollowUp[]>([])
  const save = useApiMutation(
    (input: object) => post<{ follow_ups: { title: string; due_date: string }[] }>(`/v1/deadlines/${hearing.id}/hearing-outcome`, input),
    { invalidate: [['deadlines'], ['tasks'], ['time-entries'], ['matters']], success: 'Hearing recorded', toastErrors: false },
  )
  const error = save.error ? ApiError.from(save.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({
      outcome,
      notes: notes || undefined,
      next_date: nextDate || undefined,
      next_time: nextDate && nextTime ? nextTime : undefined,
      follow_ups: followUps.filter((f) => f.title.trim() && Number(f.days) > 0).map((f) => ({ title: f.title.trim(), days: Number(f.days), kind: f.kind })),
      minutes: minutes ? Number(minutes) : undefined,
    }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} title={`${hearing.title}: what happened?`} description={hearing.matter.title}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="outcome-form" loading={save.isPending}>Save</Button></>}>
      <form id="outcome-form" onSubmit={submit} className="flex flex-col gap-4">
        <fieldset className="grid grid-cols-3 gap-2" aria-label="Outcome">
          {([['held', 'Held'], ['reset', 'Reset'], ['cancelled', 'Cancelled']] as const).map(([value, label]) => (
            <label key={value} className={`flex h-12 cursor-pointer items-center justify-center rounded-[3px] border text-sm font-medium ${outcome === value ? 'border-primary bg-primary-container text-on-primary-container' : 'border-outline-variant'}`}>
              <input type="radio" name="outcome" value={value} checked={outcome === value} onChange={() => setOutcome(value)} className="sr-only" />
              {label}
            </label>
          ))}
        </fieldset>
        <Field label={outcome === 'reset' ? 'Why it was reset' : 'What happened'} error={error?.field('notes')}>
          {(a) => <Textarea {...a} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder={outcome === 'held' ? 'e.g. Pre-trial terminated; trial set.' : 'e.g. Judge on leave.'} />}
        </Field>
        {outcome !== 'cancelled' && (
          <div className="grid grid-cols-2 gap-3">
            <Field label={outcome === 'reset' ? 'New date' : 'Next setting'} required={outcome === 'reset'} error={error?.field('next_date')}>
              {(a) => <Input {...a} type="date" min={shift(today(), 1)} value={nextDate} onChange={(e) => setNextDate(e.target.value)} />}
            </Field>
            <Field label="Time">{(a) => <Input {...a} type="time" value={nextTime} onChange={(e) => setNextTime(e.target.value)} />}</Field>
          </div>
        )}
        {outcome === 'held' && (
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">Deadlines the court gave</legend>
            {followUps.map((f, i) => (
              <div key={i} className="grid grid-cols-[1fr_5rem_auto] items-end gap-2">
                <Input aria-label="What to file or do" placeholder="File memorandum" value={f.title} onChange={(e) => setFollowUps(followUps.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} />
                <Input aria-label="Within days" type="number" min={1} placeholder="Days" value={f.days} onChange={(e) => setFollowUps(followUps.map((x, j) => (j === i ? { ...x, days: e.target.value } : x)))} />
                <IconButton label="Remove" onClick={() => setFollowUps(followUps.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
              </div>
            ))}
            <p className="text-xs text-on-surface-variant">Counted from today; a period ending on a weekend or holiday moves to the next working day.</p>
            <Button variant="text" size="sm" className="self-start" icon={<Plus className="size-4" />} onClick={() => setFollowUps([...followUps, { title: '', days: '', kind: 'filing' }])}>Add a deadline</Button>
          </fieldset>
        )}
        {outcome !== 'cancelled' && (
          <Field label="Time spent (minutes, optional)" hint="Logged as billable time at your rate.">
            {(a) => <Input {...a} type="number" min={1} max={1440} inputMode="numeric" value={minutes} onChange={(e) => setMinutes(e.target.value)} />}
          </Field>
        )}
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </form>
    </Dialog>
  )
}
