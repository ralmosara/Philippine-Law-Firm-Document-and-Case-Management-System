import { Landmark, Pencil, Plus, Trash2, Users } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, SearchInput, Select } from '@/shared/ui/Form'
import { Card, DescriptionList, PageHeader, Table, Tabs, Td, Th, Tr } from '@/shared/ui/Layout'
import { useContact, useContacts, useCourt, useCourts, useDeleteContact, useDeleteCourt, type Contact, type Court } from '../api'
import { ContactDialog, CourtDialog } from './DirectoryDialogs'

type Tab = 'courts' | 'people'

/** Courts and branches, and the people the firm deals with in them. */
export function DirectoryPage() {
  const [tab, setTab] = useUrlState('tab', 'courts')
  return (
    <>
      <PageHeader title="Directory" description="Courts and branches, judges, clerks of court, prosecutors, opposing counsel and experts. Link them to matters; opposing counsel on a matter are included in conflict checks." />
      <Tabs<Tab> label="Directory sections" value={tab as Tab} onChange={setTab} tabs={[{ value: 'courts', label: 'Courts' }, { value: 'people', label: 'People' }]} />
      {tab === 'courts' ? <CourtsTab /> : <PeopleTab />}
    </>
  )
}

function CourtsTab() {
  const abilities = useAbilities()
  const [search, setSearch] = useUrlState('q', '')
  const query = useCourts(search)
  const [editing, setEditing] = useState<Court | 'new' | null>(null)
  const [open, setOpen] = useState<number | null>(null)

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-outline-variant p-3">
        <div className="min-w-0 flex-1"><SearchInput value={search} onChange={setSearch} placeholder="Search courts, branches, cities" label="Search courts" /></div>
        {abilities.work_matters && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add court</Button>}
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? (
        <EmptyState icon={<Landmark className="size-6" />} title={search ? 'No court matches' : 'No courts yet'} description="Add the courts and branches where the firm appears." />
      ) : (
        <Table caption="Courts">
          <thead><tr><Th>Court</Th><Th>Branch</Th><Th>Contact</Th><Th align="right">People</Th><Th align="right">Matters</Th></tr></thead>
          <tbody>
            {query.data.data.map((c) => (
              <Tr key={c.id} onClick={() => setOpen(c.id)}>
                <Td><div className="font-medium">{c.label}</div><div className="text-xs text-on-surface-variant">{c.level_label}</div></Td>
                <Td>{c.branch ?? '—'}</Td>
                <Td className="text-on-surface-variant">{c.phone ?? c.email ?? '—'}</Td>
                <Td align="right">{c.contacts_count}</Td>
                <Td align="right">{c.matters_count}</Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      )}
      {editing && query.data && <CourtDialog court={editing === 'new' ? undefined : editing} levels={query.data.levels} onClose={() => setEditing(null)} />}
      {open !== null && <CourtDetail id={open} onClose={() => setOpen(null)} onEdit={(c) => { setOpen(null); setEditing(c) }} />}
    </Card>
  )
}

function CourtDetail({ id, onClose, onEdit }: { id: number; onClose: () => void; onEdit: (c: Court) => void }) {
  const abilities = useAbilities()
  const query = useCourt(id)
  const kinds = useContacts().data?.kinds ?? {}
  const remove = useDeleteCourt()
  const [adding, setAdding] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const c = query.data

  return (
    <Dialog open onClose={onClose} size="lg" title={c ? `${c.label}${c.branch ? `, ${c.branch}` : ''}` : 'Court'}
      footer={c && abilities.work_matters && (
        <>
          <Button variant="text" icon={<Trash2 className="size-4" />} onClick={() => setConfirming(true)}>Remove</Button>
          <Button variant="outlined" icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>Add person</Button>
          <Button icon={<Pencil className="size-4" />} onClick={() => onEdit(c)}>Edit</Button>
        </>
      )}>
      {query.isPending ? <PageLoader /> : query.isError || !c ? <ErrorState error={query.error} /> : (
        <div className="flex flex-col gap-4 text-sm">
          <DescriptionList items={[{ label: 'Level', value: c.level_label }, { label: 'Address', value: c.address }, { label: 'Phone', value: c.phone }, { label: 'Email', value: c.email }]} />
          {c.notes && <p className="whitespace-pre-line text-on-surface-variant">{c.notes}</p>}
          <section>
            <h3 className="mb-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">People</h3>
            {c.contacts.length === 0 ? <p className="text-on-surface-variant">No one on file yet.</p> : (
              <ul className="divide-y divide-outline-variant rounded-[3px] border border-outline-variant">
                {c.contacts.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                    <span>{p.display_name} <span className="text-xs text-on-surface-variant">· {p.kind_label}</span>{!p.is_active && <Badge className="ml-1">Inactive</Badge>}</span>
                    <span className="text-xs text-on-surface-variant">{p.phone ?? p.email ?? ''}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
          <section>
            <h3 className="mb-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Matters here</h3>
            {c.matters.length === 0 ? <p className="text-on-surface-variant">None linked.</p> : (
              <ul className="flex flex-col gap-1">{c.matters.map((m) => <li key={m.id}><Link to={`/matters/${m.id}`} className="text-primary hover:underline">{m.reference}</Link> · {m.title}</li>)}</ul>
            )}
          </section>
        </div>
      )}
      {adding && <ContactDialog kinds={kinds} defaults={{ kind: 'judge', title: 'Hon.', court_id: id }} onClose={() => setAdding(false)} />}
      <ConfirmDialog open={confirming} onClose={() => setConfirming(false)} title="Remove this court?" destructive confirmLabel="Remove" loading={remove.isPending}
        description="Matters keep the court's name as written; people stay in the directory without a court."
        onConfirm={() => remove.mutate(id, { onSuccess: onClose })} />
    </Dialog>
  )
}

function PeopleTab() {
  const abilities = useAbilities()
  const [search, setSearch] = useUrlState('q', '')
  const [kind, setKind] = useUrlState('kind', '')
  const [inactive, setInactive] = useState(false)
  const query = useContacts({ search, kind, include_inactive: inactive })
  const [editing, setEditing] = useState<Contact | 'new' | null>(null)
  const [open, setOpen] = useState<number | null>(null)
  const kinds = query.data?.kinds ?? {}

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-2 border-b border-outline-variant p-3">
        <div className="min-w-0 flex-1"><SearchInput value={search} onChange={setSearch} placeholder="Search names, firms, offices, emails" label="Search people" /></div>
        <div className="w-full sm:w-48">
          <Select aria-label="Kind" value={kind} onChange={(e) => setKind(e.target.value)}>
            <option value="">Everyone</option>
            {Object.entries(kinds).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
        </div>
        <Checkbox label="Include inactive" checked={inactive} onChange={(e) => setInactive(e.target.checked)} />
        {abilities.work_matters && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add person</Button>}
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? (
        <EmptyState icon={<Users className="size-6" />} title={search || kind ? 'No one matches' : 'No one yet'} />
      ) : (
        <Table caption="People">
          <thead><tr><Th>Name</Th><Th>Kind</Th><Th>Court / firm</Th><Th>Contact</Th><Th align="right">Matters</Th></tr></thead>
          <tbody>
            {query.data.data.map((c) => (
              <Tr key={c.id} onClick={() => setOpen(c.id)}>
                <Td><span className="font-medium">{c.display_name}</span>{!c.is_active && <Badge className="ml-1">Inactive</Badge>}</Td>
                <Td className="text-on-surface-variant">{c.kind_label}</Td>
                <Td>{c.court ?? c.organization ?? '—'}</Td>
                <Td className="text-on-surface-variant">{c.phone ?? c.email ?? '—'}</Td>
                <Td align="right">{c.matters_count}</Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      )}
      {editing && <ContactDialog contact={editing === 'new' ? undefined : editing} kinds={kinds} onClose={() => setEditing(null)} />}
      {open !== null && <ContactDetail id={open} onClose={() => setOpen(null)} onEdit={(c) => { setOpen(null); setEditing(c) }} />}
    </Card>
  )
}

function ContactDetail({ id, onClose, onEdit }: { id: number; onClose: () => void; onEdit: (c: Contact) => void }) {
  const abilities = useAbilities()
  const query = useContact(id)
  const remove = useDeleteContact()
  const c = query.data

  return (
    <Dialog open onClose={onClose} title={c?.display_name ?? 'Contact'} description={c ? [c.kind_label, c.court ?? c.organization].filter(Boolean).join(' · ') : undefined}
      footer={c && abilities.work_matters && (
        <>
          <Button variant="text" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate(id, { onSuccess: onClose })}>{c.matters_count ? 'Mark inactive' : 'Remove'}</Button>
          <Button icon={<Pencil className="size-4" />} onClick={() => onEdit(c)}>Edit</Button>
        </>
      )}>
      {query.isPending ? <PageLoader /> : query.isError || !c ? <ErrorState error={query.error} /> : (
        <div className="flex flex-col gap-4 text-sm">
          <DescriptionList items={[
            { label: 'Phone', value: c.phone ? <a href={`tel:${c.phone}`} className="text-primary hover:underline">{c.phone}</a> : null },
            { label: 'Email', value: c.email ? <a href={`mailto:${c.email}`} className="text-primary hover:underline">{c.email}</a> : null },
            { label: 'Address', value: c.address },
            { label: 'Roll no.', value: c.roll_number },
          ]} />
          {c.notes && <p className="whitespace-pre-line text-on-surface-variant">{c.notes}</p>}
          <section>
            <h3 className="mb-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Matters</h3>
            {c.matters.length === 0 ? <p className="text-on-surface-variant">Not on any matter.</p> : (
              <ul className="flex flex-col gap-1">{c.matters.map((l, i) => <li key={i}><Link to={`/matters/${l.matter.id}`} className="text-primary hover:underline">{l.matter.reference}</Link> · {l.matter.title} <span className="text-xs text-on-surface-variant">({l.role_label})</span></li>)}</ul>
            )}
          </section>
        </div>
      )}
    </Dialog>
  )
}
