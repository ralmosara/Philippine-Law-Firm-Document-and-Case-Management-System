import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAbilities, useLookups } from '@/features/auth/session'
import { useLawyerOptions } from '@/features/users/api'
import { ApiError } from '@/shared/api/axios'
import { date, dateTime, money, toCents, today } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { EngagementLetterDialog } from './EngagementLetterDialog'
import { OPEN_STAGES, useConvertProspect, useMoveProspect, useProspect, useProspectNote, useSaveProspect, type Prospect, type ProspectInput } from '../api'

export function ProspectForm({ prospect, sources, onClose }: { prospect?: Prospect; sources: Record<string, string>; onClose: () => void }) {
  const lookups = useLookups()
  const lawyers = useLawyerOptions()
  const save = useSaveProspect(prospect?.id)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState({
    name: prospect?.name ?? '', organization: prospect?.organization ?? '', client_type: prospect?.client_type ?? 'individual', email: prospect?.email ?? '',
    phone: prospect?.phone ?? '', source: prospect?.source ?? 'referral', referred_by: prospect?.referred_by ?? '', case_type: prospect?.case_type ?? '',
    description: prospect?.description ?? '', opposing: (prospect?.opposing_parties ?? []).join('\n'),
    value: prospect?.estimated_value_cents ? (prospect.estimated_value_cents / 100).toFixed(2) : '', owner_id: prospect?.owner_id ?? null,
    next_step: prospect?.next_step ?? '', next_step_on: prospect?.next_step_on ?? '',
  })
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm({ ...form, [k]: v })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const input: ProspectInput = {
      name: form.name, organization: form.organization || null, client_type: form.client_type, email: form.email || null, phone: form.phone || null,
      source: form.source, referred_by: form.referred_by || null, case_type: form.case_type || null, description: form.description || null,
      opposing_parties: form.opposing.split('\n').map((s) => s.trim()).filter(Boolean), estimated_value_cents: form.value ? toCents(form.value) : null,
      owner_id: form.owner_id, next_step: form.next_step || null, next_step_on: form.next_step_on || null,
    }
    save.mutate(input, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="lg" title={prospect ? 'Edit prospect' : 'Add a prospect'} description={prospect ? undefined : 'The name and the opposing parties are conflict-checked as soon as you save.'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="prospect-form" loading={save.isPending}>Save</Button></>}>
      <form id="prospect-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="Name" required error={error?.field('name')}>{(a) => <Input {...a} required value={form.name} onChange={(e) => set('name', e.target.value)} />}</Field>
        <Field label="Individual or company">{(a) => <Select {...a} value={form.client_type} onChange={(e) => set('client_type', e.target.value as 'individual' | 'corporate')}><option value="individual">Individual</option><option value="corporate">Company</option></Select>}</Field>
        <Field label={form.client_type === 'corporate' ? 'Contact person / company' : 'Company (if any)'}>{(a) => <Input {...a} value={form.organization} onChange={(e) => set('organization', e.target.value)} />}</Field>
        <Field label="Practice area">{(a) => <Select {...a} value={form.case_type} onChange={(e) => set('case_type', e.target.value)}><option value="">Not yet known</option>{lookups.data?.case_types.map((t) => <option key={t} value={t}>{t}</option>)}</Select>}</Field>
        <Field label="Email" error={error?.field('email')}>{(a) => <Input {...a} type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />}</Field>
        <Field label="Phone">{(a) => <Input {...a} value={form.phone} onChange={(e) => set('phone', e.target.value)} />}</Field>
        <Field label="Came from">{(a) => <Select {...a} value={form.source} onChange={(e) => set('source', e.target.value)}>{Object.entries(sources).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}</Field>
        <Field label="Referred by">{(a) => <Input {...a} value={form.referred_by} onChange={(e) => set('referred_by', e.target.value)} placeholder="Client, colleague or firm" />}</Field>
        <Field label="What they need" className="sm:col-span-2">{(a) => <Textarea {...a} value={form.description} onChange={(e) => set('description', e.target.value)} className="min-h-16" />}</Field>
        <Field label="Opposing parties" hint="One per line; each is conflict-checked." className="sm:col-span-2">{(a) => <Textarea {...a} value={form.opposing} onChange={(e) => set('opposing', e.target.value)} className="min-h-14" />}</Field>
        <Field label="Estimated fees (₱)" hint="For the pipeline value.">{(a) => <Input {...a} inputMode="decimal" value={form.value} onChange={(e) => set('value', e.target.value)} placeholder="0.00" />}</Field>
        <Field label="Handled by" error={error?.field('owner_id')}>
          {(a) => <Select {...a} value={form.owner_id ?? ''} onChange={(e) => set('owner_id', Number(e.target.value) || null)}><option value="">Me</option>{lawyers.data?.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select>}
        </Field>
        <Field label="Next step">{(a) => <Input {...a} value={form.next_step} onChange={(e) => set('next_step', e.target.value)} placeholder="Send the retainer proposal" />}</Field>
        <Field label="By" hint="You are reminded that day.">{(a) => <Input {...a} type="date" value={form.next_step_on} onChange={(e) => set('next_step_on', e.target.value)} />}</Field>
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

const EVENT_LABEL: Record<string, string> = { note: 'Note', call: 'Call', meeting: 'Meeting', email: 'Email', stage: 'Stage' }

export function ProspectDetailDialog({ id, stages, onClose, onEdit }: { id: number; stages: Record<string, string>; onClose: () => void; onEdit: (p: Prospect) => void }) {
  const abilities = useAbilities()
  const query = useProspect(id)
  const move = useMoveProspect(id)
  const note = useProspectNote(id)
  const [mode, setMode] = useState<'view' | 'lost' | 'convert' | 'engagement'>('view')
  const [text, setText] = useState('')
  const [noteType, setNoteType] = useState('note')
  const p = query.data
  const moveError = move.error ? ApiError.from(move.error) : null

  if (p && mode === 'engagement') return <EngagementLetterDialog prospectId={p.id} name={p.name} email={p.email} onClose={() => setMode('view')} />
  if (p && mode === 'convert') return <ConvertDialog prospectId={p.id} name={p.name} caseType={p.case_type} onClose={() => setMode('view')} onDone={onClose} />

  const open = p && (OPEN_STAGES as readonly string[]).includes(p.stage)
  const flagged = p?.conflicts.filter((c) => c.status === 'flagged') ?? []

  return (
    <Dialog open onClose={onClose} size="xl" title={p?.name ?? 'Prospect'} description={p ? [p.stage_label, p.case_type, p.source_label, p.owner].filter(Boolean).join(' · ') : undefined}
      footer={p && open && abilities.work_matters && (mode === 'lost' ? (
        <><Button variant="text" onClick={() => setMode('view')}>Back</Button><Button variant="danger" loading={move.isPending} onClick={() => move.mutate({ stage: 'lost', lost_reason: text }, { onSuccess: onClose })}>Mark lost</Button></>
      ) : (
        <>
          <Button variant="text" onClick={() => { setText(''); setMode('lost') }}>Lost</Button>
          <Button variant="outlined" onClick={() => onEdit(p)}>Edit</Button>
          {OPEN_STAGES.filter((s) => s !== p.stage).map((s) => <Button key={s} variant="outlined" loading={move.isPending && move.variables?.stage === s} onClick={() => move.mutate({ stage: s })}>{stages[s]}</Button>)}
          {abilities.practice_law && <Button variant="tonal" onClick={() => setMode('engagement')}>Engagement letter</Button>}
          {abilities.practice_law && <Button onClick={() => setMode('convert')}>Engaged: open matter</Button>}
        </>
      ))}>
      {query.isPending ? <PageLoader /> : query.isError || !p ? <ErrorState error={query.error} /> : (
        <div className="grid grid-cols-1 gap-5 text-sm lg:grid-cols-[1fr_1.2fr]">
          <div className="flex flex-col gap-3">
            <dl className="grid grid-cols-[7.5rem_1fr] gap-x-3 gap-y-1.5">
              <dt className="text-on-surface-variant">Contact</dt><dd>{[p.organization, p.email, p.phone].filter(Boolean).join(' · ') || '—'}</dd>
              <dt className="text-on-surface-variant">Came from</dt><dd>{p.source_label}{p.referred_by && `, ${p.referred_by}`}</dd>
              <dt className="text-on-surface-variant">Estimated fees</dt><dd>{p.estimated_value_cents ? money(p.estimated_value_cents) : '—'}</dd>
              <dt className="text-on-surface-variant">Next step</dt><dd>{p.next_step ? <>{p.next_step}{p.next_step_on && <span className={p.follow_up_due ? 'text-danger' : 'text-on-surface-variant'}> · {date(p.next_step_on)}</span>}</> : '—'}</dd>
              {p.opposing_parties.length > 0 && <><dt className="text-on-surface-variant">Opposing</dt><dd>{p.opposing_parties.join(', ')}</dd></>}
              {p.proposal_sent_on && <><dt className="text-on-surface-variant">Proposal sent</dt><dd>{date(p.proposal_sent_on)}</dd></>}
              {p.engagement_sent_on && <><dt className="text-on-surface-variant">Engagement sent</dt><dd>{date(p.engagement_sent_on)}</dd></>}
              {p.matter && <><dt className="text-on-surface-variant">Matter</dt><dd><Link to={`/matters/${p.matter.id}`} className="text-primary hover:underline">{p.matter.reference} · {p.matter.title}</Link></dd></>}
              {p.lost_reason && <><dt className="text-on-surface-variant">Lost because</dt><dd>{p.lost_reason}</dd></>}
            </dl>
            {p.description && <p className="whitespace-pre-line text-on-surface-variant">{p.description}</p>}
            <div>
              <h3 className="mb-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Conflict checks</h3>
              <ul className="flex flex-wrap gap-1.5">
                {p.conflicts.map((c) => (
                  <li key={c.id}><Link to="/compliance?tab=conflicts" className="no-underline"><Badge tone={c.status === 'flagged' ? 'danger' : c.status === 'declined' ? 'danger' : c.status === 'waived' ? 'warning' : 'success'}>{c.search_term}: {c.status}</Badge></Link></li>
                ))}
              </ul>
              {flagged.length > 0 && <p className="mt-1 text-xs text-danger">Resolve the flagged check{flagged.length > 1 ? 's' : ''} under Compliance before opening a matter.</p>}
            </div>
            {mode === 'lost' && <Field label="Why was it lost?" required error={moveError?.field('lost_reason')}>{(a) => <Input {...a} value={text} onChange={(e) => setText(e.target.value)} placeholder="Fees too high; went with another firm; no case" />}</Field>}
          </div>
          <div className="flex flex-col gap-2">
            {abilities.work_matters && open && (
              <div className="flex flex-col gap-1.5">
                <Textarea aria-label="Add to the history" value={text && mode === 'view' ? text : ''} onChange={(e) => setText(e.target.value)} className="min-h-14" placeholder="Log a call, a meeting or a note" />
                <div className="flex gap-1.5">
                  <Select aria-label="Type" value={noteType} onChange={(e) => setNoteType(e.target.value)} className="w-32"><option value="note">Note</option><option value="call">Call</option><option value="meeting">Meeting</option><option value="email">Email</option></Select>
                  <Button size="sm" variant="tonal" disabled={!text.trim() || mode !== 'view'} loading={note.isPending} onClick={() => note.mutate({ type: noteType, body: text }, { onSuccess: () => setText('') })}>Add</Button>
                </div>
              </div>
            )}
            <ol className="flex max-h-[45vh] flex-col gap-2 overflow-auto">
              {p.events.map((e) => (
                <li key={e.id} className="rounded-[3px] border border-outline-variant px-3 py-2">
                  <div className="text-xs text-on-surface-variant">{e.type === 'stage' ? `→ ${e.to_label}` : EVENT_LABEL[e.type]} · {e.by} · {dateTime(e.at)}</div>
                  {e.body && <div className="whitespace-pre-line">{e.body}</div>}
                </li>
              ))}
            </ol>
          </div>
          <div className="lg:col-span-2"><FormError message={moveError && !Object.keys(moveError.errors).length ? moveError.message : moveError?.field('stage') ?? null} /></div>
        </div>
      )}
    </Dialog>
  )
}

function ConvertDialog({ prospectId, name, caseType, onClose, onDone }: { prospectId: number; name: string; caseType: string | null; onClose: () => void; onDone: () => void }) {
  const lawyers = useLawyerOptions()
  const convert = useConvertProspect(prospectId)
  const navigate = useNavigate()
  const error = convert.error ? ApiError.from(convert.error) : null
  const [title, setTitle] = useState(`${name}: ${caseType ?? 'New matter'}`)
  const [lawyer, setLawyer] = useState<number | null>(null)

  return (
    <Dialog open onClose={onClose} title="Engagement signed" description={`Creates the client (or reuses one with the same email) and opens the matter with the opposing parties, today ${date(today())}.`}
      footer={<><Button variant="text" onClick={onClose}>Back</Button><Button loading={convert.isPending} onClick={() => convert.mutate({ title, responsible_lawyer_id: lawyer }, { onSuccess: (r) => { onDone(); navigate(`/matters/${r.matter_id}`) } })}>Open the matter</Button></>}>
      <div className="grid grid-cols-1 gap-3">
        <Field label="Matter title" error={error?.field('title')}>{(a) => <Input {...a} value={title} onChange={(e) => setTitle(e.target.value)} />}</Field>
        <Field label="Responsible lawyer">{(a) => <Select {...a} value={lawyer ?? ''} onChange={(e) => setLawyer(Number(e.target.value) || null)}><option value="">Whoever handled the prospect</option>{lawyers.data?.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select>}</Field>
        <FormError message={error ? (error.field('conflicts') ?? error.field('stage') ?? (!Object.keys(error.errors).length ? error.message : null)) : null} />
      </div>
    </Dialog>
  )
}
