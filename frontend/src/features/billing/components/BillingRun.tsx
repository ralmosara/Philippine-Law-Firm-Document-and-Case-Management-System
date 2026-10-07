import { useQuery } from '@tanstack/react-query'
import { FilePlus2, Send } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, duration, money } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, Input } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

interface Candidate {
  matter: { id: number; reference: string; title: string; fee_arrangement: string | null }
  client: { id: number; name: string; has_email: boolean } | null
  lawyer: string | null
  time_entries: number
  minutes: number
  time_cents: number
  expense_entries: number
  expenses_cents: number
  oldest: string | null
  drafts: number
}
interface Draft { id: number; number: string; client: string | null; client_has_email: boolean; matter: string | null; total_cents: number }
interface Result { matter_id?: number; invoice_id?: number | null; number?: string | null; error: string | null; issued?: boolean; emailed?: boolean }

const endOfLastMonth = () => {
  const d = new Date()
  d.setDate(0)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Month-end: draft bills for every matter with unbilled work, then issue the drafts together. */
export function BillingRun() {
  const [through, setThrough] = useState(endOfLastMonth())
  const query = useQuery({ queryKey: ['billing-run', through], queryFn: () => get<{ matters: Candidate[]; drafts: Draft[] }>('/v1/billing-run', { through }) })
  const [picked, setPicked] = useState<Set<number>>(new Set())
  const [pickedDrafts, setPickedDrafts] = useState<Set<number>>(new Set())
  const [due, setDue] = useState('30')
  const [email, setEmail] = useState(true)
  const [results, setResults] = useState<{ kind: 'draft' | 'issue'; rows: Result[] } | null>(null)
  const invalidate = [['billing-run'], ['invoices'], ['time-entries'], ['matters'], ['dashboard']]
  const draft = useApiMutation((input: object) => post<{ results: Result[] }>('/v1/billing-run/draft', input), { invalidate })
  const issue = useApiMutation((input: object) => post<{ results: Result[] }>('/v1/billing-run/issue', input), { invalidate: [...invalidate, ['collections']] })

  const matters = query.data?.matters ?? []
  const drafts = query.data?.drafts ?? []
  const total = useMemo(() => matters.filter((m) => picked.has(m.matter.id)).reduce((s, m) => s + m.time_cents + m.expenses_cents, 0), [matters, picked])
  const toggle = (set: Set<number>, id: number, on: boolean) => { const next = new Set(set); if (on) next.add(id); else next.delete(id); return next }

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader title="1. Draft bills" description="Matters with billable time or expenses not yet on a bill. Each draft takes all of the matter's unbilled work up to the cut-off date; later work waits for the next run." />
        <div className="flex flex-wrap items-end gap-3 border-b border-outline-variant p-4">
          <Field label="Work up to">{(a) => <Input {...a} type="date" value={through} onChange={(e) => { setThrough(e.target.value); setPicked(new Set()) }} className="w-44" />}</Field>
          <Field label="Due in (days)">{(a) => <Input {...a} inputMode="numeric" value={due} onChange={(e) => setDue(e.target.value)} className="w-28" />}</Field>
          <Button icon={<FilePlus2 className="size-4" />} disabled={picked.size === 0} loading={draft.isPending}
            onClick={() => draft.mutate({ matter_ids: [...picked], due_in_days: Number(due) || 0, through }, { onSuccess: (r) => { setResults({ kind: 'draft', rows: r.results }); setPicked(new Set()) } })}>
            {picked.size ? `Draft ${picked.size} bill${picked.size === 1 ? '' : 's'} (${money(total)} before VAT)` : 'Draft bills'}
          </Button>
        </div>
        {matters.length === 0 ? <EmptyState title="Nothing unbilled" description="Every billable entry up to this date is on a bill." /> : (
          <Table caption="Matters with unbilled work">
            <thead><tr>
              <Th><input type="checkbox" className="size-4 accent-(--color-primary)" aria-label="Select all" checked={picked.size === matters.length} onChange={(e) => setPicked(e.target.checked ? new Set(matters.map((m) => m.matter.id)) : new Set())} /></Th>
              <Th>Client and matter</Th><Th>Lawyer</Th><Th align="right">Time</Th><Th align="right">Expenses</Th><Th>Since</Th>
            </tr></thead>
            <tbody>
              {matters.map((m) => (
                <tr key={m.matter.id}>
                  <Td><input type="checkbox" className="size-4 accent-(--color-primary)" aria-label={`Bill ${m.matter.reference}`} checked={picked.has(m.matter.id)} onChange={(e) => setPicked(toggle(picked, m.matter.id, e.target.checked))} /></Td>
                  <Td className="font-medium">{m.client?.name}<div className="text-xs font-normal"><Link to={`/matters/${m.matter.id}?tab=billing`} className="text-primary hover:underline">{m.matter.reference} · {m.matter.title}</Link></div>{m.drafts > 0 && <Badge tone="warning">{m.drafts} draft already</Badge>}</Td>
                  <Td className="text-on-surface-variant">{m.lawyer ?? '—'}</Td>
                  <Td align="right">{m.time_entries ? <>{money(m.time_cents)}<div className="text-xs text-on-surface-variant">{duration(m.minutes)} · {m.time_entries} entr{m.time_entries === 1 ? 'y' : 'ies'}</div></> : '—'}</Td>
                  <Td align="right">{m.expense_entries ? money(m.expenses_cents) : '—'}</Td>
                  <Td className="whitespace-nowrap text-on-surface-variant">{date(m.oldest)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {results && (
        <Card>
          <CardHeader title={results.kind === 'draft' ? 'Drafted' : 'Issued'} actions={<Button size="sm" variant="text" onClick={() => setResults(null)}>Dismiss</Button>} />
          <ul className="flex flex-col gap-1 p-4 text-sm">
            {results.rows.map((r, i) => (
              <li key={i} className={r.error ? 'text-danger' : ''}>
                {r.number ?? `Matter #${r.matter_id}`}: {r.error ?? (results.kind === 'draft' ? 'drafted' : r.emailed ? 'issued and emailed' : 'issued')}
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Card>
        <CardHeader title="2. Review and issue" description="Open each draft to check it (write lines down or give a discount if needed), then issue them together." />
        <div className="flex flex-wrap items-center gap-3 border-b border-outline-variant p-4">
          <Checkbox label="Email each client their billing statement" checked={email} onChange={(e) => setEmail(e.target.checked)} />
          <Button className="ml-auto" icon={<Send className="size-4" />} disabled={pickedDrafts.size === 0} loading={issue.isPending}
            onClick={() => issue.mutate({ invoice_ids: [...pickedDrafts], email }, { onSuccess: (r) => { setResults({ kind: 'issue', rows: r.results }); setPickedDrafts(new Set()) } })}>
            {pickedDrafts.size ? `Issue ${pickedDrafts.size}` : 'Issue'}
          </Button>
        </div>
        {drafts.length === 0 ? <EmptyState title="No drafts waiting" /> : (
          <Table caption="Draft bills" compact>
            <thead><tr>
              <Th><input type="checkbox" className="size-4 accent-(--color-primary)" aria-label="Select all drafts" checked={pickedDrafts.size === drafts.length} onChange={(e) => setPickedDrafts(e.target.checked ? new Set(drafts.map((d) => d.id)) : new Set())} /></Th>
              <Th>Draft</Th><Th>Client and matter</Th><Th align="right">Total</Th>
            </tr></thead>
            <tbody>
              {drafts.map((d) => (
                <tr key={d.id}>
                  <Td><input type="checkbox" className="size-4 accent-(--color-primary)" aria-label={`Issue ${d.number}`} checked={pickedDrafts.has(d.id)} onChange={(e) => setPickedDrafts(toggle(pickedDrafts, d.id, e.target.checked))} /></Td>
                  <Td><Link to={`/billing/invoices/${d.id}`} className="font-medium text-primary hover:underline">{d.number}</Link></Td>
                  <Td>{d.client}{!d.client_has_email && <span className="ml-1 text-xs text-on-surface-variant">(no email)</span>}<div className="text-xs text-on-surface-variant">{d.matter}</div></Td>
                  <Td align="right" className="font-medium">{money(d.total_cents)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  )
}
