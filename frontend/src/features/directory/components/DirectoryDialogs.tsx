import { useState, type FormEvent } from 'react'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { useCourts, useSaveContact, useSaveCourt, type Contact, type ContactInput, type Court, type CourtInput } from '../api'

export function CourtDialog({ court, levels, onClose }: { court?: Court; levels: Record<string, string>; onClose: () => void }) {
  const save = useSaveCourt(court?.id)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState<CourtInput>({
    level: court?.level ?? 'rtc', name: court?.name ?? 'Regional Trial Court', branch: court?.branch ?? '', station: court?.station ?? '',
    address: court?.address ?? '', email: court?.email ?? '', phone: court?.phone ?? '', notes: court?.notes ?? '',
  })
  const set = <K extends keyof CourtInput>(k: K, v: CourtInput[K]) => setForm({ ...form, [k]: v })
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ ...form, email: form.email || null }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="lg" title={court ? 'Edit court' : 'Add a court'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="court-form" loading={save.isPending}>Save</Button></>}>
      <form id="court-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="Level">
          {(a) => (
            <Select {...a} value={form.level} onChange={(e) => {
              const level = e.target.value
              // Suggest the usual name when the level changes and the name was a default.
              setForm({ ...form, level, name: Object.values(levels).includes(form.name) && !['agency', 'other'].includes(level) ? (levels[level] ?? form.name) : form.name })
            }}>
              {Object.entries(levels).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Name" required error={error?.field('name')}>{(a) => <Input {...a} required value={form.name} onChange={(e) => set('name', e.target.value)} />}</Field>
        <Field label="Branch" error={error?.field('branch')}>{(a) => <Input {...a} value={form.branch ?? ''} onChange={(e) => set('branch', e.target.value)} placeholder="Branch 143" />}</Field>
        <Field label="Station (city or municipality)" error={error?.field('station')}>{(a) => <Input {...a} value={form.station ?? ''} onChange={(e) => set('station', e.target.value)} placeholder="Makati City" />}</Field>
        <Field label="Address" className="sm:col-span-2">{(a) => <Input {...a} value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} placeholder="Hall of Justice, …" />}</Field>
        <Field label="Email" error={error?.field('email')}>{(a) => <Input {...a} type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} />}</Field>
        <Field label="Phone">{(a) => <Input {...a} value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} />}</Field>
        <Field label="Notes" className="sm:col-span-2" hint="Hearing days, e-filing address, how the branch likes things done.">{(a) => <Textarea {...a} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />}</Field>
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

export function ContactDialog({ contact, kinds, defaults, onClose, onSaved }: { contact?: Contact; kinds: Record<string, string>; defaults?: Partial<ContactInput>; onClose: () => void; onSaved?: (c: Contact) => void }) {
  const courts = useCourts()
  const save = useSaveContact(contact?.id)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState<ContactInput>({
    kind: contact?.kind ?? defaults?.kind ?? 'opposing_counsel', name: contact?.name ?? '', title: contact?.title ?? defaults?.title ?? '',
    organization: contact?.organization ?? '', court_id: contact?.court_id ?? defaults?.court_id ?? null, email: contact?.email ?? '', phone: contact?.phone ?? '',
    address: contact?.address ?? '', roll_number: contact?.roll_number ?? '', notes: contact?.notes ?? '', is_active: contact?.is_active ?? true,
  })
  const set = <K extends keyof ContactInput>(k: K, v: ContactInput[K]) => setForm({ ...form, [k]: v })
  const atCourt = ['judge', 'clerk_of_court', 'sheriff', 'prosecutor'].includes(form.kind)
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ ...form, email: form.email || null }, { onSuccess: (c) => { onSaved?.(c); onClose() } })
  }

  return (
    <Dialog open onClose={onClose} size="lg" title={contact ? 'Edit contact' : 'Add a contact'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="contact-form" loading={save.isPending}>Save</Button></>}>
      <form id="contact-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-[8rem_1fr_1fr]">
        <Field label="Kind" className="sm:col-span-3">
          {(a) => <Select {...a} value={form.kind} onChange={(e) => set('kind', e.target.value)}>{Object.entries(kinds).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}
        </Field>
        <Field label="Title">{(a) => <Input {...a} value={form.title ?? ''} onChange={(e) => set('title', e.target.value)} placeholder={form.kind === 'judge' ? 'Hon.' : 'Atty.'} />}</Field>
        <Field label="Name" className="sm:col-span-2" required error={error?.field('name')}>{(a) => <Input {...a} required value={form.name} onChange={(e) => set('name', e.target.value)} />}</Field>
        {atCourt ? (
          <Field label={form.kind === 'prosecutor' ? 'Court (optional)' : 'Court'} className="sm:col-span-3" error={error?.field('court_id')}>
            {(a) => (
              <Select {...a} value={form.court_id ?? ''} onChange={(e) => set('court_id', Number(e.target.value) || null)}>
                <option value="">None</option>
                {courts.data?.data.map((c) => <option key={c.id} value={c.id}>{c.label}{c.branch ? `, ${c.branch}` : ''}</option>)}
              </Select>
            )}
          </Field>
        ) : null}
        <Field label={form.kind === 'opposing_counsel' ? 'Law firm' : 'Office / organization'} className="sm:col-span-2">{(a) => <Input {...a} value={form.organization ?? ''} onChange={(e) => set('organization', e.target.value)} />}</Field>
        {form.kind === 'opposing_counsel' ? <Field label="Roll no.">{(a) => <Input {...a} value={form.roll_number ?? ''} onChange={(e) => set('roll_number', e.target.value)} />}</Field> : <div className="hidden sm:block" />}
        <Field label="Email" className="sm:col-span-2" error={error?.field('email')}>{(a) => <Input {...a} type="email" value={form.email ?? ''} onChange={(e) => set('email', e.target.value)} />}</Field>
        <Field label="Phone">{(a) => <Input {...a} value={form.phone ?? ''} onChange={(e) => set('phone', e.target.value)} />}</Field>
        <Field label="Address" className="sm:col-span-3">{(a) => <Input {...a} value={form.address ?? ''} onChange={(e) => set('address', e.target.value)} />}</Field>
        <Field label="Notes" className="sm:col-span-3">{(a) => <Textarea {...a} value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />}</Field>
        {contact && <div className="sm:col-span-3"><Checkbox label="Active (untick when they retire, move or leave the office)" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} /></div>}
        <div className="sm:col-span-3"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}
