import { Plus, TrendingUp } from 'lucide-react'
import { useState } from 'react'
import { useAbilities } from '@/features/auth/session'
import { date, money, today } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Input, SearchInput } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Tabs, Td, Th, Tr } from '@/shared/ui/Layout'
import { OPEN_STAGES, useProspectReport, useProspects, type Prospect } from '../api'
import { ProspectDetailDialog, ProspectForm } from './ProspectDialogs'

type Tab = 'pipeline' | 'closed' | 'report'

/** Business development: prospects from first contact to engagement, and where they come from. */
export function ProspectsPage() {
  const abilities = useAbilities()
  const [tab, setTab] = useUrlState('tab', 'pipeline')
  const [openId, setOpenId] = useUrlState('open', '')
  const [search, setSearch] = useUrlState('q', '')
  const [editing, setEditing] = useState<Prospect | 'new' | null>(null)
  const query = useProspects({ closed: tab === 'closed', search })
  const stages = query.data?.stages ?? {}
  const sources = query.data?.sources ?? {}

  return (
    <>
      <PageHeader
        title="Business development"
        description="Prospective clients from first contact to a signed engagement. Each is conflict-checked when added; a won prospect becomes a client and matter in one step."
        actions={<Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add prospect</Button>}
      />
      <Tabs<Tab> label="Business development sections" value={tab as Tab} onChange={setTab}
        tabs={[{ value: 'pipeline', label: 'Pipeline' }, { value: 'closed', label: 'Engaged & lost' }, ...(abilities.manage_finances ? [{ value: 'report' as const, label: 'Conversion report' }] : [])]} />

      {tab === 'report' ? <Report /> : (
        <>
          <div className="mb-3 max-w-md"><SearchInput value={search} onChange={setSearch} placeholder="Search prospects" label="Search prospects" /></div>
          {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : tab === 'pipeline' ? (
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
              {OPEN_STAGES.map((stage) => {
                const items = query.data.data.filter((p) => p.stage === stage)
                const value = items.reduce((s, p) => s + (p.estimated_value_cents ?? 0), 0)
                return (
                  <Card key={stage} className="flex flex-col">
                    <CardHeader title={`${stages[stage]} (${items.length})`} description={value ? money(value) : undefined} />
                    {items.length === 0 ? <p className="px-3 py-4 text-center text-sm text-on-surface-variant">None</p> : (
                      <ul className="flex flex-col gap-2 p-2">
                        {items.map((p) => (
                          <li key={p.id}>
                            <button type="button" onClick={() => setOpenId(String(p.id))} className="w-full rounded-[3px] border border-outline-variant bg-surface p-2.5 text-left text-sm hover:border-primary">
                              <div className="font-medium">{p.name}</div>
                              <div className="text-xs text-on-surface-variant">{[p.case_type, p.source_label].filter(Boolean).join(' · ')}</div>
                              <div className="mt-1 flex flex-wrap items-center justify-between gap-1 text-xs">
                                <span className="tabular-nums">{p.estimated_value_cents ? money(p.estimated_value_cents) : ''}</span>
                                {p.next_step_on && <span className={p.follow_up_due ? 'font-semibold text-danger' : 'text-on-surface-variant'}>{p.next_step_on <= today() ? 'Follow up ' : ''}{date(p.next_step_on)}</span>}
                              </div>
                              {p.owner && <div className="text-xs text-on-surface-variant">{p.owner}</div>}
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}
                  </Card>
                )
              })}
            </div>
          ) : query.data.data.length === 0 ? <EmptyState title="Nothing closed in the last year" /> : (
            <Card>
              <Table caption="Closed prospects">
                <thead><tr><Th>Prospect</Th><Th>Outcome</Th><Th>Came from</Th><Th>Handled by</Th><Th align="right">Estimated fees</Th></tr></thead>
                <tbody>
                  {query.data.data.map((p) => (
                    <Tr key={p.id} onClick={() => setOpenId(String(p.id))}>
                      <Td><div className="font-medium">{p.name}</div><div className="text-xs text-on-surface-variant">{p.case_type}</div></Td>
                      <Td>{p.stage === 'won' ? <Badge tone="success">Engaged {date(p.engagement_signed_on)}</Badge> : <><Badge tone="danger">Lost</Badge><div className="text-xs text-on-surface-variant">{p.lost_reason}</div></>}</Td>
                      <Td className="text-on-surface-variant">{p.source_label}</Td>
                      <Td className="text-on-surface-variant">{p.owner ?? '—'}</Td>
                      <Td align="right">{p.estimated_value_cents ? money(p.estimated_value_cents) : '—'}</Td>
                    </Tr>
                  ))}
                </tbody>
              </Table>
            </Card>
          )}
        </>
      )}
      {editing && <ProspectForm prospect={editing === 'new' ? undefined : editing} sources={sources} onClose={() => setEditing(null)} />}
      {openId && <ProspectDetailDialog key={openId} id={Number(openId)} stages={stages} onClose={() => setOpenId('')} onEdit={(p) => { setOpenId(''); setEditing(p) }} />}
    </>
  )
}

function Report() {
  const [from, setFrom] = useUrlState('from', '')
  const [to, setTo] = useUrlState('to', '')
  const query = useProspectReport(from, to, true)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="text-on-surface-variant">Prospects added from</span>
        <Input type="date" aria-label="From" value={from || query.data?.from || ''} onChange={(e) => setFrom(e.target.value)} className="w-40" />
        <span className="text-on-surface-variant">to</span>
        <Input type="date" aria-label="To" value={to || query.data?.to || ''} onChange={(e) => setTo(e.target.value)} className="w-40" />
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} /> : (() => {
        const r = query.data
        return (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <StatCard label="Prospects" value={String(r.overall.total)} detail={`${r.overall.open} still open`} />
              <StatCard label="Win rate" value={r.overall.win_rate === null ? '—' : `${r.overall.win_rate}%`} detail={`${r.overall.won} engaged, ${r.overall.lost} lost`} />
              <StatCard label="Fees won (estimated)" value={money(r.overall.won_value_cents)} />
              <StatCard label="Days to engage" value={r.overall.avg_days_to_win === null ? '—' : String(r.overall.avg_days_to_win)} detail="on average" />
            </div>
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
              <SummaryTable title="By source" rows={r.by_source} />
              <SummaryTable title="By lawyer" rows={r.by_owner} />
            </div>
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
              <Card>
                <CardHeader title="Open pipeline" description="All open prospects, whenever added." />
                <Table caption="Open pipeline">
                  <thead><tr><Th>Stage</Th><Th align="right">Prospects</Th><Th align="right">Estimated fees</Th></tr></thead>
                  <tbody>{r.pipeline.map((s) => <tr key={s.stage}><Td>{s.label}</Td><Td align="right">{s.count}</Td><Td align="right">{money(s.value_cents)}</Td></tr>)}</tbody>
                </Table>
              </Card>
              <Card>
                <CardHeader title="Why prospects were lost" />
                {r.lost_reasons.length === 0 ? <EmptyState icon={<TrendingUp className="size-6" />} title="None lost in this period" /> : (
                  <Table caption="Lost reasons">
                    <thead><tr><Th>Reason</Th><Th align="right">Prospects</Th></tr></thead>
                    <tbody>{r.lost_reasons.map((l) => <tr key={l.reason}><Td className="first-letter:uppercase">{l.reason}</Td><Td align="right">{l.count}</Td></tr>)}</tbody>
                  </Table>
                )}
              </Card>
            </div>
          </>
        )
      })()}
    </div>
  )
}

function SummaryTable({ title, rows }: { title: string; rows: { label: string; total: number; won: number; lost: number; win_rate: number | null; won_value_cents: number }[] }) {
  return (
    <Card>
      <CardHeader title={title} />
      {rows.length === 0 ? <EmptyState title="No prospects in this period" /> : (
        <Table caption={title}>
          <thead><tr><Th>{title.replace('By ', '')}</Th><Th align="right">Prospects</Th><Th align="right">Engaged</Th><Th align="right">Lost</Th><Th align="right">Win rate</Th><Th align="right">Fees won</Th></tr></thead>
          <tbody>
            {rows.map((s) => (
              <tr key={s.label}>
                <Td>{s.label}</Td><Td align="right">{s.total}</Td><Td align="right">{s.won}</Td><Td align="right">{s.lost}</Td>
                <Td align="right">{s.win_rate === null ? '—' : `${s.win_rate}%`}</Td><Td align="right">{money(s.won_value_cents)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  )
}
