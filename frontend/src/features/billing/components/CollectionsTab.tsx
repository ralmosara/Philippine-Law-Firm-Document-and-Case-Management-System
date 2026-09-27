import { BellOff, BellRing, CalendarClock, Landmark, Mail } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useFirmSettings, useSaveFirmSettings } from '@/features/settings/api'
import { date, dateTime, money } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { useCollections, usePauseReminders, useRequestReplenishment, useSendReminder } from '../api'

/** Everything that needs following up: unpaid invoices, low trust deposits, retainers. */
export function CollectionsTab() {
  const query = useCollections()
  const firm = useFirmSettings()
  const saveFirm = useSaveFirmSettings()
  const remind = useSendReminder()
  const pause = usePauseReminders()
  const replenish = useRequestReplenishment()

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const data = query.data

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader
          title="Payment reminders"
          description="When on, clients with an e-mail address get a courteous reminder with the billing statement attached: 3 days before the due date, then 1 week and 1 month after. Each goes out once; pause an invoice while a client disputes it."
        />
        <div className="p-5">
          <Checkbox
            label="Send payment reminders automatically"
            checked={firm.data?.payment_reminders_enabled ?? data.reminders_enabled}
            disabled={!firm.data || saveFirm.isPending}
            onChange={(e) => saveFirm.mutate({ payment_reminders_enabled: e.target.checked }, { onSuccess: () => query.refetch() })}
          />
        </div>
      </Card>

      <Card>
        <CardHeader title="Due soon and overdue" description="Issued invoices due within 3 days or already past due, oldest first." />
        {data.invoices.length === 0 ? (
          <EmptyState icon={<Mail className="size-6" />} title="Nothing to follow up" description="No invoice is due in the next 3 days or overdue." />
        ) : (
          <Table caption="Invoices to follow up">
            <thead><tr><Th>Invoice</Th><Th>Client</Th><Th>Due</Th><Th align="right">Balance</Th><Th>Reminders</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
            <tbody>
              {data.invoices.map((i) => (
                <tr key={i.id}>
                  <Td><Link to={`/billing/invoices/${i.id}`} className="font-medium text-primary hover:underline">{i.number}</Link><div className="text-xs text-on-surface-variant">{i.matter}</div></Td>
                  <Td>{i.client}{!i.client_has_email && <div className="text-xs text-danger">No e-mail on file</div>}</Td>
                  <Td className="whitespace-nowrap">
                    {date(i.due_at)}
                    <div>{i.days_overdue > 0 ? <Badge tone="danger">{i.days_overdue} days overdue</Badge> : <Badge tone="warning">{i.days_overdue === 0 ? 'Due today' : `Due in ${-i.days_overdue} days`}</Badge>}</div>
                  </Td>
                  <Td align="right" className="font-medium">{money(i.balance_cents)}</Td>
                  <Td className="text-sm">
                    {i.reminders_paused ? <Badge><BellOff className="size-3" aria-hidden /> Paused</Badge> : i.last_reminder ? <span>{i.last_reminder.label}, {dateTime(i.last_reminder.sent_at)}</span> : <span className="text-on-surface-variant">None yet</span>}
                    {!i.reminders_paused && i.next_reminder && data.reminders_enabled && <div className="text-xs text-on-surface-variant">Next: {i.next_reminder.toLowerCase()}, at the next 9 AM run</div>}
                  </Td>
                  <Td align="right" className="whitespace-nowrap">
                    <Button size="sm" variant="text" icon={<BellRing className="size-4" />} disabled={!i.client_has_email || remind.isPending} onClick={() => remind.mutate(i.id)}>Remind now</Button>
                    <Button size="sm" variant="text" onClick={() => pause.mutate({ invoiceId: i.id, paused: !i.reminders_paused })}>{i.reminders_paused ? 'Resume' : 'Pause'}</Button>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      <Card>
        <CardHeader title="Trust deposits below minimum" description="Set a minimum balance on a trust account (Trust Accounts → the account). When it drops below, the client is asked to top it up, at most once a week, each morning at 9." />
        {data.trust_below_minimum.length === 0 ? (
          <EmptyState icon={<Landmark className="size-6" />} title="Every deposit is above its minimum" />
        ) : (
          <Table caption="Trust accounts below minimum" compact>
            <thead><tr><Th>Account</Th><Th>Client</Th><Th align="right">Balance</Th><Th align="right">Minimum</Th><Th>Last request</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
            <tbody>
              {data.trust_below_minimum.map((a) => (
                <tr key={a.id}>
                  <Td><Link to={`/trust/${a.id}`} className="font-medium text-primary hover:underline">{a.account_number}</Link></Td>
                  <Td>{a.client}</Td>
                  <Td align="right">{money(a.balance_cents)}</Td>
                  <Td align="right">{money(a.minimum_balance_cents)}</Td>
                  <Td className="text-sm">{a.replenishment_requested_at ? dateTime(a.replenishment_requested_at) : '—'}</Td>
                  <Td align="right"><Button size="sm" variant="text" disabled={!a.client_has_email || replenish.isPending} onClick={() => replenish.mutate(a.id)}>Ask to top up</Button></Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      <Card>
        <CardHeader title="Monthly retainers" description="Turn on automatic billing in each matter's fee settings (Edit matter). Invoices are drafted on the billing day, or issued and e-mailed if you choose." />
        {data.retainers.length === 0 ? (
          <EmptyState icon={<CalendarClock className="size-6" />} title="No retainer matters" />
        ) : (
          <Table caption="Retainer matters" compact>
            <thead><tr><Th>Matter</Th><Th align="right">Monthly</Th><Th>Automatic</Th><Th>Last billed</Th><Th>Next</Th></tr></thead>
            <tbody>
              {data.retainers.map((m) => (
                <tr key={m.id}>
                  <Td><Link to={`/matters/${m.id}`} className="font-medium text-primary hover:underline">{m.title}</Link><div className="text-xs text-on-surface-variant">{m.reference} · {m.client}</div></Td>
                  <Td align="right">{money(m.amount_cents)}</Td>
                  <Td>{m.auto_bill ? <Badge tone="success">{m.auto_issue ? 'Issued and e-mailed' : 'Drafted'} on day {m.billing_day}</Badge> : <Badge>Off</Badge>}</Td>
                  <Td>{m.billed_through ?? '—'}</Td>
                  <Td>{m.next_billing ? date(m.next_billing) : '—'}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  )
}
