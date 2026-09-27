import { AlertTriangle, CheckCircle2, ShieldCheck } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import type { ConflictCheck, ConflictStatus } from '@/shared/api/types'
import { dateTime } from '@/shared/lib/format'
import { useUrlPage } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Input } from '@/shared/ui/Form'
import { Card, CardHeader, Pagination, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useConflictChecks, useResolveConflict, useRunConflictCheck } from '../api'

const statusBadge: Record<ConflictStatus, [string, 'success' | 'danger' | 'warning' | 'neutral']> = {
  clear: ['Clear', 'success'],
  flagged: ['Needs review', 'danger'],
  waived: ['Waived', 'warning'],
  declined: ['Declined', 'neutral'],
}

/**
 * Search clients and every party to the firm's matters before accepting an
 * engagement. Every search is kept, with its results, as the firm's record
 * of due diligence.
 */
export function ConflictCheckForm() {
  const [name, setName] = useState('')
  const run = useRunConflictCheck()
  const [page, setPage] = useUrlPage()
  const history = useConflictChecks(page)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (name.trim().length >= 2) run.mutate(name.trim())
  }

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader title="Run a conflict check" description="Searches current and former clients, adverse parties, counsel and witnesses. Word order and case do not matter." />
        <form onSubmit={submit} className="flex flex-col gap-3 p-5 sm:flex-row">
          <Input aria-label="Name to check" placeholder="Prospective client or adverse party, e.g. Juan Dela Cruz" value={name} onChange={(e) => setName(e.target.value)} className="flex-1" />
          <Button type="submit" loading={run.isPending} disabled={name.trim().length < 2} icon={<ShieldCheck className="size-4" />}>Check</Button>
        </form>
        {run.data && <ConflictResult check={run.data} />}
      </Card>

      <Card>
        <CardHeader title="History" description="Kept permanently as evidence of due diligence." />
        {history.isPending ? (
          <PageLoader />
        ) : history.isError ? (
          <ErrorState error={history.error} />
        ) : history.data.data.length === 0 ? (
          <EmptyState title="No checks yet" />
        ) : (
          <Table caption="Conflict check history">
            <thead><tr><Th>Searched for</Th><Th>Result</Th><Th align="right">Matches</Th><Th>By</Th><Th>When</Th></tr></thead>
            <tbody>
              {history.data.data.map((c) => {
                const [label, tone] = statusBadge[c.status]
                return (
                  <Tr key={c.id}>
                    <Td className="font-medium">{c.search_term}{c.resolution_notes && <p className="text-xs font-normal text-on-surface-variant">{c.resolution_notes}</p>}</Td>
                    <Td><Badge tone={tone}>{label}</Badge></Td>
                    <Td align="right">{c.match_count}</Td>
                    <Td className="text-on-surface-variant">{c.requester?.name}{c.resolver && <div className="text-xs">Resolved by {c.resolver.name}</div>}</Td>
                    <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(c.created_at)}</Td>
                  </Tr>
                )
              })}
            </tbody>
          </Table>
        )}
        <Pagination page={history.data} onPage={setPage} />
      </Card>
    </div>
  )
}

function ConflictResult({ check }: { check: ConflictCheck }) {
  const abilities = useAbilities()
  const resolve = useResolveConflict(check.id)
  const [deciding, setDeciding] = useState<'waived' | 'declined' | null>(null)

  if (check.match_count === 0) {
    return (
      <div role="status" className="mx-5 mb-5 flex items-center gap-3 rounded-xl bg-success-container p-4 text-on-success-container">
        <CheckCircle2 className="size-5 shrink-0" aria-hidden="true" />
        <p className="text-sm">No matches for <strong>{check.search_term}</strong>. This clear result has been recorded.</p>
      </div>
    )
  }

  return (
    <div className="border-t border-outline-variant">
      <div role="alert" className="mx-5 my-4 flex items-center gap-3 rounded-xl bg-danger-container p-4 text-on-danger-container">
        <AlertTriangle className="size-5 shrink-0" aria-hidden="true" />
        <p className="flex-1 text-sm"><strong>{check.match_count} potential conflict(s)</strong> for “{check.search_term}”. A lawyer must review before the firm accepts the engagement.</p>
        {abilities.practice_law && check.status === 'flagged' && (
          <span className="flex gap-2">
            <Button size="sm" variant="outlined" onClick={() => setDeciding('waived')}>Not a conflict / waived</Button>
            <Button size="sm" variant="danger" onClick={() => setDeciding('declined')}>Decline</Button>
          </span>
        )}
      </div>
      <Table caption="Matches">
        <thead><tr><Th>Name</Th><Th>Relationship</Th><Th>Matter</Th></tr></thead>
        <tbody>
          {check.matches.map((m) => (
            <tr key={`${m.source}-${m.id}`}>
              <Td className="font-medium">{m.name}</Td>
              <Td><Badge tone={m.is_adverse ? 'danger' : 'primary'}>{m.relationship}</Badge></Td>
              <Td>{m.matter_id ? <Link to={`/matters/${m.matter_id}`} className="text-primary hover:underline">{m.matter_reference} · {m.matter_title}</Link> : <span className="text-on-surface-variant">{m.matter_title}</span>}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
      <ConfirmDialog
        open={deciding !== null}
        onClose={() => setDeciding(null)}
        title={deciding === 'waived' ? 'Record as no conflict / waived' : 'Decline the engagement'}
        description={deciding === 'waived' ? 'Explain why the matches are not a conflict, or record the written informed consent obtained.' : 'Record why the firm must decline.'}
        reasonLabel="Resolution notes"
        reasonRequired
        destructive={deciding === 'declined'}
        confirmLabel="Record decision"
        loading={resolve.isPending}
        onConfirm={(notes) => deciding && resolve.mutate({ status: deciding, notes }, { onSuccess: () => setDeciding(null) })}
      />
    </div>
  )
}
