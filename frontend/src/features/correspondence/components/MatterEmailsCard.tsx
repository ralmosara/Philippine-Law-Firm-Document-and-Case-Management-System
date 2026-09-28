import { Copy, Mail, Paperclip, RefreshCw, Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import { fileDownloadUrl } from '@/features/documents/api'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import { useMatterEmail, useMatterEmails, useReviewEmail, useRotateAddress, useUploadEml, type MatterEmail } from '../api'

/** Email to matter: the matter's address, and the correspondence filed through it. */
export function MatterEmailsCard({ matterId, canEdit }: { matterId: number; canEdit: boolean }) {
  const query = useMatterEmails(matterId)
  const review = useReviewEmail(matterId)
  const upload = useUploadEml(matterId)
  const rotate = useRotateAddress(matterId)
  const toast = useToast()
  const input = useRef<HTMLInputElement>(null)
  const [open, setOpen] = useState<number | null>(null)
  const [rotating, setRotating] = useState(false)

  if (query.isPending) return <Card><PageLoader /></Card>
  if (query.isError) return <Card><ErrorState error={query.error} /></Card>
  const { address, enabled, emails } = query.data
  const waiting = emails.filter((e) => e.status === 'review')

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(address ?? '')
      toast.success('Address copied')
    } catch {
      toast.error('Copy failed; select the address and copy it')
    }
  }

  return (
    <Card>
      <CardHeader
        title="Email"
        description={
          enabled
            ? 'Forward, copy (Cc/Bcc) or send mail to this matter\'s address and it is filed here with its attachments. Mail from people outside the firm and the client waits for you to accept it.'
            : 'Upload emails saved from Outlook or Gmail (.eml). An administrator can also give every matter its own address to forward mail to.'
        }
        actions={canEdit && (
          <>
            <input ref={input} type="file" accept=".eml,message/rfc822" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload.mutate(f); e.target.value = '' }} />
            <Button size="sm" variant="tonal" icon={<Upload className="size-4" />} loading={upload.isPending} onClick={() => input.current?.click()}>Upload .eml</Button>
          </>
        )}
      />
      {address && (
        <div className="flex flex-wrap items-center gap-2 border-b border-outline-variant px-4 py-2 text-sm">
          <Mail className="size-4 text-on-surface-variant" aria-hidden />
          <code className="min-w-0 break-all select-all">{address}</code>
          <Button size="sm" variant="text" icon={<Copy className="size-4" />} onClick={copy}>Copy</Button>
          {canEdit && <Button size="sm" variant="text" icon={<RefreshCw className="size-4" />} onClick={() => setRotating(true)}>New address</Button>}
        </div>
      )}

      {emails.length === 0 ? (
        <EmptyState icon={<Mail className="size-6" />} title="No emails filed yet" />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {[...waiting, ...emails.filter((e) => e.status !== 'review')].map((e) => (
            <li key={e.id} className="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-2.5">
              <button type="button" onClick={() => setOpen(e.id)} disabled={e.status === 'rejected'} className="min-w-0 flex-1 text-left enabled:hover:text-primary">
                <div className="flex items-center gap-2">
                  <span className="truncate font-medium">{e.subject ?? '(no subject)'}</span>
                  {e.attachments.length > 0 && <span className="inline-flex shrink-0 items-center gap-0.5 text-xs text-on-surface-variant"><Paperclip className="size-3" aria-hidden />{e.attachments.length}</span>}
                </div>
                <div className="truncate text-xs text-on-surface-variant">
                  {e.from_name ? `${e.from_name} <${e.from_email}>` : e.from_email} · {dateTime(e.sent_at ?? e.received_at ?? '')}
                </div>
                {e.preview && e.status !== 'rejected' && <div className="truncate text-xs text-on-surface-variant">{e.preview}</div>}
              </button>
              <div className="flex shrink-0 items-center gap-1.5">
                <EmailStatus e={e} />
                {canEdit && e.status === 'review' && (
                  <>
                    <Button size="sm" variant="text" onClick={() => review.mutate({ id: e.id, action: 'reject' })}>Reject</Button>
                    <Button size="sm" variant="tonal" onClick={() => review.mutate({ id: e.id, action: 'accept' })}>File it</Button>
                  </>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {open !== null && <EmailDialog id={open} onClose={() => setOpen(null)} />}
      <ConfirmDialog
        open={rotating}
        onClose={() => setRotating(false)}
        title="Replace this matter's address?"
        description="Use this if the address reached people who should not have it. Mail sent to the old address will no longer be filed."
        confirmLabel="Replace address"
        loading={rotate.isPending}
        onConfirm={() => rotate.mutate(undefined, { onSuccess: () => setRotating(false) })}
      />
    </Card>
  )
}

function EmailStatus({ e }: { e: MatterEmail }) {
  if (e.status === 'review') return <Badge tone="warning">To review</Badge>
  if (e.status === 'queued') return <Badge tone="primary">Filing…</Badge>
  if (e.status === 'rejected') return <Badge>Rejected</Badge>
  return <Badge tone="success">Filed</Badge>
}

function EmailDialog({ id, onClose }: { id: number; onClose: () => void }) {
  const query = useMatterEmail(id)
  const e = query.data

  return (
    <Dialog open onClose={onClose} size="xl" title={e?.subject ?? 'Email'}>
      {query.isPending ? <PageLoader /> : query.isError || !e ? <ErrorState error={query.error} /> : (
        <div className="flex flex-col gap-3 text-sm">
          <dl className="grid grid-cols-[4rem_1fr] gap-x-3 gap-y-1">
            <dt className="text-on-surface-variant">From</dt><dd className="break-all">{e.from_name ? `${e.from_name} <${e.from_email}>` : e.from_email}</dd>
            {e.to && <><dt className="text-on-surface-variant">To</dt><dd className="break-all">{e.to}</dd></>}
            {e.cc && <><dt className="text-on-surface-variant">Cc</dt><dd className="break-all">{e.cc}</dd></>}
            <dt className="text-on-surface-variant">Date</dt><dd>{dateTime(e.sent_at ?? e.received_at ?? '')}</dd>
          </dl>
          {e.attachments.length > 0 && (
            <ul className="flex flex-wrap gap-2">
              {e.attachments.map((a, i) => (
                <li key={i} className="inline-flex items-center gap-1 rounded-[3px] border border-outline-variant px-2 py-1 text-xs">
                  <Paperclip className="size-3" aria-hidden />
                  {a.file_id ? <a href={fileDownloadUrl(a.file_id)} download className="text-primary hover:underline">{a.name}</a> : <span>{a.name}</span>}
                  {a.skipped && <span className="text-danger">({a.skipped})</span>}
                </li>
              ))}
            </ul>
          )}
          <pre className="max-h-[55vh] overflow-auto rounded-[3px] border border-outline-variant bg-surface-container p-3 font-sans text-sm whitespace-pre-wrap">{e.body_text || '(No text in this message.)'}</pre>
          {e.eml_file_id && <a href={fileDownloadUrl(e.eml_file_id)} download className="self-start text-sm text-primary hover:underline">Download the original message (.eml)</a>}
        </div>
      )}
    </Dialog>
  )
}
