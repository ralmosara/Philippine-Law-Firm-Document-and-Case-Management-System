import { FileDown, Send } from 'lucide-react'
import { useState } from 'react'
import { useAbilities } from '@/features/auth/session'
import { date, money } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { AGING_LABELS, useClientStatement, useSendStatement, type AgingBucket } from './api'

/** What the client owes, by age, with the statement of account to download or email. */
export function StatementCard({ clientId, clientEmail }: { clientId: number; clientEmail: string | null }) {
  const abilities = useAbilities()
  const query = useClientStatement(clientId)
  const send = useSendStatement(clientId)
  const [confirming, setConfirming] = useState(false)

  return (
    <Card>
      <CardHeader title="Statement of account" />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : (
        <div className="flex flex-col gap-4 p-5">
          <div>
            <p className="text-2xl font-semibold tabular-nums">{money(query.data.total_due)}</p>
            <p className="text-sm text-on-surface-variant">
              owed on {query.data.open_invoices} billing statement{query.data.open_invoices === 1 ? '' : 's'}
            </p>
          </div>
          {query.data.total_due > 0 && (
            <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
              {(Object.keys(AGING_LABELS) as AgingBucket[]).filter((k) => query.data.aging[k] > 0).map((k) => (
                <div key={k} className="contents">
                  <dt className={k === 'current' ? 'text-on-surface-variant' : 'text-danger'}>{AGING_LABELS[k]}{k !== 'current' && ' overdue'}</dt>
                  <dd className="text-right tabular-nums">{money(query.data.aging[k])}</dd>
                </div>
              ))}
            </dl>
          )}
          {query.data.sent_on && <p className="text-xs text-on-surface-variant">Last emailed {date(query.data.sent_on)}.</p>}
          <div className="flex flex-wrap gap-2">
            <DownloadButton href={`/api/v1/clients/${clientId}/statement/pdf`} size="sm" icon={<FileDown className="size-4" />}>PDF</DownloadButton>
            {abilities.manage_finances && (
              <Button size="sm" variant="tonal" icon={<Send className="size-4" />} disabled={!clientEmail || !query.data.has_content} onClick={() => setConfirming(true)}>
                Email to client
              </Button>
            )}
          </div>
          {abilities.manage_finances && !clientEmail && <p className="text-xs text-on-surface-variant">Add the client’s email address to send it.</p>}
          {abilities.manage_finances && clientEmail && !query.data.has_content && <p className="text-xs text-on-surface-variant">Nothing to send: nothing owed and no funds in trust.</p>}
        </div>
      )}
      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        title="Email the statement of account?"
        description={`It goes to ${clientEmail} as a PDF, with the email in the client’s language. Open billing statements, payments in the last 30 days and trust funds are included.`}
        confirmLabel="Send"
        loading={send.isPending}
        onConfirm={() => send.mutate(undefined, { onSettled: () => setConfirming(false) })}
      />
    </Card>
  )
}
