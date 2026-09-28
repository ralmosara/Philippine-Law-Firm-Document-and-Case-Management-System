import { Phone, Plus, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Select } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useContacts, useCourts, useLinkContact, useMatterContacts, useSetMatterCourt, useUnlinkContact } from '../api'
import { ContactDialog } from './DirectoryDialogs'

/** The matter's court from the directory, and the judge, clerk, prosecutor and opposing counsel on it. */
export function MatterContactsCard({ matterId, canEdit }: { matterId: number; canEdit: boolean }) {
  const query = useMatterContacts(matterId)
  const unlink = useUnlinkContact(matterId)
  const [dialog, setDialog] = useState<'court' | 'person' | null>(null)

  return (
    <Card>
      <CardHeader
        title="Court & contacts"
        actions={canEdit && (
          <>
            <Button size="sm" variant="text" onClick={() => setDialog('court')}>{query.data?.court ? 'Change court' : 'Set court'}</Button>
            <Button size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => setDialog('person')}>Person</Button>
          </>
        )}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : (
        <div className="flex flex-col text-sm">
          <div className="border-b border-outline-variant px-4 py-2.5">
            {query.data.court ? (
              <>
                <div className="font-medium">{query.data.court.label}{query.data.court.branch && `, ${query.data.court.branch}`}</div>
                <div className="text-xs text-on-surface-variant">{[query.data.court.address, query.data.court.phone].filter(Boolean).join(' · ') || query.data.court.level_label}</div>
              </>
            ) : <span className="text-on-surface-variant">No court from the directory. <Link to="/directory" className="text-primary hover:underline">Open the directory</Link></span>}
          </div>
          {query.data.contacts.length === 0 ? <p className="px-4 py-2.5 text-on-surface-variant">No judge, clerk, prosecutor or opposing counsel linked.</p> : (
            <ul className="divide-y divide-outline-variant">
              {query.data.contacts.map((l) => (
                <li key={l.id} className="flex items-center gap-2 px-4 py-2">
                  <div className="min-w-0 flex-1">
                    <div className="truncate">{l.contact.display_name}{!l.contact.is_active && <span className="text-xs text-on-surface-variant"> (inactive)</span>}</div>
                    <div className="truncate text-xs text-on-surface-variant">{l.role_label}{l.contact.organization && ` · ${l.contact.organization}`}</div>
                  </div>
                  {l.contact.phone && <a href={`tel:${l.contact.phone}`} aria-label={`Call ${l.contact.display_name}`} className="inline-flex size-8 items-center justify-center rounded-full text-primary hover:bg-on-surface/8"><Phone className="size-4" /></a>}
                  {canEdit && <IconButton label={`Remove ${l.contact.display_name}`} onClick={() => unlink.mutate(l.id)}><X className="size-4" /></IconButton>}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
      {dialog === 'court' && <CourtPicker matterId={matterId} current={query.data?.court?.id ?? null} onClose={() => setDialog(null)} />}
      {dialog === 'person' && <PersonPicker matterId={matterId} onClose={() => setDialog(null)} />}
    </Card>
  )
}

function CourtPicker({ matterId, current, onClose }: { matterId: number; current: number | null; onClose: () => void }) {
  const courts = useCourts()
  const save = useSetMatterCourt(matterId)
  const [id, setId] = useState<number | null>(current)

  return (
    <Dialog open onClose={onClose} title="Court" description="The court's name, station and branch are copied to the matter for captions, with its judge if one is on file."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={save.isPending} onClick={() => save.mutate(id, { onSuccess: onClose })}>Save</Button></>}>
      {courts.isPending ? <PageLoader /> : (
        <Field label="Court">
          {(a) => (
            <Select {...a} value={id ?? ''} onChange={(e) => setId(Number(e.target.value) || null)}>
              <option value="">None</option>
              {courts.data?.data.map((c) => <option key={c.id} value={c.id}>{c.label}{c.branch ? `, ${c.branch}` : ''}</option>)}
            </Select>
          )}
        </Field>
      )}
      {courts.data?.data.length === 0 && <p className="mt-2 text-sm text-on-surface-variant">No courts yet. <Link to="/directory" className="text-primary hover:underline">Add them in the directory.</Link></p>}
    </Dialog>
  )
}

function PersonPicker({ matterId, onClose }: { matterId: number; onClose: () => void }) {
  const contacts = useContacts()
  const link = useLinkContact(matterId)
  const error = link.error ? ApiError.from(link.error) : null
  const [contactId, setContactId] = useState<number | null>(null)
  const [role, setRole] = useState('opposing_counsel')
  const [creating, setCreating] = useState(false)
  const data = contacts.data

  if (creating && data) {
    return <ContactDialog kinds={data.kinds} defaults={{ kind: role, title: role === 'judge' ? 'Hon.' : 'Atty.' }} onClose={() => setCreating(false)}
      onSaved={(c) => link.mutate({ contact_id: c.id, role }, { onSuccess: onClose })} />
  }

  return (
    <Dialog open onClose={onClose} title="Add someone to this matter"
      footer={<><Button variant="text" onClick={() => setCreating(true)}>New contact…</Button><Button disabled={!contactId} loading={link.isPending} onClick={() => contactId && link.mutate({ contact_id: contactId, role }, { onSuccess: onClose })}>Add</Button></>}>
      {!data ? <PageLoader /> : (
        <div className="grid grid-cols-1 gap-3">
          <Field label="As">{(a) => <Select {...a} value={role} onChange={(e) => setRole(e.target.value)}>{Object.entries(data.roles).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}</Field>
          <Field label="Who">
            {(a) => (
              <Select {...a} value={contactId ?? ''} onChange={(e) => setContactId(Number(e.target.value) || null)}>
                <option value="">Choose from the directory…</option>
                {[...data.data].sort((x, y) => Number(y.kind === role) - Number(x.kind === role)).map((c) => (
                  <option key={c.id} value={c.id}>{c.display_name} · {c.kind_label}{c.court ? ` · ${c.court}` : c.organization ? ` · ${c.organization}` : ''}</option>
                ))}
              </Select>
            )}
          </Field>
          <FormError message={error?.message ?? null} />
        </div>
      )}
    </Dialog>
  )
}
