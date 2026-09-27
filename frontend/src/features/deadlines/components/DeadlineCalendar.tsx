import { CalendarSync } from 'lucide-react'
import { ButtonLink } from '@/shared/ui/Button'
import { addDays, endOfMonth, endOfWeek, format, getDay, parse, startOfMonth, startOfWeek } from 'date-fns'
import { enUS } from 'date-fns/locale'
import { useMemo, useState } from 'react'
import { Calendar, dateFnsLocalizer, type EventProps, type View } from 'react-big-calendar'
import 'react-big-calendar/lib/css/react-big-calendar.css'
import type { Deadline } from '@/shared/api/types'
import { dateTime, isoDate, parseDate } from '@/shared/lib/format'
import { Dialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox } from '@/shared/ui/Form'
import { Card, PageHeader } from '@/shared/ui/Layout'
import { useDeadline, useDeadlinesInRange } from '../api'
import { DeadlineList } from './DeadlineList'

const localizer = dateFnsLocalizer({ format, parse, startOfWeek, getDay, locales: { 'en-US': enUS } })

interface CalendarEvent {
  id: number
  title: string
  start: Date
  end: Date
  allDay: boolean
  resource: Deadline
}

function visibleRange(date: Date, view: View): [string, string] {
  if (view === 'agenda') return [isoDate(date), isoDate(addDays(date, 30))]
  if (view === 'week') return [isoDate(startOfWeek(date)), isoDate(endOfWeek(date))]
  if (view === 'day') return [isoDate(date), isoDate(date)]
  return [isoDate(startOfWeek(startOfMonth(date))), isoDate(endOfWeek(endOfMonth(date)))]
}

function toEvent(d: Deadline): CalendarEvent {
  const start = parseDate(d.due_date)
  if (d.due_time) {
    const [h, m] = d.due_time.split(':').map(Number)
    start.setHours(h ?? 0, m ?? 0)
  }
  const end = d.due_time ? new Date(start.getTime() + 60 * 60_000) : start
  return { id: d.id, title: d.title, start, end, allDay: !d.due_time, resource: d }
}

/** Colours encode kind and state; the legend below repeats them in text. */
function eventColors(d: Deadline): { background: string; color: string } {
  if (d.status === 'completed' || d.status === 'cancelled') return { background: 'var(--color-surface-container-high)', color: 'var(--color-on-surface-variant)' }
  if (d.status === 'missed' || d.days_remaining < 0) return { background: 'var(--color-danger)', color: 'var(--color-surface)' }
  if (d.kind === 'hearing') return { background: 'var(--color-primary)', color: 'var(--color-on-primary)' }
  if (d.kind === 'filing') return { background: 'var(--color-warning-container)', color: 'var(--color-on-warning-container)' }
  return { background: 'var(--color-primary-container)', color: 'var(--color-on-primary-container)' }
}

function EventContent({ event }: EventProps<CalendarEvent>) {
  const d = event.resource
  return (
    <span className="block truncate" title={`${d.title} — ${d.matter?.reference ?? ''}`}>
      {d.status === 'completed' && '✓ '}
      {d.title}
      {d.matter && <span className="opacity-75"> · {d.matter.reference}</span>}
    </span>
  )
}

export function DeadlineCalendar() {
  const [date, setDate] = useState(new Date())
  const [view, setView] = useState<View>('month')
  const [mine, setMine] = useState(false)
  const [selected, setSelected] = useState<number | null>(null)
  const [from, to] = visibleRange(date, view)
  const query = useDeadlinesInRange(from, to, mine)
  const events = useMemo(() => (query.data ?? []).map(toEvent), [query.data])

  return (
    <>
      <PageHeader
        title="Calendar"
        description="Hearings, reglementary deadlines and tasks across all matters."
        actions={
          <>
            <Checkbox label="Only assigned to me" checked={mine} onChange={(e) => setMine(e.target.checked)} />
            <ButtonLink to="/profile" variant="text" icon={<CalendarSync className="size-4" />}>Sync to my calendar</ButtonLink>
          </>
        }
      />

      <Card className="lex-calendar p-4">
        {query.isError && <ErrorState error={query.error} onRetry={() => query.refetch()} />}
        <div className="relative">
          {query.isFetching && !query.data && <div className="absolute inset-0 z-10 bg-surface/60"><PageLoader /></div>}
          <Calendar<CalendarEvent>
            localizer={localizer}
            events={events}
            date={date}
            view={view}
            onNavigate={setDate}
            onView={setView}
            views={['month', 'week', 'agenda']}
            style={{ height: 720 }}
            popup
            components={{ event: EventContent }}
            onSelectEvent={(e) => setSelected(e.id)}
            eventPropGetter={(e) => {
              const { background, color } = eventColors(e.resource)
              return { style: { backgroundColor: background, color, border: 'none' } }
            }}
          />
        </div>
        <ul aria-label="Legend" className="mt-4 flex flex-wrap gap-4 text-xs text-on-surface-variant">
          {[
            ['var(--color-primary)', 'Hearing'],
            ['var(--color-warning-container)', 'Filing deadline'],
            ['var(--color-primary-container)', 'Task'],
            ['var(--color-danger)', 'Overdue / missed'],
            ['var(--color-surface-container-high)', 'Done or cancelled'],
          ].map(([color, label]) => (
            <li key={label} className="flex items-center gap-1.5">
              <span className="size-3 rounded-sm border border-outline-variant" style={{ backgroundColor: color }} aria-hidden="true" />
              {label}
            </li>
          ))}
        </ul>
      </Card>

      <DeadlineDialog id={selected} onClose={() => setSelected(null)} />
    </>
  )
}

function DeadlineDialog({ id, onClose }: { id: number | null; onClose: () => void }) {
  const deadline = useDeadline(id)
  const d = deadline.data

  return (
    <Dialog open={id !== null} onClose={onClose} title={d?.title ?? 'Deadline'} description={d?.matter && `${d.matter.reference} · ${d.matter.title}`} size="lg">
      {deadline.isPending ? (
        <PageLoader />
      ) : deadline.isError ? (
        <ErrorState error={deadline.error} />
      ) : d ? (
        <div className="-mx-6">
          <DeadlineList deadlines={[d]} showMatter />
          {d.notes && <p className="px-6 pb-4 text-sm whitespace-pre-line text-on-surface-variant">{d.notes}</p>}
          <div className="border-t border-outline-variant px-6 pt-4">
            <h3 className="mb-2 text-sm font-medium">Activity</h3>
            <ol className="flex flex-col gap-2 text-sm">
              {d.events?.map((e) => (
                <li key={e.id} className="flex justify-between gap-4">
                  <span>
                    {e.event_type.replace(/_/g, ' ')}
                    {typeof e.payload?.stage === 'string' && ` (${e.payload.stage})`}
                    {typeof e.payload?.reason === 'string' && ` — ${e.payload.reason}`}
                    {e.user && <span className="text-on-surface-variant"> by {e.user.name}</span>}
                  </span>
                  <span className="shrink-0 text-on-surface-variant">{dateTime(e.created_at)}</span>
                </li>
              ))}
            </ol>
          </div>
        </div>
      ) : null}
    </Dialog>
  )
}
