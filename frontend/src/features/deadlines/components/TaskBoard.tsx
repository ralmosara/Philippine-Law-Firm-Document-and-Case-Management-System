import clsx from 'clsx'
import { AlertTriangle, CalendarClock, Plus, Repeat } from 'lucide-react'
import { useState, type DragEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useMatterOptions } from '@/features/trust/api'
import { useStaffOptions } from '@/features/users/api'
import type { Deadline } from '@/shared/api/types'
import { date } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Avatar, Badge, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Select } from '@/shared/ui/Form'
import { PageHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import { useMoveTask, useTasks, type BoardColumn } from '../api'
import { DeadlineForm } from './DeadlineForm'

const COLUMNS: { value: BoardColumn; label: string }[] = [
  { value: 'todo', label: 'To do' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'review', label: 'For review' },
  { value: 'done', label: 'Done (last 14 days)' },
]

const REPEAT_LABELS = { weekly: 'Weekly', monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly' } as const

const PRIORITY = {
  urgent: { label: 'Urgent', tone: 'danger', weight: 0 },
  high: { label: 'High', tone: 'warning', weight: 1 },
  normal: { label: 'Normal', tone: 'neutral', weight: 2 },
  low: { label: 'Low', tone: 'neutral', weight: 3 },
} as const

const columnOf = (task: Deadline): BoardColumn => (task.status === 'completed' ? 'done' : task.progress)

/** Kanban board of matter tasks, firm-wide or for one matter. */
export function TaskBoard() {
  const abilities = useAbilities()
  const toast = useToast()
  const [assignee, setAssignee] = useUrlState('assignee', 'me')
  const [matter, setMatter] = useUrlState('matter')
  const params = { assignee: assignee === 'all' ? undefined : assignee, matter_id: matter ? Number(matter) : undefined }
  const tasks = useTasks(params)
  const move = useMoveTask(params)
  const staff = useStaffOptions()
  const matters = useMatterOptions()
  const [creating, setCreating] = useState(false)
  const [dropTarget, setDropTarget] = useState<BoardColumn | null>(null)

  const moveTo = (task: Deadline, column: BoardColumn) => {
    if (columnOf(task) === column) return
    move.mutate({ id: task.id, column }, { onError: (error) => toast.error(error.message) })
  }

  const onDrop = (column: BoardColumn) => (e: DragEvent) => {
    e.preventDefault()
    setDropTarget(null)
    const task = tasks.data?.find((t) => t.id === Number(e.dataTransfer.getData('text/plain')))
    if (task) moveTo(task, column)
  }

  return (
    <>
      <PageHeader
        title="Tasks"
        description="Drag cards between columns, or use each card’s “Move to” menu. Moving a card to Done completes the task."
        actions={
          abilities.work_matters && (
            <Button icon={<Plus className="size-4" />} disabled={!matter} title={matter ? undefined : 'Choose a matter first'} onClick={() => setCreating(true)}>
              New task
            </Button>
          )
        }
      />

      <div className="mb-4 flex flex-col gap-3 sm:flex-row">
        <Select aria-label="Whose tasks" value={assignee} onChange={(e) => setAssignee(e.target.value)} className="sm:w-56">
          <option value="me">My tasks</option>
          <option value="all">Everyone</option>
          <option value="unassigned">Unassigned</option>
          {staff.data?.map((u) => <option key={u.id} value={u.id}>{u.name}{u.is_away ? ` (away until ${u.away_until})` : ''}</option>)}
        </Select>
        <Select aria-label="Matter" value={matter} onChange={(e) => setMatter(e.target.value)} className="sm:w-80">
          <option value="">All matters</option>
          {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
        </Select>
      </div>

      {tasks.isPending ? (
        <PageLoader />
      ) : tasks.isError ? (
        <ErrorState error={tasks.error} onRetry={() => tasks.refetch()} />
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
          {COLUMNS.map((column) => {
            const cards = (tasks.data ?? [])
              .filter((t) => columnOf(t) === column.value)
              .sort((a, b) => PRIORITY[a.priority].weight - PRIORITY[b.priority].weight || a.due_date.localeCompare(b.due_date))
            return (
              <section
                key={column.value}
                aria-labelledby={`col-${column.value}`}
                onDragOver={(e) => { e.preventDefault(); setDropTarget(column.value) }}
                onDragLeave={() => setDropTarget(null)}
                onDrop={onDrop(column.value)}
                className={clsx('flex min-h-40 flex-col rounded-(--radius-card) bg-surface-container p-3 transition-colors', dropTarget === column.value && 'ring-2 ring-primary')}
              >
                <h2 id={`col-${column.value}`} className="mb-3 flex items-center justify-between px-1 text-sm font-semibold">
                  {column.label}
                  <span className="rounded-full bg-surface px-2 text-xs font-medium text-on-surface-variant">{cards.length}</span>
                </h2>
                <ul className="flex flex-col gap-2">
                  {cards.map((task) => <TaskCard key={task.id} task={task} onMove={(c) => moveTo(task, c)} canMove={abilities.work_matters} />)}
                </ul>
                {cards.length === 0 && <p className="px-1 py-6 text-center text-xs text-on-surface-variant">Nothing here</p>}
              </section>
            )
          })}
        </div>
      )}

      {creating && matter && <DeadlineForm matterId={Number(matter)} open onClose={() => setCreating(false)} initialKind="task" />}
    </>
  )
}

function TaskCard({ task, onMove, canMove }: { task: Deadline; onMove: (column: BoardColumn) => void; canMove: boolean }) {
  const overdue = task.status === 'missed' || (task.status === 'pending' && task.days_remaining < 0)
  const priority = PRIORITY[task.priority]

  return (
    <li
      draggable={canMove}
      onDragStart={(e) => { e.dataTransfer.setData('text/plain', String(task.id)); e.dataTransfer.effectAllowed = 'move' }}
      className={clsx('rounded-[3px] border border-outline-variant bg-surface p-3 shadow-sm', canMove && 'cursor-grab active:cursor-grabbing', task.status === 'completed' && 'opacity-70')}
    >
      <div className="flex items-start justify-between gap-2">
        <p className={clsx('text-sm font-medium', task.status === 'completed' && 'line-through')}>{task.title}</p>
        {task.priority !== 'normal' && <Badge tone={priority.tone}>{priority.label}</Badge>}
        {task.repeat && <span className="inline-flex items-center gap-1 text-xs text-on-surface-variant" title={`Repeats ${REPEAT_LABELS[task.repeat]}${task.repeat_until ? ` until ${task.repeat_until}` : ''}`}><Repeat className="size-3" aria-hidden />{REPEAT_LABELS[task.repeat]}</span>}
      </div>
      {task.matter && (
        <Link to={`/matters/${task.matter.id}`} className="mt-1 block truncate text-xs text-primary hover:underline">{task.matter.reference} · {task.matter.title}</Link>
      )}
      <div className="mt-2 flex items-center justify-between gap-2 text-xs text-on-surface-variant">
        <span className={clsx('inline-flex items-center gap-1', overdue && 'font-medium text-danger')}>
          {overdue ? <AlertTriangle className="size-3.5" aria-hidden="true" /> : <CalendarClock className="size-3.5" aria-hidden="true" />}
          {overdue ? 'Overdue · ' : 'Due '}{date(task.due_date)}
        </span>
        {task.assignee ? <span className="inline-flex items-center gap-1"><Avatar name={task.assignee.name} className="size-5 text-[9px]" /> {task.assignee.name.split(' ').slice(-1)[0]}</span> : <span>Unassigned</span>}
      </div>
      {canMove && (
        <Select aria-label={`Move “${task.title}” to`} value={columnOf(task)} onChange={(e) => onMove(e.target.value as BoardColumn)} className="mt-2 h-8 text-xs">
          {COLUMNS.map((c) => <option key={c.value} value={c.value}>Move to: {c.label.replace(' (last 14 days)', '')}</option>)}
        </Select>
      )}
    </li>
  )
}
