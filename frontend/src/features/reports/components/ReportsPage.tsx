import { useQuery } from '@tanstack/react-query'
import { FileSpreadsheet } from 'lucide-react'
import type { ReactNode } from 'react'
import { get } from '@/shared/api/axios'
import { money, today } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { DownloadButton } from '@/shared/ui/Button'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, Input } from '@/shared/ui/Form'
import { Card, PageHeader, StatCard, Table, Tabs, Td, Th } from '@/shared/ui/Layout'

type Report = 'aged-receivables' | 'collections' | 'matter-profitability' | 'write-offs'
type Row = Record<string, string | number | null>
interface ReportData { rows: Row[]; totals: Record<string, number> }

const BUCKETS: [string, string][] = [['current', 'Not yet due'], ['d1_30', '1–30 days late'], ['d31_60', '31–60 days late'], ['d61_90', '61–90 days late'], ['d90_plus', 'Over 90 days late']]

function useReport(report: Report, params: Record<string, string>) {
  return useQuery({ queryKey: ['reports', report, params], queryFn: () => get<ReportData>(`/v1/reports/${report}`, params) })
}

const csvUrl = (report: Report, params: Record<string, string>) => `/api/v1/reports/${report}?${new URLSearchParams({ ...params, format: 'csv' })}`

/** Receivables, collections and matter economics for firm management. */
export function ReportsPage() {
  const [tab, setTab] = useUrlState('tab', 'aged-receivables')

  return (
    <>
      <PageHeader title="Reports" description="Figures are live from invoices, time and expenses. Download any report as CSV for Excel." />
      <Tabs<Report>
        label="Reports"
        value={tab as Report}
        onChange={setTab}
        tabs={[
          { value: 'aged-receivables', label: 'Aged receivables' },
          { value: 'collections', label: 'Collections by lawyer' },
          { value: 'matter-profitability', label: 'Matter profitability' },
          { value: 'write-offs', label: 'Write-offs' },
        ]}
      />
      {tab === 'aged-receivables' && <AgedReceivables />}
      {tab === 'collections' && <Collections />}
      {tab === 'matter-profitability' && <Profitability />}
      {tab === 'write-offs' && <WriteOffs />}
    </>
  )
}

function ReportCard({ report, params, toolbar, children, query }: { report: Report; params: Record<string, string>; toolbar?: ReactNode; children: (data: ReportData) => ReactNode; query: ReturnType<typeof useReport> }) {
  return (
    <Card>
      <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-end">
        <div className="flex flex-1 flex-wrap gap-3">{toolbar}</div>
        <DownloadButton href={csvUrl(report, params)} variant="tonal" icon={<FileSpreadsheet className="size-4" />}>Download CSV</DownloadButton>
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} onRetry={() => query.refetch()} /></div> : query.data.rows.length === 0 ? <EmptyState title="Nothing to report for this period" /> : children(query.data)}
    </Card>
  )
}

function AgedReceivables() {
  const [asOf, setAsOf] = useUrlState('as_of', today())
  const params = { as_of: asOf }
  const query = useReport('aged-receivables', params)

  return (
    <div className="flex flex-col gap-6">
      {query.data && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
          {BUCKETS.map(([key, label]) => (
            <StatCard key={key} label={label} value={money(query.data.totals[key])} tone={key === 'd90_plus' && (query.data.totals[key] ?? 0) > 0 ? 'danger' : key === 'd61_90' && (query.data.totals[key] ?? 0) > 0 ? 'warning' : undefined} />
          ))}
        </div>
      )}
      <ReportCard report="aged-receivables" params={params} query={query} toolbar={<Field label="As of">{(a) => <Input {...a} type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />}</Field>}>
        {(data) => (
          <Table caption="Aged receivables by client">
            <thead><tr><Th>Client</Th><Th align="right">Invoices</Th>{BUCKETS.map(([k, l]) => <Th key={k} align="right">{l}</Th>)}<Th align="right">Total</Th></tr></thead>
            <tbody>
              {data.rows.map((r, i) => (
                <tr key={i}>
                  <Td className="font-medium">{r.client}</Td>
                  <Td align="right">{r.invoices}</Td>
                  {BUCKETS.map(([k]) => <Td key={k} align="right" className={k === 'd90_plus' && Number(r[k]) > 0 ? 'font-semibold text-danger' : undefined}>{Number(r[k]) ? money(Number(r[k])) : '—'}</Td>)}
                  <Td align="right" className="font-semibold">{money(Number(r.total))}</Td>
                </tr>
              ))}
              <TotalsRow cells={['Total', '', ...BUCKETS.map(([k]) => money(data.totals[k])), money(data.totals.total)]} />
            </tbody>
          </Table>
        )}
      </ReportCard>
    </div>
  )
}

/** What was not charged: written down or discounted before billing, written off after. */
function WriteOffs() {
  const [from, setFrom] = useUrlState('from', `${new Date().getFullYear()}-01-01`)
  const [to, setTo] = useUrlState('to', today())
  const params = { from, to }
  const query = useReport('write-offs', params)
  const amount = (n: unknown) => (Number(n) ? money(Number(n)) : '—')

  return (
    <ReportCard
      report="write-offs"
      params={params}
      query={query}
      toolbar={
        <>
          <Field label="From">{(a) => <Input {...a} type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />}</Field>
          <Field label="To">{(a) => <Input {...a} type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
          <p className="text-sm text-on-surface-variant sm:self-end">Write-downs and discounts by issue date; write-offs by the date written off. Fees before VAT, except write-offs (the unpaid balance).</p>
        </>
      }
    >
      {(data) => (
        <Table caption="Write-offs by responsible lawyer">
          <thead><tr><Th>Responsible lawyer</Th><Th align="right">Invoices</Th><Th align="right">Written down</Th><Th align="right">Discounts</Th><Th align="right">Written off</Th><Th align="right">Total not charged</Th></tr></thead>
          <tbody>
            {data.rows.map((r, i) => (
              <tr key={i}>
                <Td className="font-medium">{r.lawyer}</Td>
                <Td align="right">{r.invoices}</Td>
                <Td align="right">{amount(r.written_down)}</Td>
                <Td align="right">{amount(r.discounted)}</Td>
                <Td align="right">{amount(r.written_off)}</Td>
                <Td align="right" className="font-semibold">{money(Number(r.total))}</Td>
              </tr>
            ))}
            <TotalsRow cells={['Total', String(data.totals.invoices), money(data.totals.written_down), money(data.totals.discounted), money(data.totals.written_off), money(data.totals.total)]} />
          </tbody>
        </Table>
      )}
    </ReportCard>
  )
}

function Collections() {
  const [from, setFrom] = useUrlState('from', `${new Date().getFullYear()}-01-01`)
  const [to, setTo] = useUrlState('to', today())
  const params = { from, to }
  const query = useReport('collections', params)

  return (
    <ReportCard
      report="collections"
      params={params}
      query={query}
      toolbar={
        <>
          <Field label="From">{(a) => <Input {...a} type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} />}</Field>
          <Field label="To">{(a) => <Input {...a} type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />}</Field>
          <p className="text-sm text-on-surface-variant sm:self-end">By date received, partial payments included. Tax withheld counts as collected.</p>
        </>
      }
    >
      {(data) => (
        <Table caption="Collections by responsible lawyer">
          <thead><tr><Th>Responsible lawyer</Th><Th align="right">Payments</Th><Th align="right">Cash received</Th><Th align="right">Tax withheld (2307)</Th><Th align="right">Total collected</Th></tr></thead>
          <tbody>
            {data.rows.map((r, i) => (
              <tr key={i}>
                <Td className="font-medium">{r.lawyer}</Td>
                <Td align="right">{r.payments}</Td>
                <Td align="right">{money(Number(r.received))}</Td>
                <Td align="right">{Number(r.withheld) ? money(Number(r.withheld)) : '—'}</Td>
                <Td align="right" className="font-semibold">{money(Number(r.total))}</Td>
              </tr>
            ))}
            <TotalsRow cells={['Total', String(data.totals.payments), money(data.totals.received), money(data.totals.withheld), money(data.totals.total)]} />
          </tbody>
        </Table>
      )}
    </ReportCard>
  )
}

function Profitability() {
  const query = useReport('matter-profitability', {})

  return (
    <ReportCard report="matter-profitability" params={{}} query={query} toolbar={<p className="text-sm text-on-surface-variant">Fees only (before VAT). Collection rate = fees collected ÷ fees billed.</p>}>
      {(data) => (
        <Table caption="Matter profitability">
          <thead><tr><Th>Matter</Th><Th>Lawyer</Th><Th align="right">Time recorded</Th><Th align="right">Billed</Th><Th align="right">Collected</Th><Th align="right">Unbilled</Th><Th align="right">Expenses</Th><Th align="right">Collection rate</Th></tr></thead>
          <tbody>
            {data.rows.map((r, i) => (
              <tr key={i}>
                <Td><span className="font-medium">{r.title}</span><div className="text-xs text-on-surface-variant">{r.reference} · {r.client} · {r.status}</div></Td>
                <Td>{r.lawyer ?? '—'}</Td>
                <Td align="right">{money(Number(r.recorded))}</Td>
                <Td align="right">{money(Number(r.billed))}</Td>
                <Td align="right">{money(Number(r.collected))}</Td>
                <Td align="right">{Number(r.unbilled) ? money(Number(r.unbilled)) : '—'}</Td>
                <Td align="right">{Number(r.expenses) ? money(Number(r.expenses)) : '—'}</Td>
                <Td align="right">{r.collection_rate === null ? '—' : `${r.collection_rate}%`}</Td>
              </tr>
            ))}
            <TotalsRow cells={['Total', '', money(data.totals.recorded), money(data.totals.billed), money(data.totals.collected), money(data.totals.unbilled), money(data.totals.expenses), '']} />
          </tbody>
        </Table>
      )}
    </ReportCard>
  )
}

function TotalsRow({ cells }: { cells: string[] }) {
  return (
    <tr className="bg-surface-container font-semibold">
      {cells.map((c, i) => <Td key={i} align={i < 1 ? undefined : 'right'}>{c}</Td>)}
    </tr>
  )
}
