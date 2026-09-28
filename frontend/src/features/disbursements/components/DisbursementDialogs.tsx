import { Plus, Trash2, Upload } from 'lucide-react'
import { useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { fileDownloadUrl, useMatterFiles, useUploadFile } from '@/features/documents/api'
import { useMatterOptions, useTrustAccounts } from '@/features/trust/api'
import { ApiError } from '@/shared/api/axios'
import { date, dateTime, money, toCents, today } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { STATUS, useDisbursementAction, useLiquidate, useRequestDisbursement, type Disbursement, type LiquidationItem } from '../api'

type Categories = { value: string; label: string }[]

export function StatusBadge({ d }: { d: Disbursement }) {
  if (d.is_overdue) return <Badge tone="danger">Liquidation overdue</Badge>
  return <Badge tone={STATUS[d.status].tone}>{STATUS[d.status].label}</Badge>
}

/** Ask for a cash advance: from firm funds, or from the client's trust deposit. */
export function RequestDialog({ matter, categories, onClose }: { matter?: { id: number; client_id: number }; categories: Categories; onClose: () => void }) {
  const matters = useMatterOptions({ enabled: !matter })
  const [matterId, setMatterId] = useState<number | null>(matter?.id ?? null)
  const clientId = matter?.client_id ?? matters.data?.find((m) => m.id === matterId)?.client_id
  const accounts = useTrustAccounts({ client_id: clientId, enabled: !!clientId })
  const open = (accounts.data?.data ?? []).filter((a) => a.status === 'open' && (!a.matter || a.matter.id === matterId))
  const save = useRequestDisbursement()
  const error = save.error ? ApiError.from(save.error) : null
  const [form, setForm] = useState({ category: 'filing_fee', description: '', amount: '', needed_by: '', source: 'firm' as 'firm' | 'trust', trust_account_id: null as number | null })
  const [local, setLocal] = useState<string | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const amount = toCents(form.amount)
    if (!matterId) return setLocal('Choose the matter.')
    if (!(amount > 0)) return setLocal('Enter the amount in pesos.')
    setLocal(null)
    save.mutate(
      { matterId, category: form.category, description: form.description, amount_cents: amount, needed_by: form.needed_by || null, source: form.source, trust_account_id: form.source === 'trust' ? form.trust_account_id : null },
      { onSuccess: onClose },
    )
  }

  return (
    <Dialog open onClose={onClose} title="Request a cash advance" description="For filing and sheriff's fees, TSN, notarial and similar costs. A partner approves and releases it; liquidate it with the receipts within a week."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="dr-form" loading={save.isPending}>Send for approval</Button></>}>
      <form id="dr-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {!matter && (
          <Field label="Matter" className="sm:col-span-2" required>
            {(a) => (
              <Select {...a} value={matterId ?? ''} onChange={(e) => setMatterId(Number(e.target.value) || null)}>
                <option value="">Choose…</option>
                {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
              </Select>
            )}
          </Field>
        )}
        <Field label="For" error={error?.field('category')}>
          {(a) => <Select {...a} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>{categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</Select>}
        </Field>
        <Field label="Amount (₱)" required error={error?.field('amount_cents')}>
          {(a) => <Input {...a} inputMode="decimal" placeholder="0.00" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />}
        </Field>
        <Field label="Details" className="sm:col-span-2" required error={error?.field('description')}>
          {(a) => <Input {...a} required value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Docket fees for the complaint, RTC Makati" />}
        </Field>
        <Field label="Needed by" error={error?.field('needed_by')}>{(a) => <Input {...a} type="date" min={today()} value={form.needed_by} onChange={(e) => setForm({ ...form, needed_by: e.target.value })} />}</Field>
        <Field label="Paid from" error={error?.field('source')}>
          {(a) => (
            <Select {...a} value={form.source} onChange={(e) => setForm({ ...form, source: e.target.value as 'firm' | 'trust', trust_account_id: open[0]?.id ?? null })}>
              <option value="firm">Firm funds (billed to the client)</option>
              <option value="trust" disabled={!open.length}>Client's trust deposit{!open.length ? ' (none)' : ''}</option>
            </Select>
          )}
        </Field>
        {form.source === 'trust' && (
          <Field label="Trust account" className="sm:col-span-2" error={error?.field('trust_account_id')}>
            {(a) => (
              <Select {...a} value={form.trust_account_id ?? ''} onChange={(e) => setForm({ ...form, trust_account_id: Number(e.target.value) })}>
                {open.map((t) => <option key={t.id} value={t.id}>{t.account_number} · balance {money(t.balance_cents)}</option>)}
              </Select>
            )}
          </Field>
        )}
        <div className="sm:col-span-2"><FormError message={local ?? (error && !Object.keys(error.errors).length ? error.message : null)} /></div>
      </form>
    </Dialog>
  )
}

/** One request, with what the viewer may do next. */
export function DetailDialog({ d, categories, onClose }: { d: Disbursement; categories: Categories; onClose: () => void }) {
  const act = useDisbursementAction()
  const error = act.error ? ApiError.from(act.error) : null
  const [mode, setMode] = useState<'view' | 'reject' | 'release' | 'liquidate'>('view')
  const [text, setText] = useState('')

  if (mode === 'liquidate') return <LiquidateDialog d={d} categories={categories} onClose={onClose} />

  const run = (action: 'approve' | 'reject' | 'release' | 'cancel', input: object = {}) => act.mutate({ id: d.id, action, ...input }, { onSuccess: onClose })

  const footer = mode === 'reject' ? (
    <><Button variant="text" onClick={() => setMode('view')}>Back</Button><Button variant="danger" loading={act.isPending} onClick={() => run('reject', { reason: text })}>Don't approve</Button></>
  ) : mode === 'release' ? (
    <><Button variant="text" onClick={() => setMode('view')}>Back</Button><Button loading={act.isPending} onClick={() => run('release', { reference: text || null })}>Release {money(d.amount_cents)}</Button></>
  ) : (
    <>
      {d.can.cancel && <Button variant="text" onClick={() => run('cancel')} loading={act.isPending}>Cancel request</Button>}
      {d.can.reject && <Button variant="outlined" onClick={() => { setText(''); setMode('reject') }}>Don't approve</Button>}
      {d.can.approve && <Button onClick={() => run('approve')} loading={act.isPending}>Approve</Button>}
      {d.can.release && <Button onClick={() => { setText(''); setMode('release') }}>Release</Button>}
      {d.can.liquidate && <Button onClick={() => setMode('liquidate')}>Liquidate</Button>}
    </>
  )

  return (
    <Dialog open onClose={onClose} size="lg" title={`${money(d.amount_cents)} · ${d.category_label}`} description={d.description} footer={footer}>
      <div className="flex flex-col gap-4 text-sm">
        <dl className="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
          <Item label="Status"><StatusBadge d={d} /></Item>
          <Item label="Matter">{d.matter ? <Link to={`/matters/${d.matter.id}?tab=time`} className="text-primary hover:underline">{d.matter.reference} · {d.matter.title}</Link> : '—'}</Item>
          <Item label="Requested by">{d.requester}</Item>
          <Item label="Needed by">{d.needed_by ? date(d.needed_by) : '—'}</Item>
          <Item label="Paid from">{d.source === 'trust' ? `Trust ${d.trust_account?.account_number} (balance ${money(d.trust_account?.balance_cents)})` : 'Firm funds'}</Item>
          {d.decided_by && <Item label={d.status === 'rejected' ? 'Not approved by' : 'Approved by'}>{d.decided_by}, {dateTime(d.decided_at)}{d.decision_note && <div className="text-on-surface-variant">{d.decision_note}</div>}</Item>}
          {d.released_by && <Item label="Released">{d.released_by}, {dateTime(d.released_at)}{d.release_reference && ` · ${d.release_reference}`}</Item>}
          {d.liquidation_due_on && d.status === 'released' && <Item label="Liquidate by">{date(d.liquidation_due_on)}</Item>}
          {d.status === 'liquidated' && (
            <Item label="Liquidation">
              Spent {money(d.spent_cents)}
              {!!d.returned_cents && ` · ${money(d.returned_cents)} ${d.source === 'trust' ? 'returned to trust' : 'to be returned'}`}
              {!!d.reimburse_cents && ` · ${money(d.reimburse_cents)} to reimburse`}
            </Item>
          )}
        </dl>
        {d.expenses.length > 0 && (
          <ul className="divide-y divide-outline-variant rounded-[3px] border border-outline-variant">
            {d.expenses.map((e) => (
              <li key={e.id} className="flex items-center justify-between gap-3 px-3 py-2">
                <span className="min-w-0">
                  {e.description}
                  <span className="block text-xs text-on-surface-variant">{date(e.expense_date)} · {e.receipt ? <a href={fileDownloadUrl(e.receipt.id)} download className="text-primary hover:underline">{e.receipt.name}</a> : 'no receipt'}</span>
                </span>
                <span className="tabular-nums">{money(e.amount_cents)}</span>
              </li>
            ))}
          </ul>
        )}
        {mode === 'reject' && <Field label="Reason (sent to the requester)" required error={error?.field('reason')}>{(a) => <Textarea {...a} value={text} onChange={(e) => setText(e.target.value)} />}</Field>}
        {mode === 'release' && (
          <Field label="Voucher or check no." hint={d.source === 'trust' ? 'Posted to the client\'s trust ledger as a disbursement.' : undefined}>
            {(a) => <Input {...a} value={text} onChange={(e) => setText(e.target.value)} placeholder="CV-0142" />}
          </Field>
        )}
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </div>
    </Dialog>
  )
}

function Item({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-semibold tracking-wide text-on-surface-variant uppercase">{label}</dt>
      <dd>{children}</dd>
    </div>
  )
}

type Line = { expense_date: string; category: string; description: string; amount: string; receipt_file_id: number | null; is_billable: boolean }

/** Account for the advance, one line per receipt; receipts are uploaded to the matter's files. */
function LiquidateDialog({ d, categories, onClose }: { d: Disbursement; categories: Categories; onClose: () => void }) {
  const matterId = d.matter?.id ?? 0
  const files = useMatterFiles(matterId)
  const upload = useUploadFile(matterId)
  const liquidate = useLiquidate()
  const error = liquidate.error ? ApiError.from(liquidate.error) : null
  const input = useRef<HTMLInputElement>(null)
  const [uploadingFor, setUploadingFor] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const [lines, setLines] = useState<Line[]>([{ expense_date: today(), category: d.category, description: d.description, amount: (d.amount_cents / 100).toFixed(2), receipt_file_id: null, is_billable: true }])
  const set = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const spent = lines.reduce((s, l) => s + (toCents(l.amount) || 0), 0)
  const diff = d.amount_cents - spent

  const submit = () => {
    const items: LiquidationItem[] = lines.map((l) => ({ expense_date: l.expense_date, category: l.category, description: l.description, amount_cents: toCents(l.amount), receipt_file_id: l.receipt_file_id, is_billable: l.is_billable }))
    liquidate.mutate({ id: d.id, items, note: note || null }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} size="xl" title={`Liquidate ${money(d.amount_cents)}`} description={`${d.description}. One line per receipt; each becomes an expense on the matter${d.source === 'trust' ? ', recorded as paid from the trust deposit' : ' and is billed to the client at cost'}.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={liquidate.isPending} onClick={submit}>Liquidate</Button></>}>
      <input ref={input} type="file" className="hidden" onChange={(e) => {
        const f = e.target.files?.[0]
        const i = uploadingFor
        e.target.value = ''
        if (f && i !== null) upload.mutate({ file: f, description: `Receipt: ${lines[i]?.description ?? ''}`, shared: false }, { onSuccess: (file) => set(i, { receipt_file_id: file.id }) })
      }} />
      <div className="flex flex-col gap-3">
        {lines.map((l, i) => (
          <div key={i} className="grid grid-cols-2 gap-2 rounded-[3px] border border-outline-variant p-3 sm:grid-cols-[8.5rem_11rem_1fr_7.5rem_auto]">
            <Field label="Date" error={error?.field(`items.${i}.expense_date`)}>{(a) => <Input {...a} type="date" max={today()} value={l.expense_date} onChange={(e) => set(i, { expense_date: e.target.value })} />}</Field>
            <Field label="Category">{(a) => <Select {...a} value={l.category} onChange={(e) => set(i, { category: e.target.value })}>{categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</Select>}</Field>
            <Field label="Description / OR no." className="col-span-2 sm:col-span-1" error={error?.field(`items.${i}.description`)}>{(a) => <Input {...a} value={l.description} onChange={(e) => set(i, { description: e.target.value })} />}</Field>
            <Field label="Amount (₱)" error={error?.field(`items.${i}.amount_cents`)}>{(a) => <Input {...a} inputMode="decimal" value={l.amount} onChange={(e) => set(i, { amount: e.target.value })} />}</Field>
            <div className="flex items-end justify-end">{lines.length > 1 && <IconButton label="Remove line" onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>}</div>
            <div className="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-5">
              <Select aria-label="Receipt" value={l.receipt_file_id ?? ''} onChange={(e) => set(i, { receipt_file_id: Number(e.target.value) || null })} className="max-w-xs">
                <option value="">No receipt</option>
                {files.data?.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
              </Select>
              <Button size="sm" variant="text" icon={<Upload className="size-4" />} loading={upload.isPending && uploadingFor === i} onClick={() => { setUploadingFor(i); input.current?.click() }}>Upload receipt</Button>
              {d.source === 'firm' && <Checkbox label="Bill to client" checked={l.is_billable} onChange={(e) => set(i, { is_billable: e.target.checked })} />}
            </div>
          </div>
        ))}
        <Button size="sm" variant="text" icon={<Plus className="size-4" />} className="self-start" onClick={() => setLines((ls) => [...ls, { expense_date: today(), category: d.category, description: '', amount: '', receipt_file_id: null, is_billable: true }])}>Add a receipt</Button>
        <p className="text-sm">
          Spent <strong className="tabular-nums">{money(spent)}</strong> of {money(d.amount_cents)}:{' '}
          {diff > 0 ? `${money(diff)} ${d.source === 'trust' ? 'goes back to the trust account' : 'to return to the firm'}` : diff < 0 ? (d.source === 'trust' ? <span className="text-danger">more than was released from trust</span> : `${money(-diff)} to reimburse to you`) : 'fully spent'}
        </p>
        <Field label="Note">{(a) => <Input {...a} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. sheriff issued no receipt; acknowledgment attached" />}</Field>
        <FormError message={error ? (error.field('items') ?? (!Object.keys(error.errors).length ? error.message : 'Check the lines above.')) : null} />
      </div>
    </Dialog>
  )
}
