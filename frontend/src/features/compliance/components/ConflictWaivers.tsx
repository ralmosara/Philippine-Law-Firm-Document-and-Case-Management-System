import { useQuery } from '@tanstack/react-query'
import { Send } from 'lucide-react'
import { useState } from 'react'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Textarea } from '@/shared/ui/Form'

interface Waiver { id: number; signer_name: string; signer_email: string; status: 'sent' | 'signed' | 'declined' | 'cancelled' | 'expired'; content: string; sent_at: string | null; expires_at: string | null; responded_at: string | null; signed_name: string | null; decline_reason: string | null }

const STATUS: Record<Waiver['status'], [string, 'neutral' | 'warning' | 'success' | 'danger']> = { sent: ['Waiting', 'warning'], signed: ['Consented', 'success'], declined: ['Declined', 'danger'], cancelled: ['Withdrawn', 'neutral'], expired: ['Link expired', 'neutral'] }

/**
 * Written informed consent from each person concerned, signed online and
 * kept with the conflict check (and printed in its report).
 */
export function ConflictWaivers({ checkId, searchTerm }: { checkId: number; searchTerm: string }) {
  const key = ['conflict-waivers', checkId]
  const waivers = useQuery({ queryKey: key, queryFn: () => get<Waiver[]>(`/v1/conflict-checks/${checkId}/waivers`) })
  const [asking, setAsking] = useState(false)
  const withdraw = useApiMutation((id: number) => post(`/v1/conflict-waivers/${id}/cancel`), { invalidate: [key], success: 'Withdrawn' })

  return (
    <div className="mx-5 mb-4 rounded-[3px] border border-outline-variant p-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <p className="flex-1 font-medium">Written consents</p>
        <Button size="sm" variant="tonal" icon={<Send className="size-4" />} onClick={() => setAsking(true)}>Ask for consent</Button>
      </div>
      <p className="mt-1 text-xs text-on-surface-variant">Where the firm may act despite a possible conflict, each person concerned consents in writing after full disclosure. Whether it can be waived at all is your judgment.</p>
      {waivers.data && waivers.data.length > 0 && (
        <ul className="mt-3 flex flex-col gap-2">
          {waivers.data.map((w) => (
            <li key={w.id} className="flex flex-wrap items-center gap-2">
              <Badge tone={STATUS[w.status][1]}>{STATUS[w.status][0]}</Badge>
              <span className="flex-1">{w.signer_name} <span className="text-on-surface-variant">· {w.signer_email}</span>
                {w.responded_at && <span className="block text-xs text-on-surface-variant">{w.status === 'signed' ? `Signed as “${w.signed_name}”` : 'Answered'} {dateTime(w.responded_at)}{w.decline_reason && `: ${w.decline_reason}`}</span>}
              </span>
              {w.status === 'sent' && <Button size="sm" variant="text" loading={withdraw.isPending && withdraw.variables === w.id} onClick={() => withdraw.mutate(w.id)}>Withdraw</Button>}
            </li>
          ))}
        </ul>
      )}
      {asking && <AskDialog checkId={checkId} searchTerm={searchTerm} onClose={() => setAsking(false)} />}
    </div>
  )
}

function AskDialog({ checkId, searchTerm, onClose }: { checkId: number; searchTerm: string; onClose: () => void }) {
  const [name, setName] = useState(searchTerm)
  const [email, setEmail] = useState('')
  const [situation, setSituation] = useState('')
  const [explanation, setExplanation] = useState('')
  const [content, setContent] = useState('')
  const draft = useApiMutation((input: object) => post<{ content: string }>(`/v1/conflict-checks/${checkId}/waivers/draft`, input), { toastErrors: false })
  const send = useApiMutation((input: object) => post(`/v1/conflict-checks/${checkId}/waivers`, input), { invalidate: [['conflict-waivers', checkId]], success: 'Sent for signature', toastErrors: false })
  const error = draft.error ? ApiError.from(draft.error) : send.error ? ApiError.from(send.error) : null

  return (
    <Dialog
      open
      onClose={onClose}
      size="xl"
      title="Ask for written consent"
      description="Disclose the facts fully and explain what acting means for them. They read and sign online through a private link, and may refuse."
      footer={<>
        <Button variant="text" onClick={onClose}>Cancel</Button>
        {!content
          ? <Button loading={draft.isPending} onClick={() => draft.mutate({ signer_name: name, situation, explanation }, { onSuccess: (d) => setContent(d.content) })}>Draft the letter</Button>
          : <Button icon={<Send className="size-4" />} loading={send.isPending} onClick={() => send.mutate({ signer_name: name, signer_email: email, content }, { onSuccess: onClose })}>Send for signature</Button>}
      </>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Person asked to consent" required error={error?.field('signer_name')}>{(a) => <Input {...a} value={name} onChange={(e) => setName(e.target.value)} />}</Field>
        <Field label="Their email" required error={error?.field('signer_email')}>{(a) => <Input {...a} type="email" value={email} onChange={(e) => setEmail(e.target.value)} />}</Field>
        {!content ? (
          <>
            <Field label="The facts" required className="sm:col-span-2" error={error?.field('situation')} hint="Who the firm acts for, who has asked it to act, and how their interests may conflict.">{(a) => <Textarea {...a} rows={4} value={situation} onChange={(e) => setSituation(e.target.value)} />}</Field>
            <Field label="What it means for them" required className="sm:col-span-2" error={error?.field('explanation')} hint="The effects and risks, and how the firm will protect them (separate lawyers, confidentiality).">{(a) => <Textarea {...a} rows={4} value={explanation} onChange={(e) => setExplanation(e.target.value)} />}</Field>
          </>
        ) : (
          <Field label="Letter (edit before sending)" className="sm:col-span-2" error={error?.field('content')}>{(a) => <Textarea {...a} value={content} onChange={(e) => setContent(e.target.value)} className="min-h-[50vh] font-mono text-xs leading-relaxed" />}</Field>
        )}
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </div>
    </Dialog>
  )
}
