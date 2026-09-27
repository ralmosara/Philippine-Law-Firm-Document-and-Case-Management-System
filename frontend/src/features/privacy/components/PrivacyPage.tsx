import { AlertTriangle, Download, FileLock2, Plus, ShieldAlert, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useClientOptions } from '@/features/clients/api'
import { ApiError } from '@/shared/api/axios'
import { date, dateTime } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Tabs, Td, Th } from '@/shared/ui/Layout'
import {
  DSR_TYPES, useAnonymizeClient, useDataRequests, useDisposeMatter, useIncidents, usePrivacySettings, usePrivacySummary, useRecordRequest, useResolveRequest,
  useRetention, useSaveIncident, useSavePrivacySettings, type DataSubjectRequest, type DsrType, type PrivacyIncident,
} from '../api'

type Tab = 'requests' | 'incidents' | 'retention' | 'notice'

/** Data Privacy Act compliance: requests, breaches, retention and the notice. */
export function PrivacyPage() {
  const [tab, setTab] = useUrlState('tab', 'requests')
  const summary = usePrivacySummary()
  const s = summary.data

  return (
    <>
      <PageHeader title="Data privacy" description="Your obligations under the Data Privacy Act of 2012 (RA 10173): answering people about their data, reporting breaches, and keeping records only as long as needed." />
      {s && (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard label="Open requests" value={s.open_requests} tone={s.overdue_requests ? 'danger' : undefined} detail={s.overdue_requests ? `${s.overdue_requests} overdue` : 'None overdue'} />
          <StatCard label="Breaches to report to the NPC" value={s.incidents_awaiting_npc} tone={s.incidents_awaiting_npc ? 'danger' : undefined} detail="Within 72 hours of discovery" />
          <StatCard label="Matters past retention" value={s.due_for_disposal} tone={s.due_for_disposal ? 'warning' : undefined} />
          <StatCard label="Portal clients yet to accept the notice" value={s.clients_without_consent} detail="Asked at their next sign-in" />
        </div>
      )}
      <Tabs<Tab>
        label="Privacy sections"
        value={tab as Tab}
        onChange={setTab}
        tabs={[
          { value: 'requests', label: 'Requests', count: s?.open_requests || undefined },
          { value: 'incidents', label: 'Breaches', count: s?.incidents_awaiting_npc || undefined },
          { value: 'retention', label: 'Retention' },
          { value: 'notice', label: 'Notice & DPO' },
        ]}
      />
      {tab === 'requests' && <RequestsPanel />}
      {tab === 'incidents' && <IncidentsPanel />}
      {tab === 'retention' && <RetentionPanel />}
      {tab === 'notice' && <NoticePanel />}
    </>
  )
}

function RequestsPanel() {
  const requests = useDataRequests()
  const [recording, setRecording] = useState(false)
  const [resolving, setResolving] = useState<DataSubjectRequest | null>(null)
  const [anonymizing, setAnonymizing] = useState<DataSubjectRequest | null>(null)
  const anonymize = useAnonymizeClient()

  return (
    <Card>
      <CardHeader
        title="Requests from data subjects"
        description="Clients ask through the portal; record requests that come by letter, e-mail or phone here. Aim to answer each within 15 days."
        actions={<Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setRecording(true)}>Record a request</Button>}
      />
      {requests.isPending ? <PageLoader /> : requests.isError ? <div className="p-4"><ErrorState error={requests.error} /></div> : requests.data.length === 0 ? (
        <EmptyState icon={<FileLock2 className="size-6" />} title="No requests yet" />
      ) : (
        <Table caption="Data subject requests">
          <thead><tr><Th>Received</Th><Th>From</Th><Th>Request</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {requests.data.map((r) => (
              <tr key={r.id}>
                <Td className="whitespace-nowrap">{date(r.created_at)}<div className="text-xs text-on-surface-variant">{r.source === 'portal' ? 'Client portal' : 'Recorded by staff'}</div></Td>
                <Td>
                  {r.client ? <Link to={`/clients/${r.client.id}`} className="font-medium text-primary hover:underline">{r.client.name}</Link> : <span className="font-medium">{r.requester_name}</span>}
                  {r.requester_email && <div className="text-xs text-on-surface-variant">{r.requester_email}</div>}
                </Td>
                <Td className="max-w-md">
                  <span className="font-medium">{r.type_label}</span>
                  {r.details && <p className="text-sm whitespace-pre-line text-on-surface-variant">{r.details}</p>}
                  {r.resolution && <p className="mt-1 text-sm"><span className="font-medium">Answer:</span> {r.resolution}</p>}
                </Td>
                <Td className="whitespace-nowrap">
                  {r.status === 'open' ? (
                    r.is_overdue ? <Badge tone="danger">Overdue since {date(r.due_on)}</Badge> : <Badge tone="warning">Answer by {date(r.due_on)}</Badge>
                  ) : r.status === 'completed' ? <Badge tone="success">Done</Badge> : <Badge>Declined</Badge>}
                  {r.handled_by && <div className="text-xs text-on-surface-variant">{r.handled_by}, {date(r.resolved_at)}</div>}
                </Td>
                <Td align="right" className="whitespace-nowrap">
                  {r.client && ['access', 'portability'].includes(r.type) && (
                    <DownloadButton href={`/api/v1/clients/${r.client.id}/personal-data`} size="sm" icon={<Download className="size-4" />}>Their data</DownloadButton>
                  )}
                  {r.client && r.type === 'erasure' && !r.client.anonymized && r.status === 'open' && (
                    <Button size="sm" variant="text" onClick={() => setAnonymizing(r)}>Anonymize</Button>
                  )}
                  {r.status === 'open' && <Button size="sm" variant="text" onClick={() => setResolving(r)}>Answer</Button>}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {recording && <RecordRequestDialog onClose={() => setRecording(false)} />}
      {resolving && <ResolveDialog request={resolving} onClose={() => setResolving(null)} />}
      <ConfirmDialog
        open={anonymizing !== null}
        onClose={() => { setAnonymizing(null); anonymize.reset() }}
        title={`Anonymize ${anonymizing?.client?.name}?`}
        description={
          <>
            <p>Their name, e-mail, phone, TIN, address and notes are removed for good, and portal access ends. Case records, invoices and the trust ledger are kept, as the law requires. This cannot be undone.</p>
            {anonymize.isError && <p className="mt-2 text-danger">{ApiError.from(anonymize.error).message}</p>}
          </>
        }
        destructive
        confirmLabel="Anonymize"
        loading={anonymize.isPending}
        onConfirm={() => anonymizing?.client && anonymize.mutate(anonymizing.client.id, { onSuccess: () => setAnonymizing(null) })}
      />
    </Card>
  )
}

function RecordRequestDialog({ onClose }: { onClose: () => void }) {
  const record = useRecordRequest()
  const clients = useClientOptions()
  const [form, setForm] = useState({ client_id: '', requester_name: '', requester_email: '', type: 'access' as DsrType, details: '' })
  const error = record.error ? ApiError.from(record.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const client = clients.data?.find((c) => String(c.id) === form.client_id)
    record.mutate({
      client_id: client?.id,
      requester_name: form.requester_name || client?.name || '',
      requester_email: form.requester_email || undefined,
      type: form.type,
      details: form.details || undefined,
    }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} title="Record a request" footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="dsr-form" loading={record.isPending}>Record</Button></>}>
      <form id="dsr-form" onSubmit={submit} className="flex flex-col gap-4">
        <Field label="Client (if they are one)">
          {(a) => (
            <Select {...a} value={form.client_id} onChange={(e) => setForm({ ...form, client_id: e.target.value })}>
              <option value="">Not a client, or unknown</option>
              {clients.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          )}
        </Field>
        {!form.client_id && (
          <Field label="Name" required error={error?.field('requester_name')}>{(a) => <Input {...a} value={form.requester_name} onChange={(e) => setForm({ ...form, requester_name: e.target.value })} required />}</Field>
        )}
        <Field label="E-mail" error={error?.field('requester_email')}>{(a) => <Input {...a} type="email" value={form.requester_email} onChange={(e) => setForm({ ...form, requester_email: e.target.value })} />}</Field>
        <Field label="They ask to">
          {(a) => (
            <Select {...a} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as DsrType })}>
              {Object.entries(DSR_TYPES).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Details">{(a) => <Textarea {...a} rows={3} value={form.details} onChange={(e) => setForm({ ...form, details: e.target.value })} />}</Field>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </form>
    </Dialog>
  )
}

function ResolveDialog({ request, onClose }: { request: DataSubjectRequest; onClose: () => void }) {
  const resolve = useResolveRequest()
  const [status, setStatus] = useState<'completed' | 'denied'>('completed')
  const [resolution, setResolution] = useState('')
  const error = resolve.error ? ApiError.from(resolve.error) : null

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Answer: ${request.type_label.toLowerCase()}`}
      description={`From ${request.client?.name ?? request.requester_name}. What you write here is shown to the client in the portal.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={resolve.isPending} disabled={!resolution.trim()} onClick={() => resolve.mutate({ id: request.id, status, resolution }, { onSuccess: onClose })}>Close request</Button></>}
    >
      <div className="flex flex-col gap-4">
        <Field label="Outcome">
          {(a) => (
            <Select {...a} value={status} onChange={(e) => setStatus(e.target.value as 'completed' | 'denied')}>
              <option value="completed">Done</option>
              <option value="denied">Declined (give the legal basis)</option>
            </Select>
          )}
        </Field>
        <Field label="Answer" required error={error?.field('resolution')}>
          {(a) => <Textarea {...a} rows={4} value={resolution} onChange={(e) => setResolution(e.target.value)} placeholder={status === 'denied' ? 'e.g. We must keep the case file while the appeal is pending (RA 10173, Sec. 13(f)).' : 'e.g. Your mobile number has been updated.'} />}
        </Field>
      </div>
    </Dialog>
  )
}

function IncidentsPanel() {
  const incidents = useIncidents()
  const [editing, setEditing] = useState<PrivacyIncident | 'new' | null>(null)

  return (
    <Card>
      <CardHeader
        title="Breach log"
        description="Record every personal data breach or security incident, even small ones. When there is a real risk of serious harm, notify the National Privacy Commission and the people affected within 72 hours of discovery (NPC Circular 16-03)."
        actions={<Button variant="tonal" size="sm" icon={<ShieldAlert className="size-4" />} onClick={() => setEditing('new')}>Log an incident</Button>}
      />
      {incidents.isPending ? <PageLoader /> : incidents.isError ? <div className="p-4"><ErrorState error={incidents.error} /></div> : incidents.data.length === 0 ? (
        <EmptyState icon={<ShieldAlert className="size-6" />} title="No incidents logged" />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {incidents.data.map((i) => {
            const hoursLeft = Math.round((new Date(i.notify_by).getTime() - Date.now()) / 3_600_000)
            return (
              <li key={i.id} className="flex flex-col gap-2 p-5 sm:flex-row sm:items-start">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{i.title} <Badge tone={i.status === 'closed' ? 'neutral' : i.status === 'contained' ? 'primary' : 'warning'}>{i.status}</Badge> {i.sensitive && <Badge tone="danger">Sensitive data</Badge>}</p>
                  <p className="text-sm text-on-surface-variant">Discovered {dateTime(i.discovered_at)}{i.affected_count != null && ` · ${i.affected_count} people affected`}{i.reported_by && ` · logged by ${i.reported_by}`}</p>
                  <p className="mt-1 text-sm whitespace-pre-line">{i.description}</p>
                  {i.actions_taken && <p className="mt-1 text-sm"><span className="font-medium">Actions:</span> {i.actions_taken}</p>}
                </div>
                <div className="flex shrink-0 flex-col items-start gap-2 sm:items-end">
                  {i.npc_notification_pending ? (
                    <Badge tone="danger"><AlertTriangle className="size-3" aria-hidden /> {hoursLeft > 0 ? `Notify NPC within ${hoursLeft} h` : `NPC notice overdue by ${-hoursLeft} h`}</Badge>
                  ) : i.notifiable ? <Badge tone="success">NPC notified {date(i.npc_notified_at)}</Badge> : <Badge>Not notifiable</Badge>}
                  <Button size="sm" variant="text" onClick={() => setEditing(i)}>Update</Button>
                </div>
              </li>
            )
          })}
        </ul>
      )}
      {editing && <IncidentDialog incident={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

const localInput = (iso: string | null) => {
  if (!iso) return ''
  const d = new Date(iso)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60_000).toISOString().slice(0, 16)
}

function IncidentDialog({ incident, onClose }: { incident: PrivacyIncident | null; onClose: () => void }) {
  const save = useSaveIncident(incident?.id)
  const [form, setForm] = useState({
    title: incident?.title ?? '',
    description: incident?.description ?? '',
    discovered_at: localInput(incident?.discovered_at ?? new Date().toISOString()),
    affected_count: incident?.affected_count?.toString() ?? '',
    data_involved: incident?.data_involved ?? '',
    sensitive: incident?.sensitive ?? false,
    notifiable: incident?.notifiable ?? true,
    npc_notified_at: localInput(incident?.npc_notified_at ?? null),
    subjects_notified_at: localInput(incident?.subjects_notified_at ?? null),
    actions_taken: incident?.actions_taken ?? '',
    status: incident?.status ?? 'open',
  })
  const error = save.error ? ApiError.from(save.error) : null
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }))
  const iso = (local: string) => (local ? new Date(local).toISOString() : null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({
      title: form.title,
      description: form.description,
      discovered_at: iso(form.discovered_at) ?? undefined,
      affected_count: form.affected_count === '' ? null : Number(form.affected_count),
      data_involved: form.data_involved || null,
      sensitive: form.sensitive,
      notifiable: form.notifiable,
      ...(incident ? { npc_notified_at: iso(form.npc_notified_at), subjects_notified_at: iso(form.subjects_notified_at), actions_taken: form.actions_taken || null, status: form.status } : {}),
    }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="lg" title={incident ? 'Update incident' : 'Log an incident'} footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="incident-form" loading={save.isPending}>Save</Button></>}>
      <form id="incident-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="What happened" required error={error?.field('title')} className="sm:col-span-2">{(a) => <Input {...a} value={form.title} onChange={(e) => set('title', e.target.value)} placeholder="e.g. E-mail with client files sent to the wrong recipient" required />}</Field>
        <Field label="Details" required error={error?.field('description')} className="sm:col-span-2">{(a) => <Textarea {...a} rows={3} value={form.description} onChange={(e) => set('description', e.target.value)} required />}</Field>
        <Field label="Discovered" required error={error?.field('discovered_at')}>{(a) => <Input {...a} type="datetime-local" value={form.discovered_at} onChange={(e) => set('discovered_at', e.target.value)} required />}</Field>
        <Field label="People affected" error={error?.field('affected_count')}>{(a) => <Input {...a} type="number" min={0} value={form.affected_count} onChange={(e) => set('affected_count', e.target.value)} />}</Field>
        <Field label="Data involved" className="sm:col-span-2">{(a) => <Input {...a} value={form.data_involved} onChange={(e) => set('data_involved', e.target.value)} placeholder="e.g. names, addresses, case details, government IDs" />}</Field>
        <Checkbox label="Sensitive personal information (IDs, health, legal proceedings…)" checked={form.sensitive} onChange={(e) => set('sensitive', e.target.checked)} />
        <Checkbox label="Real risk of serious harm: must be reported to the NPC" checked={form.notifiable} onChange={(e) => set('notifiable', e.target.checked)} />
        {incident && (
          <>
            <Field label="NPC notified on" error={error?.field('npc_notified_at')}>{(a) => <Input {...a} type="datetime-local" value={form.npc_notified_at} onChange={(e) => set('npc_notified_at', e.target.value)} />}</Field>
            <Field label="Affected people notified on" error={error?.field('subjects_notified_at')}>{(a) => <Input {...a} type="datetime-local" value={form.subjects_notified_at} onChange={(e) => set('subjects_notified_at', e.target.value)} />}</Field>
            <Field label="Actions taken" className="sm:col-span-2">{(a) => <Textarea {...a} rows={3} value={form.actions_taken} onChange={(e) => set('actions_taken', e.target.value)} />}</Field>
            <Field label="Status">
              {(a) => (
                <Select {...a} value={form.status} onChange={(e) => set('status', e.target.value as typeof form.status)}>
                  <option value="open">Open</option>
                  <option value="contained">Contained</option>
                  <option value="closed">Closed</option>
                </Select>
              )}
            </Field>
          </>
        )}
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

function RetentionPanel() {
  const retention = useRetention()
  const dispose = useDisposeMatter()
  const [target, setTarget] = useState<{ id: number; label: string } | null>(null)

  return (
    <Card>
      <CardHeader
        title="Closed matters past the retention period"
        description={retention.data ? `Your policy keeps closed matters for ${retention.data.retention_years} years (change it under Notice & DPO). Disposal erases uploaded files, messages and unsigned drafts for good; invoices, the trust ledger, signed documents and the audit trail remain.` : undefined}
      />
      {retention.isPending ? <PageLoader /> : retention.isError ? <div className="p-4"><ErrorState error={retention.error} /></div> : retention.data.matters.length === 0 ? (
        <EmptyState title="Nothing to dispose of" description="No closed matter is older than the retention period." />
      ) : (
        <Table caption="Matters past retention">
          <thead><tr><Th>Matter</Th><Th>Client</Th><Th>Closed</Th><Th align="right">Files</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {retention.data.matters.map((m) => (
              <tr key={m.id}>
                <Td><span className="font-medium">{m.title}</span><div className="text-xs text-on-surface-variant">{m.reference}</div></Td>
                <Td>{m.client ?? '—'}</Td>
                <Td className="whitespace-nowrap">{date(m.closed_at)}</Td>
                <Td align="right">{m.files}</Td>
                <Td align="right"><Button size="sm" variant="text" icon={<Trash2 className="size-4" />} onClick={() => setTarget({ id: m.id, label: `${m.reference} ${m.title}` })}>Dispose</Button></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <ConfirmDialog
        open={target !== null}
        onClose={() => setTarget(null)}
        title={`Dispose of ${target?.label}?`}
        description="Its uploaded files, messages and unsigned drafts are erased permanently and the matter is removed from view. Check first that no claim, audit or pending case needs it. This cannot be undone."
        destructive
        confirmLabel="Dispose permanently"
        loading={dispose.isPending}
        onConfirm={() => target && dispose.mutate(target.id, { onSuccess: () => setTarget(null) })}
      />
    </Card>
  )
}

function NoticePanel() {
  const settings = usePrivacySettings()
  return settings.isPending ? <PageLoader /> : settings.isError ? <ErrorState error={settings.error} /> : <NoticeForm initial={settings.data} />
}

function NoticeForm({ initial }: { initial: NonNullable<ReturnType<typeof usePrivacySettings>['data']> }) {
  const save = useSavePrivacySettings()
  const [form, setForm] = useState({ dpo_name: initial.dpo_name ?? '', dpo_email: initial.dpo_email ?? '', privacy_notice: initial.privacy_notice ?? '', retention_years: initial.retention_years })
  const [custom, setCustom] = useState(!!initial.privacy_notice)
  const error = save.error ? ApiError.from(save.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ dpo_name: form.dpo_name || null, dpo_email: form.dpo_email || null, privacy_notice: custom ? form.privacy_notice || null : null, retention_years: form.retention_years })
  }

  return (
    <Card>
      <CardHeader
        title="Privacy notice and Data Protection Officer"
        description={`Version ${initial.privacy_notice_version}${initial.privacy_notice_updated_at ? `, updated ${date(initial.privacy_notice_updated_at)}` : ''}. Shown on the intake form and to portal clients, who are asked to accept each new version. Have your DPO review it.`}
      />
      <form onSubmit={submit} className="flex flex-col gap-4 p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Data Protection Officer" error={error?.field('dpo_name')}>{(a) => <Input {...a} value={form.dpo_name} onChange={(e) => setForm({ ...form, dpo_name: e.target.value })} placeholder="Atty. Ana Reyes" />}</Field>
          <Field label="DPO e-mail" error={error?.field('dpo_email')}>{(a) => <Input {...a} type="email" value={form.dpo_email} onChange={(e) => setForm({ ...form, dpo_email: e.target.value })} placeholder="dpo@yourfirm.ph" />}</Field>
          <Field label="Keep closed matters for (years)" error={error?.field('retention_years')} hint="Your records retention policy.">
            {(a) => <Input {...a} type="number" min={1} max={50} value={form.retention_years} onChange={(e) => setForm({ ...form, retention_years: Number(e.target.value) })} required />}
          </Field>
        </div>
        <Checkbox label="Use our own notice instead of the built-in one" checked={custom} onChange={(e) => { setCustom(e.target.checked); if (e.target.checked && !form.privacy_notice) setForm({ ...form, privacy_notice: initial.default_notice }) }} />
        {custom ? (
          <Field label="Privacy notice" error={error?.field('privacy_notice')}>{(a) => <Textarea {...a} rows={14} value={form.privacy_notice} onChange={(e) => setForm({ ...form, privacy_notice: e.target.value })} />}</Field>
        ) : (
          <div className="rounded-lg bg-surface-container p-4 text-sm whitespace-pre-line">{initial.default_notice}</div>
        )}
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        <Button type="submit" loading={save.isPending} className="self-start">Save</Button>
      </form>
    </Card>
  )
}
