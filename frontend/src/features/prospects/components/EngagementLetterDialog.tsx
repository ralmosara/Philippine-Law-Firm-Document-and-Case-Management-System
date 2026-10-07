import { useQuery } from '@tanstack/react-query'
import { Send } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, dateTime, toCents } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'

type Arrangement = 'hourly' | 'flat' | 'retainer' | 'contingency' | 'pro_bono'

export interface EngagementLetter {
  id: number
  status: 'draft' | 'sent' | 'signed' | 'declined' | 'cancelled'
  fee_arrangement: Arrangement
  fixed_fee_cents: number | null
  acceptance_fee_cents: number | null
  appearance_fee_cents: number | null
  contingency_basis_points: number | null
  scope: string
  content: string
  sent_at: string | null
  expires_at: string | null
  responded_at: string | null
  signer_name: string | null
  decline_reason: string | null
  matter_id: number | null
  document_id: number | null
}

const ARRANGEMENTS: Record<Arrangement, string> = { flat: 'Flat fee', retainer: 'Monthly retainer', hourly: 'Hourly (time billed)', contingency: 'Contingency (share of recovery)', pro_bono: 'Pro bono' }
const STATUS: Record<EngagementLetter['status'], [string, 'neutral' | 'warning' | 'success' | 'danger']> = { draft: ['Draft', 'neutral'], sent: ['Waiting for signature', 'warning'], signed: ['Signed', 'success'], declined: ['Declined', 'danger'], cancelled: ['Withdrawn', 'neutral'] }
const pesos = (c: number | null) => (c ? (c / 100).toFixed(2) : '')

/**
 * Draft the engagement letter from the agreed terms, edit it, and send the
 * prospect a private link to sign. Signing opens the client and matter.
 */
export function EngagementLetterDialog({ prospectId, name, email, onClose }: { prospectId: number; name: string; email: string | null; onClose: () => void }) {
  const letters = useQuery({ queryKey: ['prospects', prospectId, 'engagement'], queryFn: () => get<EngagementLetter[]>(`/v1/prospects/${prospectId}/engagement-letters`) })
  const latest = letters.data?.[0]
  const [fresh, setFresh] = useState(false)

  if (letters.isPending) return <Dialog open onClose={onClose} title="Engagement letter"><PageLoader /></Dialog>
  const showForm = fresh || !latest || latest.status === 'draft'

  return showForm
    ? <LetterForm prospectId={prospectId} name={name} email={email} draft={latest?.status === 'draft' ? latest : undefined} onClose={onClose} />
    : <LetterStatus prospectId={prospectId} letter={latest!} onNew={() => setFresh(true)} onClose={onClose} />
}

function LetterForm({ prospectId, name, email, draft, onClose }: { prospectId: number; name: string; email: string | null; draft?: EngagementLetter; onClose: () => void }) {
  const [arrangement, setArrangement] = useState<Arrangement>(draft?.fee_arrangement ?? 'flat')
  const [fee, setFee] = useState(pesos(draft?.fixed_fee_cents ?? null))
  const [acceptance, setAcceptance] = useState(pesos(draft?.acceptance_fee_cents ?? null))
  const [appearance, setAppearance] = useState(pesos(draft?.appearance_fee_cents ?? null))
  const [share, setShare] = useState(draft?.contingency_basis_points ? String(draft.contingency_basis_points / 100) : '')
  const [scope, setScope] = useState(draft?.scope ?? '')
  const [content, setContent] = useState(draft?.content ?? '')
  const [edited, setEdited] = useState(false)
  const [confirmSend, setConfirmSend] = useState(false)
  const invalidate = [['prospects']]
  const save = useApiMutation((input: object) => post<EngagementLetter>(`/v1/prospects/${prospectId}/engagement-letters`, input), { invalidate, toastErrors: false })
  const send = useApiMutation((id: number) => post<EngagementLetter>(`/v1/prospects/${prospectId}/engagement-letters/${id}/send`), { invalidate, success: 'Sent for signature', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : send.error ? ApiError.from(send.error) : null
  const current = save.data ?? draft

  const cents = (v: string) => (v.trim() ? toCents(v) : null)
  const terms = () => ({
    fee_arrangement: arrangement,
    fixed_fee_cents: ['flat', 'retainer'].includes(arrangement) ? cents(fee) : null,
    acceptance_fee_cents: cents(acceptance),
    appearance_fee_cents: cents(appearance),
    contingency_basis_points: arrangement === 'contingency' && share.trim() ? Math.round(Number(share) * 100) : null,
    scope: scope.trim(),
  })

  // Redraft from the terms (dropping any edits), or save the edited text.
  const redraft = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(terms(), { onSuccess: (l) => { setContent(l.content); setEdited(false) } })
  }
  const saveText = () => save.mutate({ ...terms(), content }, { onSuccess: (l) => { setContent(l.content); setEdited(false) } })

  return (
    <Dialog
      open
      onClose={onClose}
      size="xl"
      title={`Engagement letter for ${name}`}
      description="Set the fees and scope, then read and adjust the text. The prospect signs online through a private link; signing opens the client and the matter with these fees."
      footer={
        <>
          <Button variant="text" onClick={onClose}>Close</Button>
          {current && edited && <Button variant="outlined" loading={save.isPending} onClick={saveText}>Save text</Button>}
          {current && <Button icon={<Send className="size-4" />} disabled={edited || !email} onClick={() => setConfirmSend(true)}>Send for signature</Button>}
        </>
      }
    >
      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[22rem_1fr]">
        <form onSubmit={redraft} className="flex flex-col gap-3" noValidate>
          <Field label="Fee arrangement">
            {(a) => <Select {...a} value={arrangement} onChange={(e) => setArrangement(e.target.value as Arrangement)}>{Object.entries(ARRANGEMENTS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>}
          </Field>
          {(arrangement === 'flat' || arrangement === 'retainer') && (
            <Field label={arrangement === 'flat' ? 'Fixed fee (₱)' : 'Monthly retainer (₱)'} required error={error?.field('fixed_fee_cents')}>{(a) => <Input {...a} inputMode="decimal" value={fee} onChange={(e) => setFee(e.target.value)} />}</Field>
          )}
          {arrangement === 'contingency' && (
            <Field label="Share of the recovery (%)" required error={error?.field('contingency_basis_points')}>{(a) => <Input {...a} inputMode="decimal" value={share} onChange={(e) => setShare(e.target.value)} placeholder="25" />}</Field>
          )}
          <Field label="Acceptance fee (₱)" hint="Optional. Payable on signing.">{(a) => <Input {...a} inputMode="decimal" value={acceptance} onChange={(e) => setAcceptance(e.target.value)} />}</Field>
          <Field label="Appearance fee per hearing (₱)" hint="Optional.">{(a) => <Input {...a} inputMode="decimal" value={appearance} onChange={(e) => setAppearance(e.target.value)} />}</Field>
          <Field label="Scope of the engagement" required error={error?.field('scope')} hint="What you will do, in which case and court, and up to what stage.">
            {(a) => <Textarea {...a} rows={5} value={scope} onChange={(e) => setScope(e.target.value)} />}
          </Field>
          <Button type="submit" variant="tonal" loading={save.isPending && !edited}>{current ? 'Redraft from these terms' : 'Draft the letter'}</Button>
          {current && edited && <p className="text-xs text-on-surface-variant">Redrafting replaces your edits to the text.</p>}
          {!email && <p className="text-xs text-danger">Add the prospect's email address to send the letter.</p>}
          <FormError message={error && !error.field('fixed_fee_cents') && !error.field('scope') && !error.field('contingency_basis_points') ? (error.field('letter') ?? error.message) : null} />
        </form>
        <div className="flex min-w-0 flex-col gap-1">
          <p className="text-sm font-medium">Letter</p>
          {current ? (
            <Textarea aria-label="Letter text" value={content} onChange={(e) => { setContent(e.target.value); setEdited(true) }} className="min-h-[60vh] font-mono text-xs leading-relaxed" />
          ) : (
            <p className="rounded-[3px] bg-surface-container p-4 text-sm text-on-surface-variant">Set the terms and draft the letter. It includes the scope, fees, expenses held in trust, billing, no guarantee of outcome, conflicts, data privacy consent and how the engagement ends.</p>
          )}
        </div>
      </div>
      <ConfirmDialog
        open={confirmSend}
        onClose={() => setConfirmSend(false)}
        title="Send the engagement letter?"
        description={`${name} gets a private link at ${email} to read and sign it within 30 days. The text can no longer be changed once sent; to change it, withdraw it and send a new one.`}
        confirmLabel="Send"
        loading={send.isPending}
        onConfirm={() => current && send.mutate(current.id, { onSettled: () => setConfirmSend(false) })}
      />
    </Dialog>
  )
}

function LetterStatus({ prospectId, letter, onNew, onClose }: { prospectId: number; letter: EngagementLetter; onNew: () => void; onClose: () => void }) {
  const withdraw = useApiMutation(() => post(`/v1/prospects/${prospectId}/engagement-letters/${letter.id}/cancel`), { invalidate: [['prospects']], success: 'Letter withdrawn' })
  const [label, tone] = STATUS[letter.status]

  return (
    <Dialog
      open
      onClose={onClose}
      size="lg"
      title="Engagement letter"
      footer={
        <>
          <Button variant="text" onClick={onClose}>Close</Button>
          {letter.status === 'sent' && <Button variant="outlined" loading={withdraw.isPending} onClick={() => withdraw.mutate()}>Withdraw</Button>}
          {letter.status !== 'signed' && letter.status !== 'sent' && <Button onClick={onNew}>Prepare a new letter</Button>}
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm">
        <p className="flex flex-wrap items-center gap-2"><Badge tone={tone}>{label}</Badge>{ARRANGEMENTS[letter.fee_arrangement]}</p>
        {letter.status === 'sent' && <p>Sent {dateTime(letter.sent_at)}. The link works until {date(letter.expires_at)}.</p>}
        {letter.status === 'signed' && (
          <p>
            Signed by {letter.signer_name} on {dateTime(letter.responded_at)}.{' '}
            {letter.matter_id ? <Link to={`/matters/${letter.matter_id}`} className="text-primary hover:underline">Open the matter</Link> : 'The matter could not be opened automatically: convert the prospect from the pipeline.'}
          </p>
        )}
        {letter.status === 'declined' && <p>Declined {dateTime(letter.responded_at)}{letter.decline_reason ? `: “${letter.decline_reason}”` : '.'}</p>}
        <pre className="max-h-[50vh] overflow-auto rounded-[3px] border border-outline-variant bg-surface-container p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap">{letter.content}</pre>
      </div>
    </Dialog>
  )
}
