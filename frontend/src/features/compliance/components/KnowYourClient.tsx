import { useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, FileDown, Plus, Trash2 } from 'lucide-react'
import { useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, apiClient, del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, dateTime, money } from '@/shared/lib/format'
import { Button, DownloadButton, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

interface Kyc {
  risk: 'low' | 'normal' | 'high' | null
  is_pep: boolean
  notes: string | null
  reviewed_at: string | null
  reviewed_by: string | null
  problems: string[]
  identifications: { id: number; id_type: string; id_number: string; issued_on: string | null; expires_on: string | null; expired: boolean; has_scan: boolean; notes: string | null; verified_by: string | null; verified_at: string | null }[]
  owners: { id: number; name: string; ownership_bps: number | null; position: string | null; nationality: string | null }[]
  id_types: string[]
}

const RISK: Record<'low' | 'normal' | 'high', [string, 'success' | 'neutral' | 'danger']> = { low: ['Low risk', 'success'], normal: ['Normal risk', 'neutral'], high: ['High risk', 'danger'] }

/**
 * Knowing the client: their identification, the beneficial owners of a
 * company, and a lawyer's risk rating. What to collect is the firm's AMLA
 * policy; this keeps the record.
 */
export function KycCard({ clientId, corporate }: { clientId: number; corporate: boolean }) {
  const key = ['kyc', clientId]
  const query = useQuery({ queryKey: key, queryFn: () => get<Kyc>(`/v1/clients/${clientId}/kyc`) })
  const [dialog, setDialog] = useState<'id' | 'owner' | 'review' | null>(null)
  const [removing, setRemoving] = useState<{ kind: 'identifications' | 'owners'; id: number; label: string } | null>(null)
  const remove = useApiMutation((r: { kind: string; id: number }) => del(`/v1/clients/${clientId}/kyc/${r.kind}/${r.id}`), { invalidate: [key], success: 'Removed' })

  if (query.isPending) return <Card><PageLoader /></Card>
  if (query.isError) return <Card><div className="p-4"><ErrorState error={query.error} /></div></Card>
  const k = query.data

  return (
    <Card>
      <CardHeader
        title="Client identification"
        description="Know-your-client records under the firm's anti-money-laundering policy."
        actions={k.risk ? <span className="flex gap-1"><Badge tone={RISK[k.risk][1]}>{RISK[k.risk][0]}</Badge>{k.is_pep && <Badge tone="warning">PEP</Badge>}</span> : <Badge tone="warning">Not reviewed</Badge>}
      />
      <div className="flex flex-col gap-4 p-5 text-sm">
        {k.problems.length > 0 && (
          <ul className="flex flex-col gap-1 rounded-[3px] bg-warning-container p-3 text-on-warning-container">
            {k.problems.map((p) => <li key={p} className="flex items-start gap-2"><AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />{p}</li>)}
          </ul>
        )}

        <div>
          <div className="mb-1 flex items-center justify-between"><h3 className="text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Identification</h3><Button size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => setDialog('id')}>Add</Button></div>
          {k.identifications.length === 0 ? <p className="text-on-surface-variant">None on file.</p> : (
            <ul className="flex flex-col gap-2">
              {k.identifications.map((i) => (
                <li key={i.id} className="flex items-start gap-2">
                  <div className="flex-1">
                    <p className="font-medium">{i.id_type} · <span className="font-mono">{i.id_number}</span></p>
                    <p className={`text-xs ${i.expired ? 'text-danger' : 'text-on-surface-variant'}`}>
                      {i.expires_on ? `${i.expired ? 'Expired' : 'Valid until'} ${date(i.expires_on)}` : 'No expiry'}{i.verified_by && ` · checked by ${i.verified_by} ${date(i.verified_at)}`}
                    </p>
                  </div>
                  {i.has_scan && <DownloadButton size="sm" href={`/api/v1/clients/${clientId}/kyc/identifications/${i.id}/scan`} icon={<FileDown className="size-4" />}>Scan</DownloadButton>}
                  <IconButton label={`Remove ${i.id_type}`} onClick={() => setRemoving({ kind: 'identifications', id: i.id, label: i.id_type })}><Trash2 className="size-4" /></IconButton>
                </li>
              ))}
            </ul>
          )}
        </div>

        {corporate && (
          <div>
            <div className="mb-1 flex items-center justify-between"><h3 className="text-xs font-semibold tracking-wide text-on-surface-variant uppercase">Beneficial owners</h3><Button size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => setDialog('owner')}>Add</Button></div>
            {k.owners.length === 0 ? <p className="text-on-surface-variant">None recorded.</p> : (
              <ul className="flex flex-col gap-1">
                {k.owners.map((o) => (
                  <li key={o.id} className="flex items-center gap-2">
                    <span className="flex-1">{o.name}<span className="text-on-surface-variant">{[o.ownership_bps !== null && `${o.ownership_bps / 100}%`, o.position, o.nationality].filter(Boolean).map((x) => ` · ${x}`).join('')}</span></span>
                    <IconButton label={`Remove ${o.name}`} onClick={() => setRemoving({ kind: 'owners', id: o.id, label: o.name })}><Trash2 className="size-4" /></IconButton>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        <div className="flex items-center gap-2 border-t border-outline-variant pt-3">
          <p className="flex-1 text-xs text-on-surface-variant">{k.reviewed_at ? `Reviewed by ${k.reviewed_by} on ${date(k.reviewed_at)}.` : 'Not yet reviewed.'}{k.notes && ` ${k.notes}`}</p>
          <Button size="sm" variant="tonal" onClick={() => setDialog('review')}>{k.reviewed_at ? 'Review again' : 'Review'}</Button>
        </div>
      </div>

      {dialog === 'id' && <AddIdDialog clientId={clientId} types={k.id_types} onClose={() => setDialog(null)} />}
      {dialog === 'owner' && <AddOwnerDialog clientId={clientId} onClose={() => setDialog(null)} />}
      {dialog === 'review' && <ReviewDialog clientId={clientId} kyc={k} onClose={() => setDialog(null)} />}
      <ConfirmDialog open={removing !== null} onClose={() => setRemoving(null)} title={`Remove ${removing?.label}?`} description="It is removed from the client's identification record (the change is kept in the audit log)." destructive confirmLabel="Remove" loading={remove.isPending} onConfirm={() => removing && remove.mutate(removing, { onSuccess: () => setRemoving(null) })} />
    </Card>
  )
}

function AddIdDialog({ clientId, types, onClose }: { clientId: number; types: string[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const file = useRef<HTMLInputElement>(null)
  const [form, setForm] = useState({ id_type: types[0], id_number: '', issued_on: '', expires_on: '', notes: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body = new FormData()
      Object.entries(form).forEach(([k, v]) => { const value = (v ?? '').trim(); if (value) body.append(k, value) })
      const scan = file.current?.files?.[0]
      if (scan) body.append('scan', scan)
      await apiClient.post(`/v1/clients/${clientId}/kyc/identifications`, body)
      await queryClient.invalidateQueries({ queryKey: ['kyc', clientId] })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open onClose={onClose} title="Add identification" description="Check the original (or a certified copy) and record what you saw. The scan is kept privately; each viewing is logged." footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="kyc-id" loading={busy}>Save</Button></>}>
      <form id="kyc-id" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2" noValidate>
        <Field label="Type" error={error?.field('id_type')}>{(a) => <Select {...a} value={form.id_type} onChange={set('id_type')}>{types.map((t) => <option key={t}>{t}</option>)}</Select>}</Field>
        <Field label="Number" required error={error?.field('id_number')}>{(a) => <Input {...a} value={form.id_number} onChange={set('id_number')} />}</Field>
        <Field label="Issued" error={error?.field('issued_on')}>{(a) => <Input {...a} type="date" value={form.issued_on} onChange={set('issued_on')} />}</Field>
        <Field label="Expires" hint="Leave blank if it does not expire." error={error?.field('expires_on')}>{(a) => <Input {...a} type="date" value={form.expires_on} onChange={set('expires_on')} />}</Field>
        <Field label="Scan (photo or PDF)" className="sm:col-span-2" error={error?.field('scan')}>{(a) => <input {...a} ref={file} type="file" accept="image/jpeg,image/png,application/pdf" className="text-sm" />}</Field>
        <Field label="Notes" className="sm:col-span-2">{(a) => <Input {...a} value={form.notes} onChange={set('notes')} placeholder="Original seen at our office" />}</Field>
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

function AddOwnerDialog({ clientId, onClose }: { clientId: number; onClose: () => void }) {
  const [form, setForm] = useState({ name: '', ownership: '', position: '', nationality: '' })
  const save = useApiMutation((input: object) => post(`/v1/clients/${clientId}/kyc/owners`, input), { invalidate: [['kyc', clientId]], toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null
  const set = (k: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  return (
    <Dialog open onClose={onClose} title="Add a beneficial owner" description="A natural person who ultimately owns or controls the company, directly or through others." footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={save.isPending} onClick={() => save.mutate({ name: form.name.trim(), ownership_bps: form.ownership.trim() ? Math.round(Number(form.ownership) * 100) : null, position: form.position.trim() || null, nationality: form.nationality.trim() || null }, { onSuccess: onClose })}>Save</Button></>}>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Name" required error={error?.field('name')} className="sm:col-span-2">{(a) => <Input {...a} value={form.name} onChange={set('name')} />}</Field>
        <Field label="Ownership (%)" error={error?.field('ownership_bps')}>{(a) => <Input {...a} inputMode="decimal" value={form.ownership} onChange={set('ownership')} />}</Field>
        <Field label="Position">{(a) => <Input {...a} value={form.position} onChange={set('position')} placeholder="President" />}</Field>
        <Field label="Nationality">{(a) => <Input {...a} value={form.nationality} onChange={set('nationality')} />}</Field>
      </div>
    </Dialog>
  )
}

function ReviewDialog({ clientId, kyc, onClose }: { clientId: number; kyc: Kyc; onClose: () => void }) {
  const [risk, setRisk] = useState(kyc.risk ?? 'normal')
  const [pep, setPep] = useState(kyc.is_pep)
  const [notes, setNotes] = useState(kyc.notes ?? '')
  const save = useApiMutation((input: object) => put(`/v1/clients/${clientId}/kyc/review`, input), { invalidate: [['kyc', clientId]], success: 'Review recorded', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null

  return (
    <Dialog open onClose={onClose} title="Review the client" description="Rate the risk from who the client is, where their money comes from, and what you will do for them." footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={save.isPending} onClick={() => save.mutate({ risk, is_pep: pep, notes: notes.trim() || null }, { onSuccess: onClose })}>Save review</Button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Risk">{(a) => <Select {...a} value={risk} onChange={(e) => setRisk(e.target.value as 'low')}><option value="low">Low</option><option value="normal">Normal</option><option value="high">High</option></Select>}</Field>
        <Checkbox label="Politically exposed person (or a family member or close associate of one)" checked={pep} onChange={(e) => setPep(e.target.checked)} />
        <Field label="Notes" required={risk === 'high' || pep} error={error?.field('notes')} hint="For a high-risk client or a PEP: why, the source of funds, and what further checks were made.">{(a) => <Textarea {...a} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
      </div>
    </Dialog>
  )
}

interface Review { id: number; client: { id: number; name: string } | null; day: string; amount_cents: number; deposits: number; status: string; notes: string | null; report_reference: string | null; decided_by: string | null; decided_at: string | null }

/** Trust deposits at or above the covered-transaction amount, waiting for a decision. */
export function AmlReviews() {
  const [status, setStatus] = useState('pending')
  const query = useQuery({ queryKey: ['aml-reviews', status], queryFn: () => get<{ threshold_cents: number; threshold_confirmed_at: string | null; data: Review[] }>('/v1/aml-reviews', { status }) })
  const [deciding, setDeciding] = useState<Review | null>(null)

  return (
    <Card>
      <CardHeader
        title="Anti-money-laundering reviews"
        description={query.data ? `A client's trust deposits on one day of ${money(query.data.threshold_cents)} or more are listed here. Decide whether a covered or suspicious transaction report is due, file it with the AMLC if so, and record the decision.` : undefined}
        actions={<Select aria-label="Show" value={status} onChange={(e) => setStatus(e.target.value)} className="w-44"><option value="pending">To decide</option><option value="not_reportable">Not reportable</option><option value="reported">Reported</option></Select>}
      />
      {query.data && !query.data.threshold_confirmed_at && <p className="mx-5 mt-4 rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">The amount is the default. Confirm it with your compliance counsel and set it under Firm Settings → Firm.</p>}
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? <EmptyState title={status === 'pending' ? 'Nothing to decide' : 'None'} /> : (
        <Table caption="AML reviews" compact>
          <thead><tr><Th>Client</Th><Th>Day</Th><Th align="right">Deposited</Th><Th>Decision</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {query.data.data.map((r) => (
              <tr key={r.id}>
                <Td className="font-medium">{r.client ? <Link to={`/clients/${r.client.id}`} className="text-primary hover:underline">{r.client.name}</Link> : '—'}</Td>
                <Td className="whitespace-nowrap">{date(r.day)}<div className="text-xs text-on-surface-variant">{r.deposits} deposit{r.deposits === 1 ? '' : 's'}</div></Td>
                <Td align="right" className="font-medium">{money(r.amount_cents)}</Td>
                <Td className="text-sm">{r.status === 'pending' ? <Badge tone="warning">To decide</Badge> : <>{r.status === 'reported' ? `Reported (${r.report_reference})` : 'Not reportable'} · {r.decided_by} {dateTime(r.decided_at)}<div className="text-xs text-on-surface-variant">{r.notes}</div></>}</Td>
                <Td align="right">{r.status === 'pending' && <Button size="sm" variant="tonal" onClick={() => setDeciding(r)}>Decide</Button>}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {deciding && <DecideDialog review={deciding} onClose={() => setDeciding(null)} />}
    </Card>
  )
}

function DecideDialog({ review, onClose }: { review: Review; onClose: () => void }) {
  const [status, setStatus] = useState<'not_reportable' | 'reported'>('not_reportable')
  const [notes, setNotes] = useState('')
  const [reference, setReference] = useState('')
  const decide = useApiMutation((input: object) => post(`/v1/aml-reviews/${review.id}/decide`, input), { invalidate: [['aml-reviews']], success: 'Decision recorded', toastErrors: false })
  const error = decide.error ? ApiError.from(decide.error) : null

  return (
    <Dialog open onClose={onClose} title={`${review.client?.name}: ${money(review.amount_cents)} on ${date(review.day)}`} footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={decide.isPending} onClick={() => decide.mutate({ status, notes: notes.trim(), report_reference: reference.trim() || null }, { onSuccess: onClose })}>Record decision</Button></>}>
      <div className="flex flex-col gap-4">
        <Field label="Decision">{(a) => <Select {...a} value={status} onChange={(e) => setStatus(e.target.value as 'reported')}><option value="not_reportable">No report due</option><option value="reported">Reported to the AMLC</option></Select>}</Field>
        {status === 'reported' && <Field label="Report reference" required error={error?.field('report_reference')}>{(a) => <Input {...a} value={reference} onChange={(e) => setReference(e.target.value)} />}</Field>}
        <Field label="Reasons" required error={error?.field('notes')} hint="For example: bank transfer from the client's own account, not cash; or the facts that made it reportable.">{(a) => <Textarea {...a} rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />}</Field>
        <FormError message={error && !error.field('notes') && !error.field('report_reference') ? error.message : null} />
      </div>
    </Dialog>
  )
}

