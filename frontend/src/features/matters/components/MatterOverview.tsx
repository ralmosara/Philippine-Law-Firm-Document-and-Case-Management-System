import { ChevronDown, Pencil, Plus, Trash2, UserX } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useAbilities, useLookups } from '@/features/auth/session'
import { DeadlineList } from '@/features/deadlines/components/DeadlineList'
import { ApiError } from '@/shared/api/axios'
import type { Deadline, Matter, MatterParty, MatterStatus } from '@/shared/api/types'
import { date, dateTime } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, DescriptionList } from '@/shared/ui/Layout'
import { useDeleteParty, useMatterTimeline, useSaveParty, useTransitionMatter } from '../api'

export function MatterOverview({ matter, upcoming, onShowDeadlines }: { matter: Matter; upcoming: Deadline[]; onShowDeadlines: () => void }) {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <div className="flex flex-col gap-6 lg:col-span-2">
        <Card>
          <CardHeader title="Case details" />
          <div className="p-5">
            <DescriptionList
              items={[
                { label: 'Case type', value: matter.case_type },
                { label: 'Case number', value: matter.case_number },
                { label: 'Court', value: matter.court },
                { label: 'Branch', value: matter.court_branch },
                { label: 'Presiding judge', value: matter.judge },
                { label: 'Responsible lawyer', value: matter.responsible_lawyer?.name ?? 'Unassigned' },
                { label: 'Opened', value: date(matter.opened_at) },
                { label: 'Closed', value: matter.closed_at ? date(matter.closed_at) : null },
              ]}
            />
            {matter.description && <p className="mt-6 text-sm whitespace-pre-line text-on-surface-variant">{matter.description}</p>}
          </div>
        </Card>
        <PartiesCard matter={matter} />
      </div>

      <Card className="self-start">
        <CardHeader title="Coming up" actions={<Button variant="text" size="sm" onClick={onShowDeadlines}>View all</Button>} />
        <DeadlineList deadlines={upcoming} compact emptyText="Nothing pending." />
      </Card>
    </div>
  )
}

function PartiesCard({ matter }: { matter: Matter }) {
  const abilities = useAbilities()
  const [editing, setEditing] = useState<MatterParty | 'new' | null>(null)
  const [removing, setRemoving] = useState<MatterParty | null>(null)
  const remove = useDeleteParty(matter.id)
  const parties = matter.parties ?? []

  return (
    <Card>
      <CardHeader
        title="Parties"
        description="Checked in every future conflict-of-interest search."
        actions={abilities.work_matters && <Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add party</Button>}
      />
      {parties.length === 0 ? (
        <EmptyState icon={<UserX className="size-6" />} title="No parties recorded" description="Add the adverse party and counsel so conflicts can be detected." />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {parties.map((p) => (
            <li key={p.id} className="flex items-center gap-4 px-5 py-3">
              <div className="min-w-0 flex-1">
                <p className="font-medium">{p.name}</p>
                <p className="text-sm text-on-surface-variant">{[p.counsel_name && `Counsel: ${p.counsel_name}`, p.contact].filter(Boolean).join(' · ') || '—'}</p>
              </div>
              <Badge tone={p.is_adverse ? 'danger' : 'neutral'}>{p.role_label}</Badge>
              {abilities.work_matters && (
                <span className="flex">
                  <IconButton label={`Edit ${p.name}`} onClick={() => setEditing(p)}><Pencil className="size-4" /></IconButton>
                  <IconButton label={`Remove ${p.name}`} onClick={() => setRemoving(p)}><Trash2 className="size-4" /></IconButton>
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      {editing && <PartyDialog matterId={matter.id} party={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        title="Remove party?"
        description={<>Remove <strong>{removing?.name}</strong> from this matter? They will no longer appear in conflict searches for it.</>}
        destructive
        confirmLabel="Remove"
        loading={remove.isPending}
        onConfirm={() => removing && remove.mutate(removing.id, { onSuccess: () => setRemoving(null) })}
      />
    </Card>
  )
}

function PartyDialog({ matterId, party, onClose }: { matterId: number; party?: MatterParty; onClose: () => void }) {
  const lookups = useLookups()
  const save = useSaveParty(matterId, party?.id)
  const [form, setForm] = useState({ role: party?.role ?? 'adverse_party', name: party?.name ?? '', counsel_name: party?.counsel_name ?? '', contact: party?.contact ?? '', notes: party?.notes ?? '' })
  const [error, setError] = useState<ApiError | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await save.mutateAsync(form)
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title={party ? 'Edit party' : 'Add party'} footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="party-form" loading={save.isPending}>Save</Button></>}>
      <form id="party-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} />
        <Field label="Role" required>
          {(a) => <Select {...a} value={form.role} onChange={set('role')}>{lookups.data?.party_roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}</Select>}
        </Field>
        <Field label="Name" required error={error?.field('name')}>
          {(a) => <Input {...a} required value={form.name} onChange={set('name')} />}
        </Field>
        <Field label="Counsel">
          {(a) => <Input {...a} value={form.counsel_name} onChange={set('counsel_name')} />}
        </Field>
        <Field label="Contact">
          {(a) => <Input {...a} value={form.contact} onChange={set('contact')} />}
        </Field>
        <Field label="Notes">
          {(a) => <Textarea {...a} rows={2} value={form.notes} onChange={set('notes')} />}
        </Field>
      </form>
    </Dialog>
  )
}

/** Change status along the allowed transitions (the server enforces the same state machine). */
export function StatusMenu({ matter }: { matter: Matter }) {
  const [open, setOpen] = useState(false)
  const [target, setTarget] = useState<{ value: MatterStatus; label: string } | null>(null)
  const transition = useTransitionMatter(matter.id)

  if (matter.allowed_transitions.length === 0) return null

  return (
    <div className="relative">
      <Button variant="filled" onClick={() => setOpen((o) => !o)} aria-haspopup="menu" aria-expanded={open}>
        Change status <ChevronDown className="size-4" />
      </Button>
      {open && (
        <>
          <button type="button" aria-hidden="true" tabIndex={-1} className="fixed inset-0 z-10 cursor-default" onClick={() => setOpen(false)} />
          <div role="menu" className="absolute right-0 z-20 mt-2 w-52 rounded-xl border border-outline-variant bg-surface p-1 shadow-(--shadow-elevated)">
            {matter.allowed_transitions.map((t) => (
              <button key={t.value} type="button" role="menuitem" className="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-container" onClick={() => { setOpen(false); setTarget(t) }}>
                Move to {t.label}
              </button>
            ))}
          </div>
        </>
      )}
      <ConfirmDialog
        open={target !== null}
        onClose={() => setTarget(null)}
        title={`Move to ${target?.label}?`}
        description={<>The change from <strong>{matter.status_label}</strong> to <strong>{target?.label}</strong> is recorded in the matter history.</>}
        reasonLabel={target?.value === 'closed' ? 'Reason for closing' : 'Note (optional)'}
        reasonRequired={target?.value === 'closed'}
        destructive={target?.value === 'closed'}
        confirmLabel="Change status"
        loading={transition.isPending}
        onConfirm={(reason) => target && transition.mutate({ status: target.value, reason: reason || undefined }, { onSuccess: () => setTarget(null) })}
      />
    </div>
  )
}

export function MatterHistory({ matterId }: { matterId: number }) {
  const timeline = useMatterTimeline(matterId)

  return (
    <Card>
      <CardHeader title="Status history" description="Append-only record of every status change." />
      {timeline.isPending ? (
        <PageLoader />
      ) : timeline.isError ? (
        <ErrorState error={timeline.error} />
      ) : (
        <ol className="relative px-5 py-4">
          {timeline.data.map((event, i) => (
            <li key={event.id} className="relative flex gap-4 pb-6 last:pb-0">
              {i < timeline.data.length - 1 && <span aria-hidden="true" className="absolute top-3 left-[5px] h-full w-px bg-outline-variant" />}
              <span aria-hidden="true" className="relative mt-1.5 size-3 shrink-0 rounded-full bg-primary ring-4 ring-surface" />
              <div>
                <p className="text-sm font-medium">{event.from_label ? `${event.from_label} → ${event.to_label}` : `Opened as ${event.to_label}`}</p>
                <p className="text-xs text-on-surface-variant">{dateTime(event.created_at)}{event.changed_by && ` · ${event.changed_by.name}`}</p>
                {event.reason && <p className="mt-1 text-sm text-on-surface-variant">{event.reason}</p>}
              </div>
            </li>
          ))}
        </ol>
      )}
    </Card>
  )
}
