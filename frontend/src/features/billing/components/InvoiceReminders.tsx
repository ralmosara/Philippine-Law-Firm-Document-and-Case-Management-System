import { BellOff, BellRing } from 'lucide-react'
import { useAbilities } from '@/features/auth/session'
import type { Invoice } from '@/shared/api/types'
import { dateTime, money } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Badge } from '@/shared/ui/Feedback'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { usePauseReminders, useSendReminder } from '../api'

/** Payment reminders sent for an invoice, with "remind now" and pause. */
export function InvoiceReminders({ invoice }: { invoice: Invoice }) {
  const abilities = useAbilities()
  const remind = useSendReminder()
  const pause = usePauseReminders()
  const receivable = invoice.status === 'issued' || invoice.status === 'partially_paid'
  const reminders = invoice.reminders ?? []

  if (!receivable && reminders.length === 0) return null

  return (
    <Card className="mx-auto mt-6 max-w-4xl print:hidden">
      <CardHeader
        title="Payment reminders"
        description={invoice.reminders_paused_at ? `Paused since ${dateTime(invoice.reminders_paused_at)}: no automatic reminders for this invoice.` : 'Automatic reminders follow the firm setting under Time & Billing → Collections.'}
        actions={abilities.manage_finances && receivable && (
          <div className="flex gap-1">
            <Button size="sm" variant="text" icon={invoice.reminders_paused_at ? <BellRing className="size-4" /> : <BellOff className="size-4" />} onClick={() => pause.mutate({ invoiceId: invoice.id, paused: !invoice.reminders_paused_at })}>
              {invoice.reminders_paused_at ? 'Resume' : 'Pause'}
            </Button>
            <Button size="sm" variant="tonal" icon={<BellRing className="size-4" />} loading={remind.isPending} onClick={() => remind.mutate(invoice.id)}>Remind now</Button>
          </div>
        )}
      />
      {reminders.length === 0 ? (
        <p className="px-5 py-4 text-sm text-on-surface-variant">No reminders sent yet.</p>
      ) : (
        <ul className="divide-y divide-outline-variant text-sm">
          {reminders.map((r) => (
            <li key={r.sent_at} className="flex flex-wrap items-center gap-2 px-5 py-3">
              <Badge>{r.label}</Badge>
              <span>{dateTime(r.sent_at)}</span>
              <span className="text-on-surface-variant">balance then {money(r.balance_cents)}{r.sent_by && `, by ${r.sent_by}`}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
