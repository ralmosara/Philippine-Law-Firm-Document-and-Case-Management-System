import { useQuery } from '@tanstack/react-query'
import { MessageSquareHeart, Star } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, dateTime } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Td, Th } from '@/shared/ui/Layout'

interface Summary { requested: number; answered: number; response_rate: number | null; average: number | null; low: number }

interface FeedbackRow {
  id: number
  matter: { id: number; reference: string; title: string; case_type: string; lawyer: string | null }
  client: string | null
  requested_at: string
  rating: number | null
  comment: string | null
  responded_at: string | null
  is_low: boolean
  followed_up_at: string | null
  followed_up_by: string | null
  follow_up_note: string | null
}

interface Report {
  summary: Summary
  by_lawyer: (Summary & { name: string })[]
  by_practice_area: (Summary & { name: string })[]
  low_open: number
  data: FeedbackRow[]
}

function Stars({ value }: { value: number }) {
  return (
    <span className="inline-flex" role="img" aria-label={`${value} out of 5`}>
      {[1, 2, 3, 4, 5].map((n) => <Star key={n} className={`size-4 ${n <= value ? 'fill-warning text-warning' : 'text-outline'}`} aria-hidden="true" />)}
    </span>
  )
}

/** Client feedback on closed matters: how the firm is doing, by lawyer and practice area, and poor ratings to follow up. */
export function FeedbackPage() {
  const [months, setMonths] = useUrlState('months', '12')
  const [show, setShow] = useUrlState('show', 'all')
  const query = useQuery({ queryKey: ['feedback', months, show], queryFn: () => get<Report>('/v1/feedback', { months, show }) })
  const [following, setFollowing] = useState<FeedbackRow | null>(null)

  return (
    <>
      <PageHeader
        title="Client feedback"
        description="Asked of portal clients when their matter closes: a rating out of 5 and a comment. Ratings of 2 or less alert the responsible lawyer and the managing partners."
        actions={
          <div className="w-44">
            <Select aria-label="Period" value={months} onChange={(e) => setMonths(e.target.value)}>
              <option value="3">Last 3 months</option>
              <option value="12">Last 12 months</option>
              <option value="36">Last 3 years</option>
            </Select>
          </div>
        }
      />
      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (
        <>
          <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard label="Average rating" value={query.data.summary.average !== null ? `${query.data.summary.average} / 5` : '—'} />
            <StatCard label="Answered" value={`${query.data.summary.answered} of ${query.data.summary.requested}`} detail={query.data.summary.response_rate !== null ? `${query.data.summary.response_rate}% response rate` : undefined} />
            <StatCard label="Ratings of 2 or less" value={query.data.summary.low} tone={query.data.summary.low ? 'warning' : undefined} />
            <StatCard label="To follow up" value={query.data.low_open} tone={query.data.low_open ? 'danger' : undefined} />
          </div>

          <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Breakdown title="By lawyer" rows={query.data.by_lawyer} />
            <Breakdown title="By practice area" rows={query.data.by_practice_area} />
          </div>

          <Card>
            <CardHeader
              title="Answers"
              actions={
                <div className="w-48">
                  <Select aria-label="Show" value={show} onChange={(e) => setShow(e.target.value)}>
                    <option value="all">All</option>
                    <option value="low">Ratings of 2 or less</option>
                    <option value="unanswered">Not answered yet</option>
                  </Select>
                </div>
              }
            />
            {query.data.data.length === 0 ? (
              <EmptyState icon={<MessageSquareHeart className="size-6" />} title="No feedback here yet" description="Clients with portal access are asked when you close their matter." />
            ) : (
              <ul className="divide-y divide-outline-variant">
                {query.data.data.map((f) => (
                  <li key={f.id} className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start">
                    <div className="min-w-0 flex-1 text-sm">
                      <p className="flex flex-wrap items-center gap-2">
                        <Link to={`/matters/${f.matter.id}`} className="font-medium text-primary hover:underline">{f.matter.reference}</Link>
                        <span>{f.client}</span>
                        {f.rating !== null ? <Stars value={f.rating} /> : <Badge>Not answered</Badge>}
                        {f.is_low && !f.followed_up_at && <Badge tone="danger">Follow up</Badge>}
                      </p>
                      <p className="text-xs text-on-surface-variant">{f.matter.title} · {f.matter.case_type} · {f.matter.lawyer ?? 'No lawyer'} · {f.responded_at ? `answered ${dateTime(f.responded_at)}` : `asked ${date(f.requested_at)}`}</p>
                      {f.comment && <p className="mt-2 whitespace-pre-line">“{f.comment}”</p>}
                      {f.followed_up_at && <p className="mt-2 rounded-[3px] bg-surface-container p-2 text-xs">Followed up {dateTime(f.followed_up_at)} by {f.followed_up_by}: {f.follow_up_note}</p>}
                    </div>
                    {f.is_low && !f.followed_up_at && <Button size="sm" variant="tonal" onClick={() => setFollowing(f)}>Record follow-up</Button>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </>
      )}
      {following && <FollowUpDialog row={following} onClose={() => setFollowing(null)} />}
    </>
  )
}

function Breakdown({ title, rows }: { title: string; rows: (Summary & { name: string })[] }) {
  return (
    <Card>
      <CardHeader title={title} />
      {rows.length === 0 ? <EmptyState title="Nothing yet" /> : (
        <Table caption={title} compact>
          <thead><tr><Th>Name</Th><Th align="right">Average</Th><Th align="right">Answered</Th><Th align="right">2 or less</Th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.name}>
                <Td>{r.name}</Td>
                <Td align="right">{r.average !== null ? r.average.toFixed(1) : '—'}</Td>
                <Td align="right">{r.answered} of {r.requested}</Td>
                <Td align="right">{r.low || '—'}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  )
}

function FollowUpDialog({ row, onClose }: { row: FeedbackRow; onClose: () => void }) {
  const [note, setNote] = useState('')
  const save = useApiMutation((input: { note: string }) => post(`/v1/feedback/${row.id}/follow-up`, input), { invalidate: [['feedback']], success: 'Follow-up recorded', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null
  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ note }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} title={`Follow up with ${row.client ?? 'the client'}`} description={row.comment ? `“${row.comment}”` : `Rated ${row.rating} out of 5, with no comment.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="follow-up" loading={save.isPending}>Save</Button></>}>
      <form id="follow-up" onSubmit={submit}>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        <Field label="What was done" required error={error?.field('note')} hint="Once recorded, the client can no longer change their answer.">
          {(a) => <Textarea {...a} rows={3} required maxLength={2000} value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Called the client; agreed on weekly updates for the remaining matters." />}
        </Field>
      </form>
    </Dialog>
  )
}
