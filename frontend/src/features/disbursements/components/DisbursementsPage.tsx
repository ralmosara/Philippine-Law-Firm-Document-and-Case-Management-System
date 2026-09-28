import { HandCoins, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { date, money } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Select } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useDisbursements, type Disbursement } from '../api'
import { DetailDialog, RequestDialog, StatusBadge } from './DisbursementDialogs'

const FILTERS = [
  { value: 'open', label: 'Open' },
  { value: 'pending', label: 'For approval' },
  { value: 'approved', label: 'To release' },
  { value: 'released', label: 'To liquidate' },
  { value: 'overdue', label: 'Liquidation overdue' },
  { value: 'closed', label: 'Closed' },
  { value: 'all', label: 'All' },
]

/** Cash advances across the firm (finance partners) or the user's own. */
export function DisbursementsPage() {
  const abilities = useAbilities()
  const [status, setStatus] = useUrlState('status', 'open')
  const [openId, setOpenId] = useUrlState('open', '')
  const [requesting, setRequesting] = useState(false)
  const query = useDisbursements({ status: openId ? 'all' : status })
  const selected = query.data?.data.find((d) => String(d.id) === openId)

  return (
    <>
      <PageHeader
        title="Cash advances"
        description={abilities.manage_finances ? 'Requests for case costs across the firm: approve, release, and see that each is liquidated with receipts.' : 'Your requests for case costs. Liquidate each with the receipts within a week of release.'}
        actions={abilities.work_matters && <Button icon={<Plus className="size-4" />} onClick={() => setRequesting(true)}>Request</Button>}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (() => {
        const { data, counts, categories } = query.data
        return (
          <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <StatCard label="For approval" value={String(counts.pending)} tone={counts.pending ? 'warning' : undefined} />
              <StatCard label="To release" value={String(counts.approved)} />
              <StatCard label="Out, not liquidated" value={money(counts.outstanding_cents)} detail={`${counts.released} advance(s)`} />
              <StatCard label="Liquidation overdue" value={String(counts.overdue)} tone={counts.overdue ? 'danger' : undefined} />
            </div>
            <Card>
              <CardHeader
                title="Requests"
                actions={<Select aria-label="Show" value={openId ? 'all' : status} onChange={(e) => { setOpenId(''); setStatus(e.target.value) }} className="w-48">{FILTERS.map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}</Select>}
              />
              {data.length === 0 ? <EmptyState icon={<HandCoins className="size-6" />} title="No requests here" /> : <DisbursementTable rows={data} onOpen={(d) => setOpenId(String(d.id))} showMatter />}
            </Card>
            {requesting && <RequestDialog categories={categories} onClose={() => setRequesting(false)} />}
            {selected && <DetailDialog key={selected.id} d={selected} categories={categories} onClose={() => setOpenId('')} />}
          </div>
        )
      })()}
    </>
  )
}

export function DisbursementTable({ rows, onOpen, showMatter }: { rows: Disbursement[]; onOpen: (d: Disbursement) => void; showMatter?: boolean }) {
  return (
    <Table caption="Cash advances">
      <thead>
        <tr>
          <Th>Requested</Th>
          {showMatter && <Th>Matter</Th>}
          <Th>For</Th>
          <Th>By</Th>
          <Th align="right">Amount</Th>
          <Th>Status</Th>
        </tr>
      </thead>
      <tbody>
        {rows.map((d) => (
          <Tr key={d.id} onClick={() => onOpen(d)}>
            <Td className="whitespace-nowrap">{d.needed_by ? <>Needed {date(d.needed_by)}</> : '—'}</Td>
            {showMatter && <Td>{d.matter && <Link to={`/matters/${d.matter.id}?tab=time`} onClick={(e) => e.stopPropagation()} className="hover:text-primary">{d.matter.reference}</Link>}</Td>}
            <Td>
              <div className="font-medium">{d.description}</div>
              <div className="text-xs text-on-surface-variant">{d.category_label}{d.source === 'trust' && ' · from trust'}</div>
            </Td>
            <Td className="text-on-surface-variant">{d.requester}</Td>
            <Td align="right" className="tabular-nums">{money(d.amount_cents)}</Td>
            <Td className="whitespace-nowrap">
              <StatusBadge d={d} />
              {d.status === 'released' && d.liquidation_due_on && <div className="text-xs text-on-surface-variant">by {date(d.liquidation_due_on)}</div>}
            </Td>
          </Tr>
        ))}
      </tbody>
    </Table>
  )
}
