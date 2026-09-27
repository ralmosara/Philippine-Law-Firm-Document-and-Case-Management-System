import { Clock, ReceiptText } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { InvoiceStatusBadge } from '@/features/matters/components/StatusBadge'
import type { InvoiceStatus } from '@/shared/api/types'
import { date, duration, money } from '@/shared/lib/format'
import { useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Input, Select } from '@/shared/ui/Form'
import { Card, PageHeader, Pagination, StatCard, Table, Tabs, Td, Th, Tr } from '@/shared/ui/Layout'
import { useInvoices, useTimeEntries } from '../api'
import { CollectionsTab } from './CollectionsTab'
import { Awaiting2307Tab } from './InvoicePayments'
import { TimeEntriesTable } from './TimeEntriesTable'
import { TimeTrackingForm } from './TimeTrackingForm'

type Tab = 'time' | 'invoices' | 'collections' | 'form-2307'

export function BillingDashboard() {
  const abilities = useAbilities()
  const [tab, setTab] = useUrlState('tab', 'time')
  const [logging, setLogging] = useState(false)

  return (
    <>
      <PageHeader
        title="Time & Billing"
        description="Log billable work and turn it into invoices."
        actions={abilities.work_matters && <Button icon={<Clock className="size-4" />} onClick={() => setLogging(true)}>Log time</Button>}
      />
      <Tabs<Tab>
        label="Billing sections"
        value={tab as Tab}
        onChange={setTab}
        tabs={[{ value: 'time', label: 'Time entries' }, ...(abilities.practice_law ? [{ value: 'invoices' as const, label: 'Invoices' }] : []), ...(abilities.manage_finances ? [{ value: 'collections' as const, label: 'Collections' }, { value: 'form-2307' as const, label: 'Form 2307' }] : [])]}
      />
      {tab === 'time' && <TimeTab />}
      {tab === 'invoices' && abilities.practice_law && <InvoicesTab />}
      {tab === 'collections' && abilities.manage_finances && <CollectionsTab />}
      {tab === 'form-2307' && abilities.manage_finances && <Awaiting2307Tab />}
      <TimeTrackingForm open={logging} onClose={() => setLogging(false)} />
    </>
  )
}

function TimeTab() {
  const abilities = useAbilities()
  const [mine, setMine] = useUrlState('mine', abilities.manage_finances ? '' : '1')
  const [unbilled, setUnbilled] = useUrlState('unbilled')
  const [from, setFrom] = useUrlState('from')
  const [to, setTo] = useUrlState('to')
  const [page, setPage] = useUrlPage()
  const query = useTimeEntries({ mine: mine === '1', unbilled: unbilled === '1', from, to, page })

  return (
    <div className="flex flex-col gap-6">
      {query.data && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <StatCard label="Time in view" value={duration(query.data.totals.minutes)} />
          <StatCard label="Value in view" value={money(query.data.totals.amount_cents)} />
        </div>
      )}
      <Card>
        <div className="flex flex-col flex-wrap gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-center">
          <Checkbox label="Only mine" checked={mine === '1'} onChange={(e) => setMine(e.target.checked ? '1' : '')} />
          <Checkbox label="Unbilled only" checked={unbilled === '1'} onChange={(e) => setUnbilled(e.target.checked ? '1' : '')} />
          <div className="flex items-center gap-2 sm:ml-auto">
            <Input type="date" aria-label="From date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" />
            <span className="text-on-surface-variant">to</span>
            <Input type="date" aria-label="To date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" />
          </div>
        </div>
        {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : <TimeEntriesTable entries={query.data.data} />}
        <Pagination page={query.data} onPage={setPage} />
      </Card>
    </div>
  )
}

function InvoicesTab() {
  const navigate = useNavigate()
  const [status, setStatus] = useUrlState('status')
  const [page, setPage] = useUrlPage()
  const query = useInvoices({ status: status as InvoiceStatus | '', page })

  return (
    <Card>
      <div className="flex items-center gap-3 border-b border-outline-variant p-4">
        <Select aria-label="Invoice status" value={status} onChange={(e) => setStatus(e.target.value)} className="w-48">
          <option value="">All invoices</option>
          <option value="draft">Drafts</option>
          <option value="issued">Issued (unpaid)</option>
          <option value="partially_paid">Partly paid</option>
          <option value="paid">Paid</option>
          <option value="void">Void</option>
        </Select>
        <p className="text-sm text-on-surface-variant">Draft invoices from a matter’s Billing tab.</p>
      </div>
      {query.isPending ? (
        <PageLoader />
      ) : query.isError ? (
        <div className="p-4"><ErrorState error={query.error} /></div>
      ) : query.data.data.length === 0 ? (
        <EmptyState icon={<ReceiptText className="size-6" />} title="No invoices" />
      ) : (
        <Table caption="Invoices">
          <thead>
            <tr><Th>Number</Th><Th>Client · Matter</Th><Th>Status</Th><Th>Issued</Th><Th>Due</Th><Th align="right">Total</Th><Th align="right">Balance</Th></tr>
          </thead>
          <tbody>
            {query.data.data.map((i) => (
              <Tr key={i.id} onClick={() => navigate(`/billing/invoices/${i.id}`)}>
                <Td><Link to={`/billing/invoices/${i.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-primary hover:underline">{i.number}</Link></Td>
                <Td>{i.client?.name}<div className="text-xs text-on-surface-variant">{i.matter?.reference} · {i.matter?.title}</div></Td>
                <Td><InvoiceStatusBadge status={i.status} overdue={i.is_overdue} /></Td>
                <Td className="whitespace-nowrap">{date(i.issued_at)}</Td>
                <Td className="whitespace-nowrap">{date(i.due_at)}</Td>
                <Td align="right">{money(i.total_cents)}</Td>
                <Td align="right">{i.status === 'issued' || i.status === 'partially_paid' ? money(i.balance_cents) : '—'}</Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      )}
      <Pagination page={query.data} onPage={setPage} />
    </Card>
  )
}
