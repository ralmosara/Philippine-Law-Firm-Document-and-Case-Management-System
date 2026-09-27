import { ArrowLeft, CheckCircle2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import { dateTime } from '@/shared/lib/format'
import { Button, ButtonLink } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, Tabs } from '@/shared/ui/Layout'
import { useDeclineSignature, usePortalSession, usePortalSignatureRequest, useSignDocument } from '../api'
import { SignaturePad } from './SignaturePad'

type Method = 'drawn' | 'typed'

/** The client reads the document and signs (or declines) it electronically. */
export function SignDocument() {
  const id = Number(useParams().id)
  const request = usePortalSignatureRequest(id)
  const session = usePortalSession()
  const sign = useSignDocument(id)
  const decline = useDeclineSignature(id)

  const [method, setMethod] = useState<Method>('drawn')
  const [name, setName] = useState('')
  const [image, setImage] = useState<string | null>(null)
  const [consent, setConsent] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [declining, setDeclining] = useState(false)

  if (request.isPending) return <PageLoader />
  if (request.isError) return <ErrorState error={request.error} onRetry={() => request.refetch()} />
  const r = request.data
  const signerName = name || session.data?.name || ''

  const back = <Link to="/portal" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> Back to your matters</Link>

  if (sign.isSuccess || r.status !== 'pending') {
    const done = sign.isSuccess || r.status === 'signed'
    return (
      <>
        <PageHeader back={back} title={r.document.title} />
        <Card className="flex flex-col items-center gap-3 p-10 text-center">
          {done && <CheckCircle2 className="size-12 text-success" aria-hidden="true" />}
          <p role="status" className="text-lg font-semibold">
            {done ? 'Signed. Thank you.' : r.status === 'declined' ? 'You declined to sign this document.' : `This request is ${r.status}.`}
          </p>
          <p className="max-w-md text-sm text-on-surface-variant">
            {done ? 'Your lawyer has been notified. A copy stays available under your matter.' : 'Please contact your lawyer if you have questions.'}
          </p>
          <ButtonLink to="/portal" variant="tonal" className="mt-2">Done</ButtonLink>
        </Card>
      </>
    )
  }

  const ready = consent && signerName.trim().length > 1 && (method === 'typed' || image !== null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await sign.mutateAsync({ signer_name: signerName.trim(), method, signature_image: method === 'drawn' ? (image ?? undefined) : undefined, consent })
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  const errorMessage = error ? (error.field('document') ?? error.field('status') ?? error.field('signature_image') ?? error.field('consent') ?? error.field('signer_name') ?? error.message) : undefined

  return (
    <>
      <PageHeader
        back={back}
        title={`Review and sign: ${r.document.title}`}
        description={[r.matter?.title, r.requested_by && `Requested by ${r.requested_by}`, r.expires_at && `Please respond by ${dateTime(r.expires_at)}`].filter(Boolean).join(' · ')}
      />

      {r.message && <p className="mb-6 rounded-[3px] bg-primary-container p-4 text-sm text-on-primary-container">“{r.message}”</p>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
        <Card>
          <CardHeader title="Document" description="Read the whole document before signing." />
          <pre tabIndex={0} aria-label="Document text" className="max-h-[70vh] overflow-y-auto p-6 font-serif text-[15px] leading-7 whitespace-pre-wrap sm:px-10">{r.content}</pre>
          <p className="border-t border-outline-variant px-5 py-3 font-mono text-[11px] break-all text-on-surface-variant">Document fingerprint (SHA-256): {r.content_sha256}</p>
        </Card>

        <Card as="div" className="self-start">
          <CardHeader title="Your signature" />
          <form onSubmit={submit} className="flex flex-col gap-4 p-5">
            <FormError message={errorMessage} />
            <Field label="Full name of the person signing" hint="Signing for a company? Enter your own name; your lawyer has your authority on file.">
              {(a) => <Input {...a} autoComplete="name" value={signerName} onChange={(e) => setName(e.target.value)} maxLength={255} />}
            </Field>

            <Tabs<Method>
              label="How to sign"
              value={method}
              onChange={(next) => { setMethod(next); setImage(null) }}
              tabs={[{ value: 'drawn', label: 'Draw' }, { value: 'typed', label: 'Type' }]}
            />
            {method === 'drawn' ? (
              <SignaturePad label="Signature drawing area" onChange={setImage} />
            ) : (
              <div className="rounded-[3px] border border-outline bg-white px-4 py-6 text-center font-serif text-3xl text-black italic" aria-label="Typed signature preview">
                {signerName || 'Your name'}
              </div>
            )}

            <Checkbox
              label="I have read this document, and I agree to sign it electronically. My electronic signature has the same effect as my handwritten signature."
              checked={consent}
              onChange={(e) => setConsent(e.target.checked)}
            />

            <Button type="submit" loading={sign.isPending} disabled={!ready} className="w-full">Sign document</Button>
            <Button variant="text" onClick={() => setDeclining(true)}>Decline to sign</Button>
            <p className="text-xs text-on-surface-variant">We record the time, your IP address and browser with your signature, as evidence under the E-Commerce Act (RA 8792).</p>
          </form>
        </Card>
      </div>

      <ConfirmDialog
        open={declining}
        onClose={() => setDeclining(false)}
        title="Decline to sign?"
        description="Your lawyer will be told. You can tell them why below."
        confirmLabel="Decline"
        destructive
        reasonLabel="Reason (optional)"
        loading={decline.isPending}
        onConfirm={(reason) => decline.mutate(reason, { onSuccess: () => setDeclining(false) })}
      />
    </>
  )
}
