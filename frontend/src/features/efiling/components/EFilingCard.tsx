import { AlertTriangle, ArrowDown, ArrowUp, CheckCircle2, FileDown, FileStack, Send, Stamp, X, XCircle } from 'lucide-react'
import { useState } from 'react'
import { fileDownloadUrl } from '@/features/documents/api'
import { ApiError } from '@/shared/api/axios'
import { dateTime, fileSize } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useEFilings, useEFilingSources, usePrepareEFiling, useRecordAcknowledgment, useRecordFiling, type EFiling, type EFilingCheck } from '../api'

const STATUS = {
  prepared: { label: 'Prepared', tone: 'primary' },
  filed: { label: 'Filed', tone: 'warning' },
  acknowledged: { label: 'Acknowledged', tone: 'success' },
} as const

/** Local "now" for a datetime-local input. */
const nowLocal = () => {
  const d = new Date()
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset())
  return d.toISOString().slice(0, 16)
}

function Checks({ checks }: { checks: EFilingCheck[] }) {
  return (
    <ul className="flex flex-col gap-1 text-xs">
      {checks.map((c, i) => (
        <li key={i} className={`flex items-start gap-1.5 ${c.level === 'error' ? 'text-danger' : c.level === 'warning' ? 'text-warning' : 'text-on-surface-variant'}`}>
          {c.level === 'error' ? <XCircle className="mt-px size-3.5 shrink-0" aria-hidden /> : c.level === 'warning' ? <AlertTriangle className="mt-px size-3.5 shrink-0" aria-hidden /> : <CheckCircle2 className="mt-px size-3.5 shrink-0" aria-hidden />}
          {c.message}
        </li>
      ))}
    </ul>
  )
}

/**
 * Electronic filing for a matter: the pleading and its annexes as one
 * numbered, bookmarked PDF, checked against common court limits, and the
 * record of when and how it was filed and acknowledged.
 */
export function EFilingCard({ matterId, canEdit }: { matterId: number; canEdit: boolean }) {
  const query = useEFilings(matterId)
  const [preparing, setPreparing] = useState(false)
  const [filing, setFiling] = useState<EFiling | null>(null)
  const [acknowledging, setAcknowledging] = useState<EFiling | null>(null)

  return (
    <Card>
      <CardHeader
        title="Electronic filing"
        description="The pleading and its annexes as one PDF: separator pages, bookmarks and page numbers, checked for size, searchable text and paper size."
        actions={canEdit && <Button variant="tonal" size="sm" icon={<FileStack className="size-4" />} onClick={() => setPreparing(true)}>Prepare e-filing</Button>}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? (
        <EmptyState icon={<FileStack className="size-6" />} title="Nothing prepared for e-filing yet" />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {query.data.data.map((f) => (
            <li key={f.id} className="flex flex-col gap-2 px-4 py-3 text-sm">
              <div className="flex flex-wrap items-start gap-2">
                <div className="min-w-0 flex-1">
                  <div className="font-medium">{f.title}</div>
                  <div className="text-xs text-on-surface-variant">
                    {f.page_count} pages · {fileSize(f.size_bytes)} · {f.items.map((i) => i.label).join(', ')} · prepared by {f.created_by ?? '—'} {dateTime(f.created_at)}
                  </div>
                </div>
                <Badge tone={STATUS[f.status].tone}>{STATUS[f.status].label}</Badge>
              </div>
              <Checks checks={f.checks} />
              {f.filed_at && (
                <p className="text-xs">
                  Filed {dateTime(f.filed_at)} by {f.filed_via_label?.toLowerCase()}{f.filed_to && ` to ${f.filed_to}`}{f.filing_reference && ` (${f.filing_reference})`}{f.filed_by && `, ${f.filed_by}`}
                  {f.deadline && <> · meets “{f.deadline.title}”</>}
                </p>
              )}
              {f.acknowledged_at && (
                <p className="text-xs">
                  Acknowledged {dateTime(f.acknowledged_at)}{f.acknowledgment && `: ${f.acknowledgment}`}
                  {f.acknowledgment_file && <> · <a href={fileDownloadUrl(f.acknowledgment_file.id)} download className="text-primary hover:underline">{f.acknowledgment_file.name}</a></>}
                </p>
              )}
              <div className="flex flex-wrap gap-1.5">
                {f.package && <a href={fileDownloadUrl(f.package.id)} download className="inline-flex h-8 items-center gap-1.5 rounded-[3px] border border-outline px-2.5 text-xs font-semibold hover:bg-on-surface/5"><FileDown className="size-3.5" aria-hidden />Download PDF</a>}
                {canEdit && f.status === 'prepared' && <Button size="sm" variant="tonal" icon={<Send className="size-4" />} onClick={() => setFiling(f)}>Record filing</Button>}
                {canEdit && f.status === 'filed' && <Button size="sm" variant="tonal" icon={<Stamp className="size-4" />} onClick={() => setAcknowledging(f)}>Record acknowledgment</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {preparing && <PrepareDialog matterId={matterId} maxMb={query.data?.max_mb ?? 25} onClose={() => setPreparing(false)} />}
      {filing && query.data && <FiledDialog matterId={matterId} filing={filing} via={query.data.via} onClose={() => setFiling(null)} />}
      {acknowledging && <AcknowledgedDialog matterId={matterId} filing={acknowledging} onClose={() => setAcknowledging(null)} />}
    </Card>
  )
}

type Annex = { file_id: number; name: string; description: string }

function PrepareDialog({ matterId, maxMb, onClose }: { matterId: number; maxMb: number; onClose: () => void }) {
  const sources = useEFilingSources(matterId, true)
  const prepare = usePrepareEFiling(matterId)
  const error = prepare.error ? ApiError.from(prepare.error) : null
  const [pleading, setPleading] = useState('')
  const [annexes, setAnnexes] = useState<Annex[]>([])
  const [style, setStyle] = useState<'letters' | 'numbers'>('letters')
  const [separators, setSeparators] = useState(true)
  const [title, setTitle] = useState('')
  const pdfs = sources.data?.files.filter((f) => f.mime_type === 'application/pdf') ?? []
  const available = sources.data?.files.filter((f) => !annexes.some((a) => a.file_id === f.id) && `file:${f.id}` !== pleading) ?? []
  const label = (i: number) => `Annex "${style === 'numbers' ? i + 1 : String.fromCharCode(65 + (i % 26)).repeat(Math.floor(i / 26) + 1)}"`
  const move = (i: number, by: number) => setAnnexes((a) => {
    const next = [...a]
    const [item] = next.splice(i, 1)
    if (item) next.splice(i + by, 0, item)
    return next
  })

  const submit = () => {
    const [kind, id] = pleading.split(':')
    prepare.mutate({
      document_id: kind === 'doc' ? Number(id) : null,
      main_file_id: kind === 'file' ? Number(id) : null,
      annexes: annexes.map((a) => ({ file_id: a.file_id, description: a.description || null })),
      annex_style: style,
      separators,
      title: title || null,
    }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="xl" title="Prepare an e-filing" description={`One PDF of up to ${maxMb} MB, on the firm's pleading paper. Annexes must be PDFs or JPG/PNG images; convert Word files to PDF first.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={prepare.isPending} disabled={!pleading && !annexes.length} onClick={submit}>Build the PDF</Button></>}>
      {sources.isPending ? <PageLoader /> : sources.isError ? <ErrorState error={sources.error} /> : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="flex flex-col gap-3">
            <Field label="The pleading" hint="A document drafted here (set in the court format), or an uploaded PDF such as the signed copy." error={error?.field('document_id') ?? error?.field('main_file_id')}>
              {(a) => (
                <Select {...a} value={pleading} onChange={(e) => setPleading(e.target.value)}>
                  <option value="">None: annexes only</option>
                  {!!sources.data.documents.length && <optgroup label="Drafted documents">{sources.data.documents.map((d) => <option key={d.id} value={`doc:${d.id}`}>{d.title} ({d.status})</option>)}</optgroup>}
                  {!!pdfs.length && <optgroup label="Uploaded PDFs">{pdfs.map((f) => <option key={f.id} value={`file:${f.id}`}>{f.original_name}</option>)}</optgroup>}
                </Select>
              )}
            </Field>
            <Field label="Title" hint="Defaults to the pleading's title.">{(a) => <Input {...a} value={title} onChange={(e) => setTitle(e.target.value)} />}</Field>
            <div className="flex flex-wrap items-center gap-3">
              <Select aria-label="Annex labels" value={style} onChange={(e) => setStyle(e.target.value as 'letters' | 'numbers')} className="max-w-56">
                <option value="letters">Annex "A", "B", "C"…</option>
                <option value="numbers">Annex "1", "2", "3"…</option>
              </Select>
              <Checkbox label="Separator page before each annex" checked={separators} onChange={(e) => setSeparators(e.target.checked)} />
            </div>
            <Field label="Add an annex">
              {(a) => (
                <Select {...a} value="" onChange={(e) => {
                  const f = sources.data.files.find((x) => x.id === Number(e.target.value))
                  if (f) setAnnexes((xs) => [...xs, { file_id: f.id, name: f.original_name, description: f.description ?? '' }])
                }}>
                  <option value="">Choose a file of this matter…</option>
                  {available.map((f) => <option key={f.id} value={f.id}>{f.original_name}</option>)}
                </Select>
              )}
            </Field>
          </div>
          <div className="flex flex-col gap-2">
            <h3 className="text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Order</h3>
            {!pleading && !annexes.length && <p className="text-sm text-on-surface-variant">Choose the pleading and add annexes.</p>}
            {pleading && <div className="rounded-[3px] border border-outline-variant bg-surface-container px-3 py-2 text-sm font-medium">The pleading</div>}
            {annexes.map((a, i) => (
              <div key={a.file_id} className="flex items-center gap-1.5 rounded-[3px] border border-outline-variant px-2 py-1.5">
                <div className="min-w-0 flex-1">
                  <div className="text-xs font-semibold">{label(i)}</div>
                  <div className="truncate text-xs text-on-surface-variant">{a.name}</div>
                  <Input aria-label={`Description of ${label(i)}`} value={a.description} placeholder="What it is, e.g. Deed of Absolute Sale dated 3 March 2024" onChange={(e) => setAnnexes((xs) => xs.map((x, j) => (j === i ? { ...x, description: e.target.value } : x)))} className="mt-1 h-7 text-xs" />
                </div>
                <div className="flex flex-col">
                  <IconButton label="Move up" disabled={i === 0} onClick={() => move(i, -1)}><ArrowUp className="size-4" /></IconButton>
                  <IconButton label="Move down" disabled={i === annexes.length - 1} onClick={() => move(i, 1)}><ArrowDown className="size-4" /></IconButton>
                </div>
                <IconButton label={`Remove ${label(i)}`} onClick={() => setAnnexes((xs) => xs.filter((_, j) => j !== i))}><X className="size-4" /></IconButton>
              </div>
            ))}
            <FormError message={error ? (error.field('annexes') ?? (Object.keys(error.errors).length ? 'Check the choices above.' : error.message)) : null} />
          </div>
        </div>
      )}
    </Dialog>
  )
}

function FiledDialog({ matterId, filing, via, onClose }: { matterId: number; filing: EFiling; via: Record<string, string>; onClose: () => void }) {
  const sources = useEFilingSources(matterId, true)
  const record = useRecordFiling(matterId)
  const error = record.error ? ApiError.from(record.error) : null
  const [form, setForm] = useState({ filed_at: nowLocal(), filed_via: 'email', filed_to: '', filing_reference: '', deadline_id: '' })
  const set = (k: keyof typeof form, v: string) => setForm({ ...form, [k]: v })

  return (
    <Dialog open onClose={onClose} title="Record the filing" description={filing.title}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={record.isPending} onClick={() => record.mutate({ id: filing.id, filed_at: new Date(form.filed_at).toISOString(), filed_via: form.filed_via, filed_to: form.filed_to || null, filing_reference: form.filing_reference || null, deadline_id: form.deadline_id ? Number(form.deadline_id) : null }, { onSuccess: onClose })}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="Filed on" error={error?.field('filed_at')}>{(a) => <Input {...a} type="datetime-local" value={form.filed_at} onChange={(e) => set('filed_at', e.target.value)} />}</Field>
        <Field label="How">{(a) => <Select {...a} value={form.filed_via} onChange={(e) => set('filed_via', e.target.value)}>{Object.entries(via).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}</Field>
        <Field label="To" className="sm:col-span-2" hint="The court's e-mail address or portal.">{(a) => <Input {...a} value={form.filed_to} onChange={(e) => set('filed_to', e.target.value)} placeholder="rtc143makati@judiciary.gov.ph" />}</Field>
        <Field label="Reference" className="sm:col-span-2">{(a) => <Input {...a} value={form.filing_reference} onChange={(e) => set('filing_reference', e.target.value)} placeholder="Sent 9:12 AM; e-mail subject; portal transaction no." />}</Field>
        <Field label="Deadline this meets" className="sm:col-span-2" hint="Marked done when you save." error={error?.field('deadline_id')}>
          {(a) => (
            <Select {...a} value={form.deadline_id} onChange={(e) => set('deadline_id', e.target.value)}>
              <option value="">None</option>
              {sources.data?.deadlines.map((d) => <option key={d.id} value={d.id}>{d.title} (due {d.due_date})</option>)}
            </Select>
          )}
        </Field>
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </div>
    </Dialog>
  )
}

function AcknowledgedDialog({ matterId, filing, onClose }: { matterId: number; filing: EFiling; onClose: () => void }) {
  const sources = useEFilingSources(matterId, true)
  const record = useRecordAcknowledgment(matterId)
  const error = record.error ? ApiError.from(record.error) : null
  const [at, setAt] = useState(nowLocal())
  const [text, setText] = useState('')
  const [fileId, setFileId] = useState('')

  return (
    <Dialog open onClose={onClose} title="Record the court's acknowledgment" description={filing.title}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={record.isPending} onClick={() => record.mutate({ id: filing.id, acknowledged_at: new Date(at).toISOString(), acknowledgment: text || null, acknowledgment_file_id: fileId ? Number(fileId) : null }, { onSuccess: onClose })}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-3">
        <Field label="Received on" error={error?.field('acknowledged_at')}>{(a) => <Input {...a} type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} />}</Field>
        <Field label="Acknowledgment">{(a) => <Input {...a} value={text} onChange={(e) => setText(e.target.value)} placeholder="Received by the OCC, reference no. …" />}</Field>
        <Field label="Saved copy" hint="The court's reply e-mail or stamped receipt, uploaded to the matter's files.">
          {(a) => (
            <Select {...a} value={fileId} onChange={(e) => setFileId(e.target.value)}>
              <option value="">None</option>
              {sources.data?.files.map((f) => <option key={f.id} value={f.id}>{f.original_name}</option>)}
            </Select>
          )}
        </Field>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </div>
    </Dialog>
  )
}
