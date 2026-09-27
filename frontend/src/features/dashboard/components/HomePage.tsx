import { addDays } from 'date-fns'
import { useAbilities, useCurrentSession } from '@/features/auth/session'
import { useDeadlinesInRange } from '@/features/deadlines/api'
import { DeadlineList } from '@/features/deadlines/components/DeadlineList'
import { useMatters } from '@/features/matters/api'
import { isoDate, longDate, today } from '@/shared/lib/format'
import { ButtonLink } from '@/shared/ui/Button'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader, PageHeader, StatCard } from '@/shared/ui/Layout'
import { CEOAnalytics } from './CEOAnalytics'

function greeting(): string {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
}

export function HomePage() {
  const { user } = useCurrentSession()
  const abilities = useAbilities()
  const start = isoDate(addDays(new Date(), -30))
  const end = isoDate(addDays(new Date(), 14))
  const deadlines = useDeadlinesInRange(start, end, true)
  const myMatters = useMatters({ mine: true, active_only: true, per_page: 1 })

  const pending = (deadlines.data ?? []).filter((d) => d.status === 'pending')
  const overdue = pending.filter((d) => d.due_date < today())
  const thisWeek = pending.filter((d) => d.days_remaining >= 0 && d.days_remaining <= 7)

  return (
    <>
      <PageHeader title={`${greeting()}, ${user.name.replace(/^Atty\.\s*/, '').split(' ')[0]}`} description={longDate(today())} />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="My active matters" value={myMatters.data?.meta.total ?? '—'} />
        <StatCard label="Due in the next 7 days" value={thisWeek.length} tone={thisWeek.length > 0 ? 'warning' : undefined} />
        <StatCard label="Overdue" value={overdue.length} tone={overdue.length > 0 ? 'danger' : undefined} detail={overdue.length > 0 ? 'Act on these first' : 'All caught up'} />
      </div>

      <Card className="mb-8">
        <CardHeader title="My agenda" description="Overdue items and everything due in the next two weeks" actions={<ButtonLink to="/calendar" variant="text" size="sm">Open calendar</ButtonLink>} />
        {deadlines.isPending ? (
          <PageLoader />
        ) : deadlines.isError ? (
          <ErrorState error={deadlines.error} onRetry={() => deadlines.refetch()} />
        ) : (
          <DeadlineList deadlines={pending} showMatter emptyText="Nothing due in the next two weeks." />
        )}
      </Card>

      {abilities.manage_finances && <CEOAnalytics />}
    </>
  )
}
