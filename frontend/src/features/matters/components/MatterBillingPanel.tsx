import { Landmark, ReceiptText } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useInvoices } from '@/features/billing/api'
import { InvoiceDraftDialog } from '@/features/billing/components/InvoiceDraftDialog'
import { useOpenTrustAccount, useTrustAccounts } from '@/features/trust/api'
import type { Matter } from '@/shared/api/types'
import { date, money } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { EmptyState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { InvoiceStatusBadge } from './StatusBadge'

export function MatterBillingPanel({ matter }: { matter: Matter }) {
  const abilities = useAbilities()
  const navigate = useNavigate()
  const invoices = useInvoices({ matter_id: matter.id, enabled: abilities.practice_law })
  const trust = useTrustAccounts({ client_id: matter.client_id })
  const [drafting, setDrafting] = useState(false)
  const openTrust = useOpenTrustAccount()

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      {abilities.practice_law && (
        <Card>
          <CardHeader
            title="Invoices"
            description={[
              matter.unbilled_cents ? `${money(matter.unbilled_cents)} unbilled time` : null,
              matter.unbilled_expenses_cents ? `${money(matter.unbilled_expenses_cents)} unbilled expenses` : null,
            ].filter(Boolean).join(' · ') || 'Nothing unbilled'}
            actions={
              abilities.manage_finances && (
                <Button variant="tonal" size="sm" onClick={() => setDrafting(true)}>
                  Draft invoice
                </Button>
              )
            }
          />
          {invoices.isPending ? (
            <PageLoader />
          ) : !invoices.data?.data.length ? (
            <EmptyState icon={<ReceiptText className="size-6" />} title="No invoices yet" />
          ) : (
            <Table caption="Invoices" compact>
              <thead>
                <tr><Th>Number</Th><Th>Status</Th><Th>Issued</Th><Th align="right">Total</Th></tr>
              </thead>
              <tbody>
                {invoices.data.data.map((i) => (
                  <Tr key={i.id} onClick={() => navigate(`/billing/invoices/${i.id}`)}>
                    <Td><Link to={`/billing/invoices/${i.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-primary hover:underline">{i.number}</Link></Td>
                    <Td><InvoiceStatusBadge status={i.status} overdue={i.is_overdue} /></Td>
                    <Td>{date(i.issued_at)}</Td>
                    <Td align="right">{money(i.total_cents)}</Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      )}

      <Card>
        <CardHeader
          title="Client trust funds"
          description="Held in trust for the client; only partners may disburse."
          actions={
            abilities.manage_finances && (
              <Button variant="tonal" size="sm" loading={openTrust.isPending} onClick={() => openTrust.mutate({ client_id: matter.client_id, matter_id: matter.id }, { onSuccess: (a) => navigate(`/trust/${a.id}`) })}>
                Open account
              </Button>
            )
          }
        />
        {trust.isPending ? (
          <PageLoader />
        ) : !trust.data?.data.length ? (
          <EmptyState icon={<Landmark className="size-6" />} title="No trust account" description="Open one to record deposits for filing fees and costs." />
        ) : (
          <ul className="divide-y divide-outline-variant">
            {trust.data.data.map((a) => (
              <li key={a.id}>
                <Link to={`/trust/${a.id}`} className="flex items-center justify-between px-5 py-4 hover:bg-surface-container">
                  <span>
                    <span className="block font-medium">{a.account_number}</span>
                    <span className="block text-sm text-on-surface-variant">{a.matter ? a.matter.reference : 'General (client-level)'}{a.status === 'closed' && ' · Closed'}</span>
                  </span>
                  <span className="text-lg font-semibold tabular-nums">{money(a.balance_cents)}</span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {drafting && <InvoiceDraftDialog matter={matter} onClose={() => setDrafting(false)} />}
    </div>
  )
}
