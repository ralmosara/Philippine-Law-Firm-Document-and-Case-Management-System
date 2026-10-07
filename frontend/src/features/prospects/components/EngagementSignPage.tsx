import { useMutation, useQuery } from '@tanstack/react-query'
import { CheckCircle2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { SignaturePad } from '@/features/client-portal/components/SignaturePad'
import { ApiError, get, post } from '@/shared/api/axios'
import { date } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { EmptyState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader, Tabs } from '@/shared/ui/Layout'

interface PublicLetter { firm: string | null; name: string | null; content: string; status: 'sent' | 'expired' | 'signed' | 'declined' | 'cancelled'; expires_at: string | null }
type Method = 'drawn' | 'typed'

/** A prospect reads and signs (or declines) the engagement letter from the link they were emailed. */
export function EngagementSignPage() {
  return <SignByLink kind="engagement" title="Engagement letter" signLabel="Sign the letter" signed={(firm) => `${firm} has been told and will be in touch about next steps, including access to your client portal.`} declinedText="You declined the engagement letter." agreeText="I have read this letter and agree to its terms, and I agree to sign it electronically. My electronic signature has the same effect as my handwritten signature." />
}

/** Someone asked to consent in writing to the firm acting despite a possible conflict of interest. */
export function ConsentSignPage() {
  return <SignByLink kind="consent" title="Consent to a possible conflict of interest" signLabel="Sign my consent" signed={(firm) => `${firm} has been told. Thank you.`} declinedText="You declined to consent. The firm has been told." agreeText="I have read this letter, I understand the possible conflict of interest, and I consent. I agree to sign electronically; my electronic signature has the same effect as my handwritten signature." />
}

/** Read a letter from a private link and sign it or decline. */
function SignByLink({ kind, title, signLabel, signed, declinedText, agreeText }: { kind: 'engagement' | 'consent'; title: string; signLabel: string; signed: (firm: string) => string; declinedText: string; agreeText: string }) {
  const token = useParams().token ?? ''
  const letter = useQuery({ queryKey: [kind, token], queryFn: () => get<PublicLetter>(`/public/${kind}/${token}`), retry: false })
  const sign = useMutation({ mutationFn: (input: object) => post(`/public/${kind}/${token}/sign`, input) })
  const decline = useMutation({ mutationFn: (reason: string) => post(`/public/${kind}/${token}/decline`, { reason: reason || null }) })
  const [method, setMethod] = useState<Method>('drawn')
  const [name, setName] = useState('')
  const [image, setImage] = useState<string | null>(null)
  const [consent, setConsent] = useState(false)
  const [declining, setDeclining] = useState(false)

  const shell = (children: React.ReactNode) => <main className="min-h-dvh bg-surface-dim px-4 py-10"><div className="mx-auto max-w-5xl">{children}</div></main>

  if (letter.isPending) return shell(<PageLoader />)
  if (sign.isSuccess || decline.isSuccess) {
    return shell(
      <Card className="flex flex-col items-center gap-3 p-10 text-center">
        {sign.isSuccess && <CheckCircle2 className="size-12 text-success" aria-hidden />}
        <p role="status" className="text-lg font-semibold">{sign.isSuccess ? 'Signed. Thank you.' : declinedText}</p>
        <p className="max-w-md text-sm text-on-surface-variant">{sign.isSuccess ? signed(letter.data?.firm ?? 'The firm') : 'Please contact the firm if you would like to discuss it.'}</p>
      </Card>,
    )
  }
  if (letter.isError || !letter.data) {
    return shell(<EmptyState title="This link is no longer valid" description="It may have been signed already, withdrawn or replaced by a newer letter. Please contact the firm." />)
  }
  const l = letter.data
  if (l.status !== 'sent') {
    return shell(<EmptyState title={l.status === 'expired' ? 'This link has expired' : 'This letter is no longer open for signature'} description="Please contact the firm for a new link." />)
  }

  const signerName = name || l.name || ''
  const ready = consent && signerName.trim().length > 1 && (method === 'typed' || image !== null)
  const error = sign.error ? ApiError.from(sign.error) : null
  const submit = (e: FormEvent) => {
    e.preventDefault()
    sign.mutate({ signer_name: signerName.trim(), method, signature_image: method === 'drawn' ? image : undefined, consent })
  }

  return shell(
    <>
      <header className="mb-6">
        <p className="text-sm text-on-surface-variant">{l.firm}</p>
        <h1 className="text-2xl font-semibold">{title}</h1>
        {l.expires_at && <p className="text-sm text-on-surface-variant">Please respond by {date(l.expires_at)}.</p>}
      </header>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader title="Terms" description="Read the whole letter before signing." />
          <pre tabIndex={0} aria-label="Letter text" className="max-h-[70vh] overflow-y-auto p-6 font-serif text-[15px] leading-7 whitespace-pre-wrap sm:px-10">{l.content}</pre>
        </Card>
        <Card as="div" className="self-start">
          <CardHeader title="Your signature" />
          <form onSubmit={submit} className="flex flex-col gap-4 p-5">
            <FormError message={error ? (error.field('letter') ?? error.field('signature_image') ?? error.field('consent') ?? error.field('signer_name') ?? error.message) : undefined} />
            <Field label="Full name of the person signing" hint="Signing for a company? Enter your own name.">
              {(a) => <Input {...a} autoComplete="name" value={signerName} onChange={(e) => setName(e.target.value)} maxLength={255} />}
            </Field>
            <Tabs<Method> label="How to sign" value={method} onChange={(m) => { setMethod(m); setImage(null) }} tabs={[{ value: 'drawn', label: 'Draw' }, { value: 'typed', label: 'Type' }]} />
            {method === 'drawn' ? <SignaturePad label="Signature drawing area" onChange={setImage} /> : (
              <div className="rounded-[3px] border border-outline bg-white px-4 py-6 text-center font-serif text-3xl text-black italic" aria-label="Typed signature preview">{signerName || 'Your name'}</div>
            )}
            <Checkbox label={agreeText} checked={consent} onChange={(e) => setConsent(e.target.checked)} />
            <Button type="submit" loading={sign.isPending} disabled={!ready} className="w-full">{signLabel}</Button>
            <Button variant="text" onClick={() => setDeclining(true)}>Decline</Button>
            <p className="text-xs text-on-surface-variant">We record the time, your IP address and browser with your signature, as evidence under the E-Commerce Act (RA 8792).</p>
          </form>
        </Card>
      </div>
      <ConfirmDialog
        open={declining}
        onClose={() => setDeclining(false)}
        title="Decline?"
        description="The firm will be told. You can tell them why below."
        confirmLabel="Decline"
        destructive
        reasonLabel="Reason (optional)"
        loading={decline.isPending}
        onConfirm={(reason) => decline.mutate(reason, { onSuccess: () => setDeclining(false) })}
      />
    </>,
  )
}
