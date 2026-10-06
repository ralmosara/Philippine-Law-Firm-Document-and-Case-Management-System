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
  const token = useParams().token ?? ''
  const letter = useQuery({ queryKey: ['engagement', token], queryFn: () => get<PublicLetter>(`/public/engagement/${token}`), retry: false })
  const sign = useMutation({ mutationFn: (input: object) => post(`/public/engagement/${token}/sign`, input) })
  const decline = useMutation({ mutationFn: (reason: string) => post(`/public/engagement/${token}/decline`, { reason: reason || null }) })
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
        <p role="status" className="text-lg font-semibold">{sign.isSuccess ? 'Signed. Thank you.' : 'You declined the engagement letter.'}</p>
        <p className="max-w-md text-sm text-on-surface-variant">{sign.isSuccess ? `${letter.data?.firm ?? 'The firm'} has been told and will be in touch about next steps, including access to your client portal.` : 'The firm has been told. Please contact them if you would like to discuss the terms.'}</p>
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
        <h1 className="text-2xl font-semibold">Engagement letter</h1>
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
            <Checkbox label="I have read this letter and agree to its terms, and I agree to sign it electronically. My electronic signature has the same effect as my handwritten signature." checked={consent} onChange={(e) => setConsent(e.target.checked)} />
            <Button type="submit" loading={sign.isPending} disabled={!ready} className="w-full">Sign the letter</Button>
            <Button variant="text" onClick={() => setDeclining(true)}>Decline</Button>
            <p className="text-xs text-on-surface-variant">We record the time, your IP address and browser with your signature, as evidence under the E-Commerce Act (RA 8792).</p>
          </form>
        </Card>
      </div>
      <ConfirmDialog
        open={declining}
        onClose={() => setDeclining(false)}
        title="Decline the engagement letter?"
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
