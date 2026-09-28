import { Copy, Pencil, Search, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useCurrentSession, useLookups } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { date } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, SearchInput, Select, Textarea } from '@/shared/ui/Form'
import { Highlight } from '@/shared/ui/Highlight'
import { useToast } from '@/shared/ui/Toast'
import { useDeleteKnowledge, useKnowledge, useKnowledgeItem, useSaveDocumentToKnowledge, useSaveKnowledge, type KnowledgeInput, type KnowledgeItem } from '../api'

const splitTags = (s: string) => s.split(',').map((t) => t.trim()).filter(Boolean)

export function KnowledgeEditor({ item, kinds, onClose }: { item?: KnowledgeItem; kinds: Record<string, string>; onClose: () => void }) {
  const lookups = useLookups()
  const save = useSaveKnowledge(item?.id)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState({
    kind: item?.kind ?? 'jurisprudence', title: item?.title ?? '', citation: item?.citation ?? '', doctrine: item?.doctrine ?? '',
    body: item?.body ?? '', practice_area: item?.practice_area ?? '', tags: (item?.tags ?? []).join(', '),
  })
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm({ ...form, [k]: v })
  const juris = form.kind === 'jurisprudence'

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const input: KnowledgeInput = { ...form, citation: form.citation || null, doctrine: form.doctrine || null, body: form.body || null, practice_area: form.practice_area || null, tags: splitTags(form.tags) }
    save.mutate(input, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="xl" title={item ? 'Edit entry' : 'Add to the knowledge bank'}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="kb-form" loading={save.isPending}>Save</Button></>}>
      <form id="kb-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Field label="Kind">{(a) => <Select {...a} value={form.kind} onChange={(e) => set('kind', e.target.value)}>{Object.entries(kinds).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}</Field>
        <Field label="Title" className="sm:col-span-2" required error={error?.field('title')}>
          {(a) => <Input {...a} required value={form.title} onChange={(e) => set('title', e.target.value)} placeholder={juris ? 'People v. Dela Cruz: chain of custody' : 'Motion to Dismiss (lack of jurisdiction)'} />}
        </Field>
        {juris && <Field label="Citation" className="sm:col-span-3" error={error?.field('citation')}>{(a) => <Input {...a} value={form.citation} onChange={(e) => set('citation', e.target.value)} placeholder="G.R. No. 123456, March 3, 2020" />}</Field>}
        <Field label={juris ? 'Doctrine' : 'When to use it'} className="sm:col-span-3" hint={juris ? 'The ruling in a sentence or two, in your own words.' : undefined}>
          {(a) => <Textarea {...a} value={form.doctrine} onChange={(e) => set('doctrine', e.target.value)} className="min-h-16" />}
        </Field>
        <Field label={juris ? 'Notes and key passages' : 'Text'} className="sm:col-span-3">
          {(a) => <Textarea {...a} value={form.body} onChange={(e) => set('body', e.target.value)} rows={12} className="font-mono text-xs" />}
        </Field>
        <Field label="Practice area" hint="Offered to the assistant on matters of this type.">
          {(a) => (
            <Select {...a} value={form.practice_area} onChange={(e) => set('practice_area', e.target.value)}>
              <option value="">Any</option>
              {lookups.data?.case_types.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Tags" className="sm:col-span-2" hint="Separated by commas.">{(a) => <Input {...a} value={form.tags} onChange={(e) => set('tags', e.target.value)} placeholder="torts, negligence" />}</Field>
        <div className="sm:col-span-3"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

export function KnowledgeView({ id, onClose, onEdit, onInsert }: { id: number; onClose: () => void; onEdit?: (item: KnowledgeItem) => void; onInsert?: (text: string) => void }) {
  const { user } = useCurrentSession()
  const query = useKnowledgeItem(id)
  const remove = useDeleteKnowledge()
  const toast = useToast()
  const k = query.data
  const text = k ? [k.citation, k.doctrine, k.body].filter(Boolean).join('\n\n') : ''
  const canRemove = k && (k.created_by_id === user.id || user.role === 'managing_partner')

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success('Copied')
    } catch {
      toast.error('Copy failed')
    }
  }

  return (
    <Dialog open onClose={onClose} size="xl" title={k?.title ?? 'Entry'} description={k ? [k.kind_label, k.citation, k.practice_area].filter(Boolean).join(' · ') : undefined}
      footer={k && (
        <>
          {onEdit && canRemove && <Button variant="text" icon={<Trash2 className="size-4" />} loading={remove.isPending} onClick={() => remove.mutate(k.id, { onSuccess: onClose })}>Remove</Button>}
          {onEdit && <Button variant="outlined" icon={<Pencil className="size-4" />} onClick={() => onEdit(k)}>Edit</Button>}
          <Button variant={onInsert ? 'outlined' : 'filled'} icon={<Copy className="size-4" />} onClick={copy}>Copy</Button>
          {onInsert && <Button onClick={() => onInsert(k.body || text)}>Insert</Button>}
        </>
      )}>
      {query.isPending ? <PageLoader /> : query.isError || !k ? <ErrorState error={query.error} /> : (
        <div className="flex flex-col gap-3 text-sm">
          {k.doctrine && <p className="rounded-[3px] border-l-4 border-primary bg-surface-container px-3 py-2">{k.doctrine}</p>}
          {k.body && <pre className="max-h-[55vh] overflow-auto rounded-[3px] border border-outline-variant p-3 font-mono text-xs whitespace-pre-wrap">{k.body}</pre>}
          <div className="flex flex-wrap items-center gap-2 text-xs text-on-surface-variant">
            {k.tags.map((t) => <Badge key={t}>{t}</Badge>)}
            <span>Added by {k.created_by ?? 'someone'}{k.updated_by && k.updated_by !== k.created_by ? `, edited by ${k.updated_by}` : ''} · {date(k.updated_at)}</span>
            {k.source_matter && <span>· from <Link to={`/matters/${k.source_matter.id}`} className="text-primary hover:underline">{k.source_matter.reference}</Link></span>}
          </div>
          {k.kind === 'jurisprudence' && <p className="text-xs text-on-surface-variant">This is the firm's note of the case. Check it against the full decision before citing it.</p>}
        </div>
      )}
    </Dialog>
  )
}

/** Search the knowledge bank and insert an entry's text, e.g. into a pleading's body. */
export function KnowledgePicker({ onInsert, onClose }: { onInsert: (text: string) => void; onClose: () => void }) {
  const [search, setSearch] = useState('')
  const [kind, setKind] = useState('')
  const query = useKnowledge({ search, kind })
  const [open, setOpen] = useState<number | null>(null)

  if (open !== null) return <KnowledgeView id={open} onClose={() => setOpen(null)} onInsert={(t) => { onInsert(t); onClose() }} />

  return (
    <Dialog open onClose={onClose} size="lg" title="Insert from the knowledge bank" footer={<Button variant="text" onClick={onClose}>Close</Button>}>
      <div className="flex flex-col gap-3">
        <div className="flex gap-2">
          <div className="min-w-0 flex-1"><SearchInput value={search} onChange={setSearch} placeholder="Search clauses, pleadings, doctrines, G.R. numbers" label="Search the knowledge bank" /></div>
          <Select aria-label="Kind" value={kind} onChange={(e) => setKind(e.target.value)} className="w-40">
            <option value="">All kinds</option>
            {Object.entries(query.data?.kinds ?? {}).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </Select>
        </div>
        {query.isPending ? <PageLoader /> : !query.data?.data.length ? <EmptyState icon={<Search className="size-6" />} title="Nothing found" /> : (
          <ul className="max-h-[50vh] divide-y divide-outline-variant overflow-auto rounded-[3px] border border-outline-variant">
            {query.data.data.map((k) => (
              <li key={k.id}>
                <button type="button" onClick={() => setOpen(k.id)} className="w-full px-3 py-2 text-left hover:bg-on-surface/5">
                  <div className="font-medium">{k.title} <span className="text-xs font-normal text-on-surface-variant">· {k.kind_label}{k.citation && ` · ${k.citation}`}</span></div>
                  {k.snippet ? <Highlight text={k.snippet} className="text-xs text-on-surface-variant" /> : k.doctrine && <div className="truncate text-xs text-on-surface-variant">{k.doctrine}</div>}
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Dialog>
  )
}

/** From the document editor: keep this document as a model for next time. */
export function SaveToKnowledgeDialog({ documentId, title, onClose }: { documentId: number; title: string; onClose: () => void }) {
  const save = useSaveDocumentToKnowledge(documentId)
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState({ kind: 'pleading', title: title.split(' — ')[0] ?? title, tags: '', notes: '' })

  return (
    <Dialog open onClose={onClose} title="Keep in the knowledge bank" description="The latest version is copied; later changes to this document are not. Remove client names and facts that should not be reused."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={save.isPending} onClick={() => save.mutate({ ...form, tags: splitTags(form.tags), notes: form.notes || null }, { onSuccess: onClose })}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3">
        <Field label="Kind">
          {(a) => (
            <Select {...a} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
              <option value="pleading">Pleading</option><option value="form">Form</option><option value="clause">Clause</option><option value="note">Research note</option>
            </Select>
          )}
        </Field>
        <Field label="Title" error={error?.field('title')}>{(a) => <Input {...a} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />}</Field>
        <Field label="When to use it">{(a) => <Textarea {...a} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} className="min-h-16" placeholder="Granted by RTC Makati Br. 143; works when the complaint lacks a certification against forum shopping." />}</Field>
        <Field label="Tags" hint="Separated by commas.">{(a) => <Input {...a} value={form.tags} onChange={(e) => setForm({ ...form, tags: e.target.value })} />}</Field>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </div>
    </Dialog>
  )
}
