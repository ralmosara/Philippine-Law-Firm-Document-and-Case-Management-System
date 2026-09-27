import { useState, type FormEvent } from 'react'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { UsersList } from '@/features/users/components/UsersList'
import { FirmPanel } from './FirmPanel'
import { ImportPanel } from '@/features/imports/components/ImportPanel'
import { useLookups } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import type { DeadlineRule, WorkflowTemplate } from '@/shared/api/types'
import { date, dateTime } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, Pagination, Table, Tabs, Td, Th } from '@/shared/ui/Layout'
import {
  useAddHoliday, useAllDeadlineRules, useAuditLog, useDeleteHoliday, useDeleteRule, useDeleteWorkflow, useHolidays, useSaveRule, useSaveWorkflow, useWorkflows,
} from '../api'

type Tab = 'firm' | 'users' | 'rules' | 'holidays' | 'workflows' | 'import' | 'audit'

export function SettingsPage() {
  const [tab, setTab] = useUrlState('tab', 'users')

  return (
    <>
      <PageHeader title="Firm settings" description="Firm details and security, people, reglementary rules, the holiday calendar and the audit trail." />
      <Tabs<Tab>
        label="Settings sections"
        value={tab as Tab}
        onChange={setTab}
        tabs={[
          { value: 'firm', label: 'Firm & security' },
          { value: 'users', label: 'Users' },
          { value: 'rules', label: 'Deadline rules' },
          { value: 'holidays', label: 'Holidays' },
          { value: 'workflows', label: 'Workflows' },
          { value: 'import', label: 'Import data' },
          { value: 'audit', label: 'Audit log' },
        ]}
      />
      {tab === 'firm' && <FirmPanel />}
      {tab === 'users' && <UsersList />}
      {tab === 'rules' && <RulesPanel />}
      {tab === 'holidays' && <HolidaysPanel />}
      {tab === 'workflows' && <WorkflowsPanel />}
      {tab === 'import' && <ImportPanel />}
      {tab === 'audit' && <AuditPanel />}
    </>
  )
}

function RulesPanel() {
  const rules = useAllDeadlineRules()
  const remove = useDeleteRule()
  const [editing, setEditing] = useState<DeadlineRule | 'new' | null>(null)
  const [deleting, setDeleting] = useState<DeadlineRule | null>(null)

  return (
    <Card>
      <CardHeader
        title="Reglementary periods"
        description="System rules follow the Rules of Court and cannot be edited. Add firm rules for special proceedings or agency rules you use often."
        actions={<Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add rule</Button>}
      />
      {rules.isPending ? <PageLoader /> : rules.isError ? <ErrorState error={rules.error} /> : (
        <Table caption="Deadline rules">
          <thead><tr><Th>Rule</Th><Th>Counted from</Th><Th align="right">Period</Th><Th>Basis</Th><Th /></tr></thead>
          <tbody>
            {rules.data.map((r) => (
              <tr key={r.id}>
                <Td className="font-medium">{r.name} {r.is_system ? <Badge>System</Badge> : <Badge tone="primary">Firm</Badge>} {!r.is_active && <Badge>Inactive</Badge>}</Td>
                <Td className="text-on-surface-variant">{r.trigger_event}</Td>
                <Td align="right">{r.period_days} {r.period_type === 'calendar' ? 'days' : 'working days'}</Td>
                <Td className="text-on-surface-variant">{r.legal_basis ?? '—'}</Td>
                <Td align="right" className="whitespace-nowrap">
                  {!r.is_system && (
                    <>
                      <IconButton label={`Edit ${r.name}`} onClick={() => setEditing(r)}><Pencil className="size-4" /></IconButton>
                      <IconButton label={`Delete ${r.name}`} onClick={() => setDeleting(r)}><Trash2 className="size-4" /></IconButton>
                    </>
                  )}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {editing && <RuleDialog rule={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={deleting !== null} onClose={() => setDeleting(null)} title="Delete rule?" description="Deadlines already computed with it are unaffected." destructive confirmLabel="Delete" loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })} />
    </Card>
  )
}

function RuleDialog({ rule, onClose }: { rule?: DeadlineRule; onClose: () => void }) {
  const save = useSaveRule(rule?.id)
  const [form, setForm] = useState({
    name: rule?.name ?? '', trigger_event: rule?.trigger_event ?? '', period_days: String(rule?.period_days ?? 15),
    period_type: rule?.period_type ?? 'calendar', legal_basis: rule?.legal_basis ?? '', notes: rule?.notes ?? '', is_active: rule?.is_active ?? true,
  })
  const [error, setError] = useState<ApiError | null>(null)
  const set = (key: 'name' | 'trigger_event' | 'period_days' | 'period_type' | 'legal_basis' | 'notes') => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await save.mutateAsync({ ...form, period_days: Number(form.period_days), period_type: form.period_type as DeadlineRule['period_type'], legal_basis: form.legal_basis || null, notes: form.notes || null })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title={rule ? 'Edit rule' : 'Add firm rule'} footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="rule-form" loading={save.isPending}>Save</Button></>}>
      <form id="rule-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>
        <Field label="Name" required className="sm:col-span-2" error={error?.field('name')}>{(a) => <Input {...a} required value={form.name} onChange={set('name')} placeholder="Position paper (NLRC)" />}</Field>
        <Field label="Counted from" required className="sm:col-span-2" error={error?.field('trigger_event')}>{(a) => <Input {...a} required value={form.trigger_event} onChange={set('trigger_event')} placeholder="Receipt of order" />}</Field>
        <Field label="Days" required error={error?.field('period_days')}>{(a) => <Input {...a} required type="number" min={1} max={3650} value={form.period_days} onChange={set('period_days')} />}</Field>
        <Field label="Counting">{(a) => <Select {...a} value={form.period_type} onChange={set('period_type')}><option value="calendar">Calendar days</option><option value="working_days">Working days</option></Select>}</Field>
        <Field label="Legal basis" className="sm:col-span-2">{(a) => <Input {...a} value={form.legal_basis} onChange={set('legal_basis')} />}</Field>
        <Field label="Notes" className="sm:col-span-2">{(a) => <Textarea {...a} rows={2} value={form.notes} onChange={set('notes')} />}</Field>
        <Checkbox label="Active" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
      </form>
    </Dialog>
  )
}

function HolidaysPanel() {
  const [year, setYear] = useState(new Date().getFullYear())
  const holidays = useHolidays(year)
  const add = useAddHoliday()
  const remove = useDeleteHoliday()
  const [form, setForm] = useState({ date: '', name: '', type: 'special_non_working' })
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await add.mutateAsync({ ...form, type: form.type as 'regular' })
      setForm((f) => ({ ...f, date: '', name: '' }))
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Card>
      <CardHeader
        title="Non-working days"
        description="Deadlines falling on these days move to the next working day. Add proclaimed holidays (e.g. Eid'l Fitr) and court closures as soon as they are announced."
        actions={
          <Select aria-label="Year" value={year} onChange={(e) => setYear(Number(e.target.value))} className="w-28">
            {[-1, 0, 1, 2].map((d) => <option key={d} value={new Date().getFullYear() + d}>{new Date().getFullYear() + d}</option>)}
          </Select>
        }
      />
      <form onSubmit={submit} className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-start">
        <Input aria-label="Date" type="date" required value={form.date} onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} className="sm:w-44" aria-invalid={!!error?.field('date')} />
        <Input aria-label="Name" required placeholder="Holiday or closure name" value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} className="flex-1" />
        <Select aria-label="Type" value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))} className="sm:w-56">
          <option value="regular">Regular holiday</option>
          <option value="special_non_working">Special non-working day</option>
          <option value="court_closure">Court closure</option>
        </Select>
        <Button type="submit" loading={add.isPending}>Add</Button>
      </form>
      {error && <p role="alert" className="px-4 pt-3 text-sm text-danger">{error.field('date') ?? error.message}</p>}
      {holidays.isPending ? <PageLoader /> : holidays.isError ? <ErrorState error={holidays.error} /> : holidays.data.length === 0 ? <EmptyState title={`No holidays recorded for ${year}`} /> : (
        <ul className="divide-y divide-outline-variant">
          {holidays.data.map((h) => (
            <li key={h.id} className="flex items-center gap-4 px-5 py-2.5">
              <span className="w-32 text-sm tabular-nums">{date(h.date)}</span>
              <span className="flex-1 text-sm font-medium">{h.name}</span>
              <Badge tone={h.type === 'regular' ? 'primary' : h.type === 'court_closure' ? 'warning' : 'neutral'}>{h.type.replace(/_/g, ' ')}</Badge>
              <IconButton label={`Remove ${h.name}`} onClick={() => remove.mutate(h.id)}><Trash2 className="size-4" /></IconButton>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function WorkflowsPanel() {
  const workflows = useWorkflows()
  const remove = useDeleteWorkflow()
  const [editing, setEditing] = useState<WorkflowTemplate | 'new' | null>(null)

  return (
    <Card>
      <CardHeader title="Case workflows" description="Checklists applied automatically when a matter of the case type is opened." actions={<Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add workflow</Button>} />
      {workflows.isPending ? <PageLoader /> : workflows.isError ? <ErrorState error={workflows.error} /> : workflows.data.length === 0 ? <EmptyState title="No workflows yet" /> : (
        <ul className="divide-y divide-outline-variant">
          {workflows.data.map((w) => (
            <li key={w.id} className="flex items-start gap-4 px-5 py-4">
              <div className="flex-1">
                <p className="font-medium">{w.name} <Badge>{w.case_type}</Badge> {!w.is_active && <Badge>Inactive</Badge>}</p>
                <ol className="mt-1 list-inside list-decimal text-sm text-on-surface-variant">
                  {w.tasks.map((t, i) => <li key={i}>{t.title} <span className="text-xs">(day {t.days_offset})</span></li>)}
                </ol>
              </div>
              <IconButton label={`Edit ${w.name}`} onClick={() => setEditing(w)}><Pencil className="size-4" /></IconButton>
              <IconButton label={`Delete ${w.name}`} onClick={() => remove.mutate(w.id)}><Trash2 className="size-4" /></IconButton>
            </li>
          ))}
        </ul>
      )}
      {editing && <WorkflowDialog workflow={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

function WorkflowDialog({ workflow, onClose }: { workflow?: WorkflowTemplate; onClose: () => void }) {
  const lookups = useLookups()
  const save = useSaveWorkflow(workflow?.id)
  const [name, setName] = useState(workflow?.name ?? '')
  const [caseType, setCaseType] = useState(workflow?.case_type ?? '')
  const [active, setActive] = useState(workflow?.is_active ?? true)
  const [tasks, setTasks] = useState(workflow?.tasks.map((t) => ({ title: t.title, days_offset: String(t.days_offset) })) ?? [{ title: '', days_offset: '7' }])
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await save.mutateAsync({ name, case_type: caseType, is_active: active, tasks: tasks.filter((t) => t.title.trim()).map((t) => ({ title: t.title, days_offset: Number(t.days_offset), kind: 'task' })) })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title={workflow ? 'Edit workflow' : 'Add workflow'} size="lg" footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="workflow-form" loading={save.isPending}>Save</Button></>}>
      <form id="workflow-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error?.message} />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name" required>{(a) => <Input {...a} required value={name} onChange={(e) => setName(e.target.value)} />}</Field>
          <Field label="Case type" required>{(a) => <Select {...a} required value={caseType} onChange={(e) => setCaseType(e.target.value)}><option value="">Select…</option>{lookups.data?.case_types.map((t) => <option key={t}>{t}</option>)}</Select>}</Field>
        </div>
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-medium">Tasks (days after the matter is opened)</legend>
          {tasks.map((t, i) => (
            <div key={i} className="grid grid-cols-[1fr_6rem_auto] gap-2">
              <Input aria-label={`Task ${i + 1}`} value={t.title} onChange={(e) => setTasks((all) => all.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} placeholder="Task" />
              <Input aria-label={`Task ${i + 1} day`} type="number" min={0} value={t.days_offset} onChange={(e) => setTasks((all) => all.map((x, j) => (j === i ? { ...x, days_offset: e.target.value } : x)))} />
              <IconButton label="Remove task" onClick={() => setTasks((all) => all.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
            </div>
          ))}
          <Button variant="text" size="sm" className="self-start" icon={<Plus className="size-4" />} onClick={() => setTasks((all) => [...all, { title: '', days_offset: '7' }])}>Add task</Button>
        </fieldset>
        <Checkbox label="Active" checked={active} onChange={(e) => setActive(e.target.checked)} />
      </form>
    </Dialog>
  )
}

function AuditPanel() {
  const [page, setPage] = useState(1)
  const [type, setType] = useState('')
  const log = useAuditLog(page, type)

  return (
    <Card>
      <CardHeader
        title="Audit log"
        description="Who changed what, and when (Data Privacy Act accountability). Entries are permanent."
        actions={
          <Select aria-label="Record type" value={type} onChange={(e) => { setType(e.target.value); setPage(1) }} className="w-40">
            <option value="">All records</option>
            <option value="client">Clients</option>
            <option value="matter">Matters</option>
            <option value="document">Documents</option>
            <option value="deadline">Deadlines</option>
            <option value="invoice">Invoices</option>
            <option value="user">Users</option>
          </Select>
        }
      />
      {log.isPending ? <PageLoader /> : log.isError ? <ErrorState error={log.error} /> : (
        <Table caption="Audit log">
          <thead><tr><Th>When</Th><Th>Who</Th><Th>Action</Th><Th>Record</Th><Th>Changes</Th></tr></thead>
          <tbody>
            {log.data.data.map((e) => (
              <tr key={e.id}>
                <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(e.created_at)}</Td>
                <Td>{e.actor?.name ?? 'System'}</Td>
                <Td><Badge>{e.action.replace(/_/g, ' ')}</Badge></Td>
                <Td className="whitespace-nowrap">{e.subject_type ? `${e.subject_type} #${e.subject_id}` : '—'}</Td>
                <Td className="max-w-md"><ChangeSummary changes={e.changes} /></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <Pagination page={log.data} onPage={setPage} />
    </Card>
  )
}

function ChangeSummary({ changes }: { changes: Record<string, unknown> | null }) {
  if (!changes) return <span className="text-on-surface-variant">—</span>
  const after = (changes.after ?? changes) as Record<string, unknown>
  const before = (changes.before ?? {}) as Record<string, unknown>
  const keys = Object.keys(after).filter((k) => !['id', 'created_at', 'updated_at', 'firm_id'].includes(k)).slice(0, 4)
  return (
    <ul className="text-xs text-on-surface-variant">
      {keys.map((k) => (
        <li key={k} className="truncate">
          <span className="font-medium text-on-surface">{k}</span>: {k in before ? `${String(before[k] ?? '∅')} → ` : ''}{String(after[k] ?? '∅')}
        </li>
      ))}
    </ul>
  )
}
