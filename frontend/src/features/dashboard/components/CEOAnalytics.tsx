import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { get } from '@/shared/api/axios'
import type { Dashboard } from '@/shared/api/types'
import { duration, money, moneyCompact } from '@/shared/lib/format'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader, StatCard } from '@/shared/ui/Layout'

export function useDashboard(enabled: boolean) {
  return useQuery({ queryKey: ['dashboard'], queryFn: () => get<Dashboard>('/v1/analytics/dashboard'), enabled, staleTime: 60_000 })
}

/** Firm performance for partners: live figures from the ledger, invoices and time. */
export function CEOAnalytics() {
  const query = useDashboard(true)

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const { metrics: m, revenue_trend, matters_by_status, utilization } = query.data

  return (
    <section aria-labelledby="firm-performance" className="flex flex-col gap-6">
      <h2 id="firm-performance" className="text-lg font-semibold">Firm performance</h2>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Collected this year" value={money(m.revenue_collected_ytd_cents)} detail={m.collection_rate !== null ? `${m.collection_rate}% of ${moneyCompact(m.billed_ytd_cents)} billed` : 'Nothing billed yet this year'} />
        <StatCard label="Outstanding receivables" value={money(m.outstanding_receivables_cents)} detail={m.overdue_receivables_cents > 0 ? `${money(m.overdue_receivables_cents)} overdue` : 'None overdue'} tone={m.overdue_receivables_cents > 0 ? 'warning' : undefined} />
        <StatCard label="Unbilled work in progress" value={money(m.unbilled_wip_cents)} detail="Logged time not yet invoiced" />
        <StatCard label="Client funds held in trust" value={money(m.trust_funds_held_cents)} detail="Not firm revenue" />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <CardHeader title="Collections, last 6 months" description="Paid invoices by month paid" />
          <RevenueChart data={revenue_trend} />
        </Card>
        <Card className="lg:col-span-2">
          <CardHeader title="Matters by stage" description={`${m.active_matters} active · ${m.new_matters_this_month} opened this month`} />
          <StageBars data={matters_by_status} />
        </Card>
      </div>

      <Card>
        <CardHeader title="Lawyer utilization, this month" description="Billable time logged against a 120-hour monthly target, pro-rated to today" />
        {utilization.length === 0 ? (
          <p className="p-5 text-sm text-on-surface-variant">No lawyers yet.</p>
        ) : (
          <ul className="divide-y divide-outline-variant">
            {utilization.map((u) => (
              <li key={u.user_id} className="grid grid-cols-[minmax(8rem,14rem)_1fr_auto] items-center gap-4 px-5 py-3">
                <span className="truncate text-sm font-medium">{u.name}</span>
                <span className="relative h-2 rounded-full bg-surface-container-high" aria-hidden="true">
                  <span className="absolute inset-y-0 left-0 rounded-full bg-primary" style={{ width: `${Math.min(100, u.utilization_percent)}%` }} />
                </span>
                <span className="w-40 text-right text-sm tabular-nums text-on-surface-variant">
                  <span className="font-medium text-on-surface">{u.utilization_percent}%</span> · {duration(u.billable_minutes)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </section>
  )
}

/** Single-series column chart: one hue, 4px rounded tops, recessive grid, hover tooltip. */
function RevenueChart({ data }: { data: Dashboard['revenue_trend'] }) {
  const [active, setActive] = useState<number | null>(null)
  const max = Math.max(...data.map((d) => d.collected_cents), 1)
  const W = 600
  const H = 220
  const pad = { top: 16, bottom: 28, left: 8, right: 8 }
  const plotH = H - pad.top - pad.bottom
  const slot = (W - pad.left - pad.right) / data.length
  const barW = Math.min(48, slot * 0.55)
  const total = data.reduce((s, d) => s + d.collected_cents, 0)

  return (
    <figure className="p-5">
      <div className="relative">
        <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label={`Collections by month: ${data.map((d) => `${d.label} ${money(d.collected_cents)}`).join(', ')}`}>
          {[0.5, 1].map((f) => (
            <line key={f} x1={pad.left} x2={W - pad.right} y1={pad.top + plotH * (1 - f)} y2={pad.top + plotH * (1 - f)} stroke="var(--color-outline-variant)" strokeDasharray="2 4" />
          ))}
          <line x1={pad.left} x2={W - pad.right} y1={pad.top + plotH} y2={pad.top + plotH} stroke="var(--color-outline)" />
          {data.map((d, i) => {
            const h = (d.collected_cents / max) * plotH
            const x = pad.left + slot * i + (slot - barW) / 2
            const y = pad.top + plotH - h
            const r = Math.min(4, h)
            return (
              <g key={d.month} onMouseEnter={() => setActive(i)} onMouseLeave={() => setActive(null)}>
                {/* Hit target larger than the mark. */}
                <rect x={pad.left + slot * i} y={pad.top} width={slot} height={plotH} fill="transparent" />
                {h > 0 && (
                  <path
                    d={`M${x},${pad.top + plotH} V${y + r} Q${x},${y} ${x + r},${y} H${x + barW - r} Q${x + barW},${y} ${x + barW},${y + r} V${pad.top + plotH} Z`}
                    fill="var(--color-primary)"
                    opacity={active === null || active === i ? 1 : 0.45}
                  />
                )}
                <text x={x + barW / 2} y={H - 8} textAnchor="middle" fontSize="12" fill="var(--color-on-surface-variant)">{d.label}</text>
                {i === data.length - 1 && h > 0 && (
                  <text x={x + barW / 2} y={y - 6} textAnchor="middle" fontSize="12" fontWeight="600" fill="var(--color-on-surface)">{moneyCompact(d.collected_cents)}</text>
                )}
              </g>
            )
          })}
        </svg>
        {active !== null && data[active] && (
          <div role="tooltip" className="pointer-events-none absolute top-0 rounded-[3px] bg-surface-container-high px-3 py-2 text-xs shadow-(--shadow-elevated)" style={{ left: `${((active + 0.5) / data.length) * 100}%`, transform: 'translateX(-50%)' }}>
            <p className="text-on-surface-variant">{data[active].month}</p>
            <p className="font-semibold text-on-surface tabular-nums">{money(data[active].collected_cents)}</p>
          </div>
        )}
      </div>
      <figcaption className="mt-2 text-sm text-on-surface-variant">{money(total)} collected over six months.</figcaption>
    </figure>
  )
}

/** Horizontal bars, directly labeled with counts (stage order, not sorted by size). */
function StageBars({ data }: { data: Dashboard['matters_by_status'] }) {
  const max = Math.max(...data.map((d) => d.count), 1)
  return (
    <ul className="flex flex-col gap-3 p-5">
      {data.map((d) => (
        <li key={d.status} className="grid grid-cols-[5.5rem_1fr_2rem] items-center gap-3 text-sm" title={`${d.label}: ${d.count}`}>
          <span className="text-on-surface-variant">{d.label}</span>
          <span className="h-3" aria-hidden="true">
            {d.count > 0 && <span className="block h-full rounded-r-[4px] bg-primary" style={{ width: `${(d.count / max) * 100}%` }} />}
          </span>
          <span className="text-right font-medium tabular-nums">{d.count}</span>
        </li>
      ))}
    </ul>
  )
}
