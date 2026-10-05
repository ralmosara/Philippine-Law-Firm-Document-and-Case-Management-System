import { AlertTriangle, ArrowLeft, CalendarCheck, CheckCircle2, Inbox, TrendingUp, XCircle } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { useAbilities } from '@/features/auth/session'
import { useResolveConflict } from '@/features/compliance/api'
import { useTrackIntake } from '@/features/prospects/api'
import { useLawyerOptions } from '@/features/users/api'
import { ApiError } from '@/shared/api/axios'
import { date, dateTime, longDate, today } from '@/shared/lib/format'
import { useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, DescriptionList, PageHeader, Pagination, Table, Tabs, Td, Th, Tr } from '@/shared/ui/Layout'
import { useIntakeAction, useIntakeRequest, useIntakeRequests, useScreenPrescription, type IntakeDetail, type IntakeRequest } from '../api'
import { usePrescriptionPeriods } from '@/features/prescription/api'

const STATUS: Record<IntakeRequest['status'], { label: string; tone: 'primary' | 'success' | 'neutral' | 'warning' }> = {
  new: { label: 'New', tone: 'primary' },
  scheduled: { label: 'Consultation set', tone: 'warning' },
  accepted: { label: 'Accepted', tone: 'success' },
  declined: { label: 'Declined', tone: 'neutral' },
}

function ConflictBadge({ status }: { status: 'clear' | 'flagged' }) {
  return status === 'flagged'
    ? <Badge tone="danger"><AlertTriangle className="mr-1 inline size-3" aria-hidden="true" />Possible conflict</Badge>
    : <Badge tone="success">No conflicts found</Badge>
}

/** How soon the claim prescribes, if the period the lawyer chose applies (open requests only). */
function PrescriptionBadge({ p }: { p: IntakeRequest['prescription'] }) {
  if (!p) return null
  if (p.state === 'prescribed') return <Badge tone="danger">Prescribed {date(p.last_day)}</Badge>
  const tone = p.state === 'urgent' ? 'danger' : p.state === 'soon' ? 'warning' : 'neutral'
  return <Badge tone={tone}>Prescribes in {p.days_left} day{p.days_left === 1 ? '' : 's'}</Badge>
}

/** Consultation requests from the firm's public intake page. */
export function IntakeInbox() {
  const [status, setStatus] = useUrlState('status', 'open')
  const [page, setPage] = useUrlPage()
  const list = useIntakeRequests({ status, page })
  const navigate = useNavigate()

  return (
    <>
      <PageHeader title="Online intake" description="Consultation requests from your public intake page, each checked automatically for conflicts of interest." />
      <Tabs
        label="Request status"
        value={status}
        onChange={setStatus}
        tabs={[
          { value: 'open', label: 'To review', count: list.data?.open_count },
          { value: 'accepted', label: 'Accepted' },
          { value: 'declined', label: 'Declined' },
        ]}
      />
      <Card>
        {list.isPending ? <PageLoader /> : list.isError ? <div className="p-4"><ErrorState error={list.error} /></div> : list.data.data.length === 0 ? (
          <EmptyState icon={<Inbox className="size-6" />} title="No requests here" description="Share your intake page link (Firm Settings → Firm & security) on your website and social media." />
        ) : (
          <Table caption="Consultation requests">
            <thead><tr><Th>Applicant</Th><Th>Concern</Th><Th>Conflicts</Th><Th>Status</Th><Th>Received</Th></tr></thead>
            <tbody>
              {list.data.data.map((r) => (
                <Tr key={r.id} onClick={() => navigate(`/intake/${r.id}`)}>
                  <Td><Link to={`/intake/${r.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-primary hover:underline">{r.name}</Link><div className="text-xs text-on-surface-variant">{r.email}</div></Td>
                  <Td>{r.case_type}{r.prescription && <div className="mt-1"><PrescriptionBadge p={r.prescription} /></div>}</Td>
                  <Td><ConflictBadge status={r.conflict_status} /></Td>
                  <Td><Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>{r.consultation_at && <div className="text-xs text-on-surface-variant">{dateTime(r.consultation_at)}</div>}</Td>
                  <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(r.created_at)}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
        <Pagination page={list.data} onPage={setPage} />
      </Card>
    </>
  )
}

export function IntakeReview() {
  const id = Number(useParams().id)
  const request = useIntakeRequest(id)
  const abilities = useAbilities()
  const navigate = useNavigate()
  const track = useTrackIntake()
  const [dialog, setDialog] = useState<'schedule' | 'accept' | 'decline' | null>(null)
  const [resolving, setResolving] = useState<IntakeDetail['conflict_checks'][number] | null>(null)

  if (request.isPending) return <PageLoader />
  if (request.isError) return <ErrorState error={request.error} onRetry={() => request.refetch()} />
  const r = request.data
  const open = r.status === 'new' || r.status === 'scheduled'

  return (
    <>
      <PageHeader
        back={<Link to="/intake" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> Online intake</Link>}
        title={r.name}
        description={<span className="flex flex-wrap items-center gap-2"><Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge><ConflictBadge status={r.conflict_status} /> {r.case_type} · received {dateTime(r.created_at)}</span>}
        actions={
          abilities.practice_law && open && (
            <>
              <Button variant="text" icon={<TrendingUp className="size-4" />} loading={track.isPending} onClick={() => track.mutate(r.id, { onSuccess: (p) => navigate(`/prospects?open=${p.id}`) })}>Track in pipeline</Button>
              <Button variant="outlined" icon={<CalendarCheck className="size-4" />} onClick={() => setDialog('schedule')}>{r.status === 'scheduled' ? 'Reschedule' : 'Schedule consultation'}</Button>
              <Button icon={<CheckCircle2 className="size-4" />} onClick={() => setDialog('accept')}>Accept as client</Button>
              <Button variant="text" icon={<XCircle className="size-4" />} onClick={() => setDialog('decline')}>Decline</Button>
            </>
          )
        }
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_24rem]">
        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader title="Their concern" />
            <p className="p-5 text-sm leading-6 whitespace-pre-wrap">{r.description}</p>
          </Card>
          {open && <PrescriptionScreen request={r} canEdit={abilities.work_matters} />}
          <Card>
            <CardHeader title="Conflict checks" description="Run automatically on the applicant and every party they named. Resolve any flagged check before accepting." />
            <ul className="divide-y divide-outline-variant">
              {r.conflict_checks.map((c) => (
                <li key={c.id} className="px-5 py-3">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">“{c.search_term}”</span>
                    <Badge tone={c.status === 'clear' ? 'success' : c.status === 'waived' ? 'warning' : 'danger'}>{c.status === 'clear' ? 'Clear' : c.status === 'flagged' ? `${c.match_count} possible match${c.match_count === 1 ? '' : 'es'}` : c.status}</Badge>
                  </div>
                  {c.matches.map((m) => (
                    <p key={`${m.source}-${m.id}`} className="mt-1 text-sm text-on-surface-variant">
                      {m.name}: {m.relationship}{m.matter_reference && <> in <Link to={`/matters/${m.matter_id}`} className="text-primary hover:underline">{m.matter_reference}</Link></>}{m.is_adverse && ' (adverse)'}
                    </p>
                  ))}
                  {c.status === 'flagged' && abilities.practice_law && open && <Button variant="text" size="sm" className="mt-1 -ml-3" onClick={() => setResolving(c)}>Resolve</Button>}
                </li>
              ))}
            </ul>
          </Card>
        </div>

        <Card className="self-start">
          <CardHeader title="Applicant" />
          <div className="p-5">
            <DescriptionList
              items={[
                { label: 'Email', value: <a href={`mailto:${r.email}`} className="break-all text-primary hover:underline">{r.email}</a> },
                { label: 'Mobile', value: r.phone },
                { label: 'Type', value: r.client_type === 'corporate' ? 'Company representative' : 'Individual' },
                { label: 'Other parties', value: r.opposing_parties.length ? r.opposing_parties.join(', ') : 'None named' },
                { label: 'Available', value: r.preferred_times.length ? r.preferred_times.map((t) => dateTime(t)).join('; ') : 'No times given' },
                { label: 'Consultation', value: r.consultation_at ? `${dateTime(r.consultation_at)} with ${r.assigned_lawyer?.name ?? '—'}` : 'Not scheduled' },
                { label: 'Privacy consent', value: dateTime(r.consent_at) },
                ...(r.matter_id ? [{ label: 'Matter', value: <Link to={`/matters/${r.matter_id}`} className="text-primary hover:underline">Open matter</Link> }] : []),
                ...(r.internal_notes ? [{ label: 'Internal notes', value: r.internal_notes }] : []),
              ]}
            />
          </div>
        </Card>
      </div>

      {dialog === 'schedule' && <ScheduleDialog request={r} onClose={() => setDialog(null)} />}
      {dialog === 'accept' && <AcceptDialog request={r} onClose={() => setDialog(null)} />}
      {dialog === 'decline' && <DeclineDialog request={r} onClose={() => setDialog(null)} />}
      {resolving && <ResolveConflictDialog check={resolving} onClose={() => setResolving(null)} />}
    </>
  )
}

/**
 * Before taking the case: from the date the applicant gave (correctable) and
 * the type of claim the lawyer judges it to be, when it prescribes. Once the
 * case is taken, the matter tracks it with reminders.
 */
function PrescriptionScreen({ request, canEdit }: { request: IntakeDetail; canEdit: boolean }) {
  const periods = usePrescriptionPeriods()
  const screen = useScreenPrescription(request.id)
  const [incident, setIncident] = useState(request.incident_on ?? '')
  const [period, setPeriod] = useState(request.prescription?.period_key ?? '')
  const p = request.prescription
  const error = screen.error ? ApiError.from(screen.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    screen.mutate({ period_key: period || null, incident_on: incident || null })
  }

  return (
    <Card>
      <CardHeader
        title="Prescription"
        description={request.incident_on ? `The applicant says it happened or started on ${longDate(request.incident_on)}.` : 'The applicant gave no date. Ask at the consultation, or enter it here.'}
      />
      <div className="flex flex-col gap-4 p-5">
        {p && (
          <p role="status" className={`rounded-[3px] p-3 text-sm ${p.state === 'prescribed' || p.state === 'urgent' ? 'bg-danger-container text-on-danger-container' : p.state === 'soon' ? 'bg-warning-container text-on-warning-container' : 'bg-surface-container'}`}>
            <strong>{p.label}</strong> ({p.basis}): {p.state === 'prescribed' ? <>prescribed on <strong>{longDate(p.last_day)}</strong>.</> : <>last day <strong>{longDate(p.last_day)}</strong>, {p.days_left} day{p.days_left === 1 ? '' : 's'} from today{p.file_by !== p.last_day && <> (Rule 22 moves it to {longDate(p.file_by)})</>}.</>}
            {' '}Taking the case starts tracking it on the matter, with reminders.
          </p>
        )}
        {canEdit && (
          <form onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_11rem_auto] sm:items-end">
            <Field label="Type of claim" error={error?.field('period_key')}>
              {(a) => (
                <Select {...a} value={period} onChange={(e) => setPeriod(e.target.value)}>
                  <option value="">Not checked yet</option>
                  {periods.data?.groups.map((g) => (
                    <optgroup key={g.group} label={g.label}>
                      {g.periods.map((x) => <option key={x.key} value={x.key}>{x.label} ({[x.years && `${x.years} yr`, x.months && `${x.months} mo`].filter(Boolean).join(' ')})</option>)}
                    </optgroup>
                  ))}
                </Select>
              )}
            </Field>
            <Field label="It arose on" error={error?.field('incident_on')}>
              {(a) => <Input {...a} type="date" max={today()} value={incident} onChange={(e) => setIncident(e.target.value)} />}
            </Field>
            <Button type="submit" variant="tonal" loading={screen.isPending}>Check</Button>
          </form>
        )}
        <p className="text-xs text-on-surface-variant">A first look, from what the applicant wrote: confirm the accrual date and the period against the documents and current law.</p>
      </div>
    </Card>
  )
}

/** Record the lawyer's decision on a flagged check (kept with the check as compliance evidence). */
function ResolveConflictDialog({ check, onClose }: { check: IntakeDetail['conflict_checks'][number]; onClose: () => void }) {
  const resolve = useResolveConflict(check.id)
  const queryClient = useQueryClient()
  const [decision, setDecision] = useState<'waived' | 'declined'>('waived')
  const [notes, setNotes] = useState('')

  const submit = () =>
    resolve.mutate({ status: decision, notes }, {
      onSuccess: () => {
        void queryClient.invalidateQueries({ queryKey: ['intake'] })
        onClose()
      },
    })

  return (
    <Dialog open onClose={onClose} title={`Resolve the check on “${check.search_term}”`} description="Your decision and reasons are kept with the check as evidence of compliance (CPRA, Canon III)."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={resolve.isPending} disabled={notes.trim().length < 5} onClick={submit}>Record decision</Button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Decision">
          {(a) => (
            <Select {...a} value={decision} onChange={(e) => setDecision(e.target.value as 'waived' | 'declined')}>
              <option value="waived">No conflict / conflict waived with written consent</option>
              <option value="declined">Conflict: we cannot act</option>
            </Select>
          )}
        </Field>
        <Field label="Reasons" required hint="E.g. same person as our existing client; or written consent of all parties obtained on (date).">
          {(a) => <Textarea {...a} rows={3} required maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />}
        </Field>
      </div>
    </Dialog>
  )
}

function ScheduleDialog({ request, onClose }: { request: IntakeDetail; onClose: () => void }) {
  const { schedule } = useIntakeAction(request.id)
  const lawyers = useLawyerOptions()
  const [at, setAt] = useState(request.preferred_times[0]?.slice(0, 16) ?? '')
  const [lawyer, setLawyer] = useState(String(request.assigned_lawyer?.id ?? ''))
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await schedule.mutateAsync({ consultation_at: at, assigned_lawyer_id: Number(lawyer) })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title="Schedule the consultation" description={`${request.name} will be emailed the date, time and your office address.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="schedule-form" loading={schedule.isPending} disabled={!at || !lawyer}>Schedule and email</Button></>}>
      <form id="schedule-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} />
        <Field label="Date and time (Philippine time)" required error={error?.field('consultation_at')}>
          {(a) => <Input {...a} type="datetime-local" required value={at} onChange={(e) => setAt(e.target.value)} />}
        </Field>
        <Field label="Lawyer" required error={error?.field('assigned_lawyer_id')}>
          {(a) => (
            <Select {...a} required value={lawyer} onChange={(e) => setLawyer(e.target.value)}>
              <option value="">Choose…</option>
              {lawyers.data?.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          )}
        </Field>
      </form>
    </Dialog>
  )
}

function AcceptDialog({ request, onClose }: { request: IntakeDetail; onClose: () => void }) {
  const { accept } = useIntakeAction(request.id)
  const lawyers = useLawyerOptions()
  const navigate = useNavigate()
  const [title, setTitle] = useState(request.opposing_parties[0] ? `${request.name} v. ${request.opposing_parties[0]}` : `${request.name}: ${request.case_type}`)
  const [lawyer, setLawyer] = useState(String(request.assigned_lawyer?.id ?? ''))
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const { matter_id } = await accept.mutateAsync({ title, ...(lawyer ? { responsible_lawyer_id: Number(lawyer) } : {}) })
      navigate(`/matters/${matter_id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title="Accept as a client" description="Creates the client (or reuses one with the same email) and opens the matter, recording the parties they named."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="accept-form" loading={accept.isPending}>Create client and matter</Button></>}>
      <form id="accept-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error ? (error.field('conflicts') ?? (Object.keys(error.errors).length ? undefined : error.message)) : undefined} />
        <Field label="Matter title" required error={error?.field('title')}>
          {(a) => <Input {...a} required maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} />}
        </Field>
        <Field label="Responsible lawyer">
          {(a) => (
            <Select {...a} value={lawyer} onChange={(e) => setLawyer(e.target.value)}>
              <option value="">Me</option>
              {lawyers.data?.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          )}
        </Field>
        <p className="text-xs text-on-surface-variant">Next: send the engagement letter and, for the portal, invite the client from their client page.</p>
      </form>
    </Dialog>
  )
}

function DeclineDialog({ request, onClose }: { request: IntakeDetail; onClose: () => void }) {
  const { decline } = useIntakeAction(request.id)
  const [notes, setNotes] = useState('')
  const [notify, setNotify] = useState(true)

  return (
    <Dialog open onClose={onClose} title="Decline this request?"
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button variant="danger" loading={decline.isPending} onClick={() => decline.mutate({ internal_notes: notes || undefined, notify }, { onSuccess: onClose })}>Decline</Button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Internal note" hint="Only the firm sees this.">
          {(a) => <Textarea {...a} rows={3} maxLength={2000} value={notes} onChange={(e) => setNotes(e.target.value)} />}
        </Field>
        <Checkbox label={`Email ${request.name} a courteous decline`} checked={notify} onChange={(e) => setNotify(e.target.checked)} />
        <p className="text-xs text-on-surface-variant">The email never gives a reason. Mentioning a conflict of interest would itself disclose another client’s confidential information.</p>
      </div>
    </Dialog>
  )
}
