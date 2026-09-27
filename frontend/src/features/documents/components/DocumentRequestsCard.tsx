import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, ClipboardList, Plus, Trash2, Undo2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, today } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'

export interface DocumentRequestItem {
  id: number
  label: string
  description: string | null
  required: boolean
  status: 'pending' | 'uploaded' | 'accepted' | 'rejected'
  review_note: string | null
  uploaded_at: string | null
  file: { id: number; name: string } | null
}

export interface DocumentRequestData {
  id: number
  matter_id: number
  title: string
  message: string | null
  due_on: string | null
  status: 'open' | 'completed' | 'cancelled'
  progress: { done: number; total: number }
  created_by: string | null
  items: DocumentRequestItem[]
}

const ITEM_STATUS: Record<DocumentRequestItem['status'], { label: string; tone: 'neutral' | 'warning' | 'success' | 'danger' | 'primary' }> = {
  pending: { label: 'Waiting', tone: 'neutral' },
  uploaded: { label: 'To review', tone: 'primary' },
  accepted: { label: 'Accepted', tone: 'success' },
  rejected: { label: 'Sent back', tone: 'danger' },
}

const PRESETS = ['Valid government ID', 'Proof of address', 'Special Power of Attorney', 'Contract or agreement', 'Demand letter received', 'Official receipts', 'Certificate of title', 'Tax declaration', 'Marriage certificate (PSA)', 'Birth certificate (PSA)', 'SEC registration and GIS', "Secretary's certificate / board resolution"]

const invalidate = [['document-requests'], ['files']]

/** Checklists of documents asked of the client for this matter, and review of what arrived. */
export function DocumentRequestsCard({ matterId, canEdit }: { matterId: number; canEdit: boolean }) {
  const query = useQuery({ queryKey: ['document-requests', matterId], queryFn: () => get<DocumentRequestData[]>(`/v1/matters/${matterId}/document-requests`) })
  const [creating, setCreating] = useState(false)
  const [returning, setReturning] = useState<DocumentRequestItem | null>(null)
  const [cancelling, setCancelling] = useState<DocumentRequestData | null>(null)
  const review = useApiMutation(({ id, decision, note }: { id: number; decision: 'accept' | 'reject'; note?: string }) => post(`/v1/document-request-items/${id}/review`, { decision, note }), {
    invalidate,
    success: (_d) => 'Saved',
  })
  const cancel = useApiMutation((id: number) => post(`/v1/document-requests/${id}/cancel`), { invalidate, success: 'Request cancelled' })
  const [note, setNote] = useState('')

  return (
    <Card>
      <CardHeader
        title="Documents requested from the client"
        description="The client uploads each item in the portal; accept it or send it back with a reason."
        actions={canEdit && <Button variant="tonal" size="sm" icon={<ClipboardList className="size-4" />} onClick={() => setCreating(true)}>Request documents</Button>}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : query.data.length === 0 ? (
        <EmptyState icon={<ClipboardList className="size-6" />} title="No requests yet" description="Ask the client for their ID, contracts or other documents with a checklist." />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {query.data.map((r) => (
            <li key={r.id} className="p-5">
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">{r.title}</p>
                {r.status === 'completed' ? <Badge tone="success">Complete</Badge> : r.status === 'cancelled' ? <Badge>Cancelled</Badge> : r.due_on && <Badge tone={r.due_on < today() ? 'danger' : 'warning'}>Due {date(r.due_on)}</Badge>}
                {canEdit && r.status === 'open' && <Button size="sm" variant="text" className="ml-auto" onClick={() => setCancelling(r)}>Cancel request</Button>}
              </div>
              <div className="mt-2 max-w-sm"><ProgressBar value={r.progress.total ? (r.progress.done / r.progress.total) * 100 : 0} label={`${r.progress.done} of ${r.progress.total} required documents accepted`} tone={r.status === 'completed' ? 'success' : 'primary'} /></div>
              <ul className="mt-3 flex flex-col gap-2">
                {r.items.map((i) => (
                  <li key={i.id} className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge tone={ITEM_STATUS[i.status].tone}>{ITEM_STATUS[i.status].label}</Badge>
                    <span className="font-medium">{i.label}</span>{!i.required && <span className="text-on-surface-variant">(optional)</span>}
                    {i.file && <a href={`/api/v1/files/${i.file.id}/download`} download className="text-primary hover:underline">{i.file.name}</a>}
                    {i.status === 'rejected' && i.review_note && <span className="text-on-surface-variant">— {i.review_note}</span>}
                    {canEdit && i.status === 'uploaded' && (
                      <span className="ml-auto flex gap-1">
                        <Button size="sm" variant="text" icon={<CheckCircle2 className="size-4" />} onClick={() => review.mutate({ id: i.id, decision: 'accept' })}>Accept</Button>
                        <Button size="sm" variant="text" icon={<Undo2 className="size-4" />} onClick={() => { setNote(''); setReturning(i) }}>Send back</Button>
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ul>
      )}
      {creating && <NewRequestDialog matterId={matterId} onClose={() => setCreating(false)} />}
      <Dialog open={returning !== null} onClose={() => setReturning(null)} title={`Send back: ${returning?.label}`} description="The client is e-mailed your reason and asked to upload again."
        footer={<><Button variant="text" onClick={() => setReturning(null)}>Cancel</Button><Button disabled={!note.trim()} loading={review.isPending} onClick={() => returning && review.mutate({ id: returning.id, decision: 'reject', note }, { onSuccess: () => setReturning(null) })}>Send back</Button></>}>
        <Field label="What is wrong" required>{(a) => <Textarea {...a} rows={3} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. The ID has expired; please send a current one." />}</Field>
      </Dialog>
      <ConfirmDialog open={cancelling !== null} onClose={() => setCancelling(null)} title="Cancel this request?" description="The client will no longer see it. Documents already uploaded stay in the matter's files." confirmLabel="Cancel request" destructive loading={cancel.isPending}
        onConfirm={() => cancelling && cancel.mutate(cancelling.id, { onSuccess: () => setCancelling(null) })} />
    </Card>
  )
}

function NewRequestDialog({ matterId, onClose }: { matterId: number; onClose: () => void }) {
  const [title, setTitle] = useState('Documents we need from you')
  const [message, setMessage] = useState('')
  const [dueOn, setDueOn] = useState('')
  const [items, setItems] = useState<{ label: string; required: boolean }[]>([])
  const [custom, setCustom] = useState('')
  const create = useApiMutation((input: object) => post(`/v1/matters/${matterId}/document-requests`, input), { invalidate, success: 'Request sent to the client', toastErrors: false })
  const error = create.error ? ApiError.from(create.error) : null

  const toggle = (label: string) => setItems((list) => (list.some((i) => i.label === label) ? list.filter((i) => i.label !== label) : [...list, { label, required: true }]))
  const addCustom = () => {
    if (custom.trim()) setItems((list) => [...list, { label: custom.trim(), required: true }])
    setCustom('')
  }
  const submit = (e: FormEvent) => {
    e.preventDefault()
    create.mutate({ title, message: message || undefined, due_on: dueOn || undefined, items }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="lg" title="Request documents from the client" description="The client is e-mailed a link to upload each document in the portal."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="doc-request-form" loading={create.isPending} disabled={items.length === 0}>Send request</Button></>}>
      <form id="doc-request-form" onSubmit={submit} className="flex flex-col gap-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_12rem]">
          <Field label="Title" required error={error?.field('title')}>{(a) => <Input {...a} value={title} onChange={(e) => setTitle(e.target.value)} required />}</Field>
          <Field label="Needed by" error={error?.field('due_on')}>{(a) => <Input {...a} type="date" min={today()} value={dueOn} onChange={(e) => setDueOn(e.target.value)} />}</Field>
        </div>
        <fieldset>
          <legend className="mb-2 text-sm font-medium">Documents</legend>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {PRESETS.map((p) => <Checkbox key={p} label={p} checked={items.some((i) => i.label === p)} onChange={() => toggle(p)} />)}
          </div>
          <div className="mt-3 flex gap-2">
            <Input aria-label="Another document" placeholder="Another document…" value={custom} onChange={(e) => setCustom(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addCustom() } }} />
            <Button variant="tonal" icon={<Plus className="size-4" />} onClick={addCustom}>Add</Button>
          </div>
          {items.filter((i) => !PRESETS.includes(i.label)).map((i) => (
            <div key={i.label} className="mt-2 flex items-center gap-2 text-sm">
              <span className="flex-1">{i.label}</span>
              <IconButton label={`Remove ${i.label}`} onClick={() => toggle(i.label)}><Trash2 className="size-4" /></IconButton>
            </div>
          ))}
          {items.length > 0 && (
            <div className="mt-3 flex flex-col gap-1 rounded-[3px] bg-surface-container p-3">
              <p className="text-xs text-on-surface-variant">Untick "required" for documents that are only helpful:</p>
              {items.map((i) => <Checkbox key={i.label} label={`${i.label}: required`} checked={i.required} onChange={(e) => setItems((list) => list.map((x) => (x.label === i.label ? { ...x, required: e.target.checked } : x)))} />)}
            </div>
          )}
          {error?.field('items') && <p className="mt-1 text-xs text-danger">{error.field('items')}</p>}
        </fieldset>
        <Field label="Message to the client">{(a) => <Textarea {...a} rows={2} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="e.g. Clear phone photos are fine." />}</Field>
        <FormError message={error?.field('client') ?? (error && !Object.keys(error.errors).length ? error.message : null)} />
      </form>
    </Dialog>
  )
}
