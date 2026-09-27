import { PenLine } from 'lucide-react'
import { useState } from 'react'
import { ApiError } from '@/shared/api/axios'
import type { SignatureRequest } from '@/shared/api/types'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, DescriptionList } from '@/shared/ui/Layout'
import { useCancelSignatureRequest, useRequestSignature, useSignatureRequests } from '../api'

const STATUS: Record<SignatureRequest['status'], { label: string; tone: 'success' | 'warning' | 'danger' | 'neutral' }> = {
  pending: { label: 'Waiting for client', tone: 'warning' },
  signed: { label: 'Signed', tone: 'success' },
  declined: { label: 'Declined', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
  expired: { label: 'Expired', tone: 'neutral' },
}

/** E-signature requests for one document, with the evidence of each signature. */
export function SignaturePanel({ documentId, canRequest, canManage }: { documentId: number; canRequest: boolean; canManage: boolean }) {
  const requests = useSignatureRequests(documentId)
  const cancel = useCancelSignatureRequest()
  const [asking, setAsking] = useState(false)
  const [viewing, setViewing] = useState<SignatureRequest | null>(null)
  const [cancelling, setCancelling] = useState<SignatureRequest | null>(null)

  return (
    <Card>
      <CardHeader title="E-signature" />
      <div className="flex flex-col gap-3 p-5">
        {canRequest && (
          <Button variant="tonal" icon={<PenLine className="size-4" />} onClick={() => setAsking(true)}>Ask client to sign</Button>
        )}
        {requests.isPending ? (
          <PageLoader />
        ) : !requests.data?.length ? (
          <p className="text-sm text-on-surface-variant">{canRequest ? 'The client signs in the portal. You’ll get an email when they answer.' : 'Mark the document final to ask the client to sign it.'}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-outline-variant">
            {requests.data.map((r) => (
              <li key={r.id} className="flex flex-col gap-1 py-2 first:pt-0 last:pb-0">
                <div className="flex items-center justify-between gap-2">
                  <Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>
                  <span className="text-xs text-on-surface-variant">{dateTime(r.created_at)}</span>
                </div>
                <p className="text-sm">{r.client?.name}{r.version_number ? ` · version ${r.version_number}` : ''}</p>
                {r.status === 'pending' && r.expires_at && <p className="text-xs text-on-surface-variant">Expires {dateTime(r.expires_at)}</p>}
                {r.decline_reason && <p className="text-xs text-on-surface-variant">“{r.decline_reason}”</p>}
                <div className="flex gap-2">
                  {(r.status === 'signed' || r.status === 'declined') && <Button variant="text" size="sm" onClick={() => setViewing(r)}>View record</Button>}
                  {canManage && (r.status === 'pending' || r.status === 'expired') && <Button variant="text" size="sm" onClick={() => setCancelling(r)}>Cancel request</Button>}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      {asking && <RequestDialog documentId={documentId} onClose={() => setAsking(false)} />}
      {viewing && <CertificateDialog request={viewing} onClose={() => setViewing(null)} />}
      <ConfirmDialog
        open={cancelling !== null}
        onClose={() => setCancelling(null)}
        title="Cancel this signature request?"
        description="The client can no longer sign it, and the document returns to final."
        confirmLabel="Cancel request"
        destructive
        loading={cancel.isPending}
        onConfirm={() => cancelling && cancel.mutate(cancelling.id, { onSuccess: () => setCancelling(null) })}
      />
    </Card>
  )
}

function RequestDialog({ documentId, onClose }: { documentId: number; onClose: () => void }) {
  const request = useRequestSignature(documentId)
  const [message, setMessage] = useState('')
  const [days, setDays] = useState('14')
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async () => {
    setError(null)
    try {
      await request.mutateAsync({ message: message || undefined, expires_in_days: Number(days) })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Ask the client to sign"
      description="They’ll get an email and can sign in the client portal. The content is locked while the request is open."
      footer={
        <>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button loading={request.isPending} onClick={submit}>Send request</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <FormError message={error ? (error.field('document') ?? error.message) : undefined} />
        <Field label="Message to the client" hint="Optional">
          {(a) => <Textarea {...a} rows={3} maxLength={1000} value={message} onChange={(e) => setMessage(e.target.value)} />}
        </Field>
        <Field label="Respond within">
          {(a) => (
            <Select {...a} value={days} onChange={(e) => setDays(e.target.value)}>
              <option value="3">3 days</option>
              <option value="7">7 days</option>
              <option value="14">14 days</option>
              <option value="30">30 days</option>
            </Select>
          )}
        </Field>
        <p className="text-xs text-on-surface-variant">
          Electronic signatures are recognised under the E-Commerce Act (RA 8792). Instruments that must be notarized still need the signatory’s personal appearance before the notary.
        </p>
      </div>
    </Dialog>
  )
}

function CertificateDialog({ request, onClose }: { request: SignatureRequest; onClose: () => void }) {
  const signed = request.status === 'signed'
  return (
    <Dialog open onClose={onClose} title={signed ? 'Signature record' : 'Declined'} size="lg">
      {signed && (
        <div className="mb-4 rounded-[3px] border border-outline-variant bg-white p-4 text-center text-black">
          {request.signature_method === 'drawn' && request.signature_image ? (
            <img src={request.signature_image} alt={`Signature of ${request.signer_name}`} className="mx-auto max-h-32" />
          ) : (
            <p className="font-serif text-3xl italic">{request.signer_name}</p>
          )}
        </div>
      )}
      <DescriptionList
        items={[
          { label: signed ? 'Signed by' : 'Client', value: request.signer_name ?? request.client?.name },
          { label: signed ? 'Signed at' : 'Declined at', value: dateTime(request.responded_at) },
          ...(signed ? [{ label: 'Method', value: request.signature_method === 'drawn' ? 'Drawn signature' : 'Typed name' }] : []),
          { label: 'Portal account', value: request.client ? `${request.client.name} (${request.client.email ?? 'no email'})` : null },
          { label: 'IP address', value: request.signer_ip },
          { label: 'Browser', value: request.signer_user_agent ? <span className="text-xs break-all">{request.signer_user_agent}</span> : null },
          { label: 'Document version', value: request.version_number ? `Version ${request.version_number}` : null },
          { label: 'Content SHA-256', value: <span className="font-mono text-xs break-all">{request.content_sha256}</span> },
          { label: 'Requested by', value: request.requester ? `${request.requester.name}, ${dateTime(request.created_at)}` : dateTime(request.created_at) },
          ...(request.decline_reason ? [{ label: 'Reason', value: request.decline_reason }] : []),
        ]}
      />
    </Dialog>
  )
}
