import { FileDown, Gavel, Paperclip, Plus, ScrollText, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useMatterFiles } from '@/features/documents/api'
import { ApiError } from '@/shared/api/axios'
import type { Matter } from '@/shared/api/types'
import { date, today } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, DownloadButton, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import {
  previewFormalOffer,
  STATUS_LABELS,
  useBulkExhibitStatus,
  useCreateFormalOffer,
  useDeleteExhibit,
  useExhibits,
  useNextMarking,
  useSaveExhibit,
  type Exhibit,
  type ExhibitSide,
  type ExhibitStatus,
} from '../api'

const TONES: Record<ExhibitStatus, 'primary' | 'success' | 'danger' | 'warning' | undefined> = {
  marked: undefined,
  offered: 'primary',
  admitted: 'success',
  denied: 'danger',
  withdrawn: 'warning',
}

/** The evidence in a case: each side's exhibits, their status in court, and the Formal Offer. */
export function EvidencePanel({ matter }: { matter: Matter }) {
  const abilities = useAbilities()
  const query = useExhibits(matter.id)
  const remove = useDeleteExhibit(matter.id)
  const [side, setSide] = useUrlState('side', 'ours')
  const [selected, setSelected] = useState<number[]>([])
  const [dialog, setDialog] = useState<{ kind: 'new'; parent: string | null } | { kind: 'edit'; exhibit: Exhibit } | { kind: 'ruling' } | { kind: 'offer' } | null>(null)
  const canEdit = abilities.work_matters

  const rows = useMemo(() => (query.data?.exhibits ?? []).filter((e) => e.side === side), [query.data, side])
  useEffect(() => setSelected([]), [side])

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const d = query.data
  const count = (s: ExhibitSide) => d.exhibits.filter((e) => e.side === s).length
  const toggle = (id: number) => setSelected((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]))
  const ours = side === 'ours'

  return (
    <Card>
      <CardHeader
        title="Evidence & exhibits"
        description={`Our client marks with ${d.letters.ours ? 'letters (A, B, C…)' : 'numbers (1, 2, 3…)'}; the other side with ${d.letters.adverse ? 'letters' : 'numbers'}. Sub-markings: A-1, A-2 or 1-a, 1-b.`}
        actions={
          <div className="flex flex-wrap gap-1.5">
            <DownloadButton href={`/api/v1/matters/${matter.id}/exhibits.csv`} size="sm" variant="outlined" icon={<FileDown className="size-4" />}>Exhibit list</DownloadButton>
            {canEdit && ours && <Button size="sm" variant="tonal" icon={<ScrollText className="size-4" />} onClick={() => setDialog({ kind: 'offer' })} disabled={!rows.some((e) => e.status === 'marked' || e.status === 'offered')}>Formal offer</Button>}
            {canEdit && <Button size="sm" icon={<Plus className="size-4" />} onClick={() => setDialog({ kind: 'new', parent: null })}>Mark exhibit</Button>}
          </div>
        }
      />
      <div className="flex flex-wrap items-center gap-2 border-b border-outline-variant px-3 py-2">
        <Select aria-label="Side" value={side} onChange={(e) => setSide(e.target.value)} className="w-56">
          <option value="ours">Our exhibits ({count('ours')})</option>
          <option value="adverse">Other side's exhibits ({count('adverse')})</option>
        </Select>
        {canEdit && selected.length > 0 && (
          <Button size="sm" variant="outlined" icon={<Gavel className="size-4" />} onClick={() => setDialog({ kind: 'ruling' })}>Record ruling for {selected.length}</Button>
        )}
      </div>

      {rows.length === 0 ? (
        <EmptyState title={ours ? 'No exhibits marked yet' : "None of the other side's exhibits recorded"} description={ours ? `The next one will be Exhibit "${d.next.ours}".` : 'Record their exhibits to track your objections and the court\'s rulings.'} />
      ) : (
        <Table caption="Exhibits">
          <thead>
            <tr>
              {canEdit && <Th><span className="sr-only">Select</span></Th>}
              <Th>Exhibit</Th>
              <Th>Description & purpose</Th>
              <Th>Witness</Th>
              <Th>Status</Th>
              <Th><span className="sr-only">Actions</span></Th>
            </tr>
          </thead>
          <tbody>
            {rows.map((e) => {
              const depth = e.marking.split('-').length - 1
              return (
                <tr key={e.id}>
                  {canEdit && <Td className="w-8"><Checkbox label="" aria-label={`Select Exhibit ${e.marking}`} checked={selected.includes(e.id)} onChange={() => toggle(e.id)} /></Td>}
                  <Td className="whitespace-nowrap font-semibold tabular-nums"><span style={{ paddingLeft: `${depth * 12}px` }}>"{e.marking}"</span></Td>
                  <Td>
                    <div>{e.description}</div>
                    {e.purpose && <div className="text-xs text-on-surface-variant">Purpose: {e.purpose}</div>}
                    {e.file_name && <div className="flex items-center gap-1 text-xs text-on-surface-variant"><Paperclip className="size-3" aria-hidden />{e.file_name}</div>}
                    {e.objection && <div className="text-xs text-danger">Objection: {e.objection}</div>}
                    {e.ruling && <div className="text-xs text-on-surface-variant">Ruling: {e.ruling}</div>}
                  </Td>
                  <Td className="text-on-surface-variant">{e.witness ?? '—'}</Td>
                  <Td className="whitespace-nowrap">
                    <Badge tone={TONES[e.status]}>{STATUS_LABELS[e.status]}</Badge>
                    {e.ruled_on && <div className="text-xs text-on-surface-variant">{date(e.ruled_on)}</div>}
                  </Td>
                  <Td align="right" className="whitespace-nowrap">
                    {canEdit && (
                      <>
                        <Button size="sm" variant="text" onClick={() => setDialog({ kind: 'new', parent: e.marking })}>Sub-mark</Button>
                        <Button size="sm" variant="text" onClick={() => setDialog({ kind: 'edit', exhibit: e })}>Edit</Button>
                        {!['admitted', 'denied'].includes(e.status) && (
                          <IconButton label={`Remove Exhibit ${e.marking}`} onClick={() => remove.mutate(e.id)}><Trash2 className="size-4" /></IconButton>
                        )}
                      </>
                    )}
                  </Td>
                </tr>
              )
            })}
          </tbody>
        </Table>
      )}

      {dialog?.kind === 'new' && <ExhibitDialog matter={matter} side={side as ExhibitSide} parent={dialog.parent} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'edit' && <ExhibitDialog matter={matter} side={dialog.exhibit.side} exhibit={dialog.exhibit} onClose={() => setDialog(null)} />}
      {dialog?.kind === 'ruling' && <RulingDialog matterId={matter.id} ids={selected} onClose={() => { setDialog(null); setSelected([]) }} />}
      {dialog?.kind === 'offer' && <FormalOfferDialog matterId={matter.id} exhibits={d.exhibits.filter((e) => e.side === 'ours' && (e.status === 'marked' || e.status === 'offered'))} onClose={() => setDialog(null)} />}
    </Card>
  )
}

function ExhibitDialog({ matter, side, parent = null, exhibit, onClose }: { matter: Matter; side: ExhibitSide; parent?: string | null; exhibit?: Exhibit; onClose: () => void }) {
  const files = useMatterFiles(matter.id)
  const next = useNextMarking(matter.id, side, parent, !exhibit)
  const save = useSaveExhibit(matter.id, exhibit?.id)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState({
    marking: exhibit?.marking ?? '',
    description: exhibit?.description ?? '',
    purpose: exhibit?.purpose ?? '',
    witness: exhibit?.witness ?? '',
    matter_file_id: exhibit?.matter_file_id ?? null,
    marked_on: exhibit?.marked_on ?? today(),
    status: exhibit?.status ?? ('marked' as ExhibitStatus),
    objection: exhibit?.objection ?? '',
    ruling: exhibit?.ruling ?? '',
  })
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm({ ...form, [key]: value })
  const witnesses = [matter.client?.name, ...(matter.parties ?? []).filter((p) => p.role === 'witness').map((p) => p.name)].filter(Boolean) as string[]

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(
      {
        ...(exhibit ? {} : { side, parent }),
        ...form,
        marking: form.marking || undefined,
        purpose: form.purpose || null,
        witness: form.witness || null,
        objection: form.objection || null,
        ruling: form.ruling || null,
        marked_on: form.marked_on || null,
      },
      { onSuccess: onClose },
    )
  }

  const title = exhibit ? `Exhibit "${exhibit.marking}"` : parent ? `Sub-mark Exhibit "${parent}"` : side === 'ours' ? 'Mark an exhibit' : "Record the other side's exhibit"

  return (
    <Dialog open onClose={onClose} size="lg" title={title}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="exhibit-form" loading={save.isPending}>Save</Button></>}>
      <form id="exhibit-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Field label="Marking" hint={exhibit ? undefined : 'Leave blank for the next one.'} error={error?.field('marking')}>
          {(a) => <Input {...a} value={form.marking} placeholder={next.data ?? ''} onChange={(e) => set('marking', e.target.value.trim())} maxLength={20} />}
        </Field>
        <Field label="Marked on" error={error?.field('marked_on')}>{(a) => <Input {...a} type="date" max={today()} value={form.marked_on ?? ''} onChange={(e) => set('marked_on', e.target.value)} />}</Field>
        <Field label="Status">
          {(a) => (
            <Select {...a} value={form.status} onChange={(e) => set('status', e.target.value as ExhibitStatus)}>
              {Object.entries(STATUS_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Description" className="sm:col-span-3" required error={error?.field('description')}>
          {(a) => <Input {...a} required value={form.description} onChange={(e) => set('description', e.target.value)} placeholder="Deed of Absolute Sale dated March 3, 2024" />}
        </Field>
        <Field label="Purpose" hint="What it is offered to prove; goes into the Formal Offer." className="sm:col-span-3" error={error?.field('purpose')}>
          {(a) => <Textarea {...a} value={form.purpose} onChange={(e) => set('purpose', e.target.value)} className="min-h-16" />}
        </Field>
        <Field label="Identified by" className="sm:col-span-2" error={error?.field('witness')}>
          {(a) => (
            <>
              <Input {...a} list="exhibit-witnesses" value={form.witness} onChange={(e) => set('witness', e.target.value)} />
              <datalist id="exhibit-witnesses">{witnesses.map((w) => <option key={w} value={w} />)}</datalist>
            </>
          )}
        </Field>
        <Field label="File" error={error?.field('matter_file_id')}>
          {(a) => (
            <Select {...a} value={form.matter_file_id ?? ''} onChange={(e) => set('matter_file_id', e.target.value ? Number(e.target.value) : null)}>
              <option value="">None</option>
              {files.data?.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
            </Select>
          )}
        </Field>
        <Field label={side === 'ours' ? "Other side's objection" : 'Our objection'} className="sm:col-span-3">
          {(a) => <Textarea {...a} value={form.objection} onChange={(e) => set('objection', e.target.value)} className="min-h-16" placeholder="Hearsay; not the original; not properly identified…" />}
        </Field>
        {['admitted', 'denied'].includes(form.status) && (
          <Field label="Ruling" className="sm:col-span-3">{(a) => <Input {...a} value={form.ruling} onChange={(e) => set('ruling', e.target.value)} placeholder="Order dated …" />}</Field>
        )}
        <div className="sm:col-span-3"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

function RulingDialog({ matterId, ids, onClose }: { matterId: number; ids: number[]; onClose: () => void }) {
  const [status, setStatus] = useState<ExhibitStatus>('admitted')
  const [ruledOn, setRuledOn] = useState(today())
  const [ruling, setRuling] = useState('')
  const save = useBulkExhibitStatus(matterId)
  const error = save.error ? ApiError.from(save.error) : null
  const ruled = status === 'admitted' || status === 'denied'

  return (
    <Dialog open onClose={onClose} title={`Update ${ids.length} exhibit(s)`} description="For a single order ruling on several exhibits at once."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={save.isPending} onClick={() => save.mutate({ ids, status, ruled_on: ruled ? ruledOn : null, ruling: ruling || null }, { onSuccess: onClose })}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="Status">{(a) => <Select {...a} value={status} onChange={(e) => setStatus(e.target.value as ExhibitStatus)}>{Object.entries(STATUS_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}</Field>
        {ruled && <Field label="Ruled on" error={error?.field('ruled_on')}>{(a) => <Input {...a} type="date" max={today()} value={ruledOn} onChange={(e) => setRuledOn(e.target.value)} />}</Field>}
        <Field label="Ruling / order" className="sm:col-span-2">{(a) => <Input {...a} value={ruling} onChange={(e) => setRuling(e.target.value)} placeholder="Order dated …, admitted for the purposes offered" />}</Field>
        <div className="sm:col-span-2"><FormError message={error ? error.message : null} /></div>
      </div>
    </Dialog>
  )
}

function FormalOfferDialog({ matterId, exhibits, onClose }: { matterId: number; exhibits: Exhibit[]; onClose: () => void }) {
  const [ids, setIds] = useState(exhibits.map((e) => e.id))
  const [preview, setPreview] = useState<string | null>(null)
  const create = useCreateFormalOffer(matterId)
  const navigate = useNavigate()

  useEffect(() => {
    if (!ids.length) return
    const t = setTimeout(() => previewFormalOffer(matterId, ids).then((r) => setPreview(r.text)).catch(() => setPreview('')), 300)
    return () => clearTimeout(t)
  }, [matterId, ids])

  return (
    <Dialog open onClose={onClose} size="xl" title="Formal Offer of Evidence" description="Rule 132, Secs. 34–35. Under the 2019 amendments the offer is made orally after the last witness unless the court allows it in writing; either way this is the list to read from."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button disabled={!ids.length} loading={create.isPending} onClick={() => create.mutate({ ids }, { onSuccess: (d) => { onClose(); navigate(`/documents/${d.id}`) } })}>Create document</Button></>}>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[14rem_1fr]">
        <fieldset className="flex flex-col gap-1">
          <legend className="mb-1 text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Exhibits to offer</legend>
          {exhibits.map((e) => (
            <Checkbox key={e.id} label={`"${e.marking}" ${e.description}`} checked={ids.includes(e.id)} onChange={() => setIds((x) => (x.includes(e.id) ? x.filter((i) => i !== e.id) : [...x, e.id]))} />
          ))}
        </fieldset>
        <pre className="max-h-[60vh] overflow-auto rounded-[3px] border border-outline-variant bg-surface-container p-3 font-mono text-xs whitespace-pre-wrap">
          {!ids.length ? 'Choose at least one exhibit.' : preview === null ? 'Preparing…' : preview}
        </pre>
      </div>
    </Dialog>
  )
}
