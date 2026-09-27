import { CalendarCheck2, Check, Gavel, ListTodo, MoreVertical, FileClock } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { DeadlineStatusBadge } from '@/features/matters/components/StatusBadge'
import type { Deadline } from '@/shared/api/types'
import { date, relativeDays, today } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { EmptyState } from '@/shared/ui/Feedback'
import { Field, Input, Textarea } from '@/shared/ui/Form'
import { useCancelDeadline, useCompleteDeadline, useRescheduleDeadline } from '../api'

const kindIcon = { hearing: Gavel, filing: FileClock, task: ListTodo }

/** `compact` hides row actions and secondary lines, for narrow side panels. */
export function DeadlineList({ deadlines, showMatter = false, compact = false, emptyText = 'Nothing scheduled.' }: { deadlines: Deadline[]; showMatter?: boolean; compact?: boolean; emptyText?: string }) {
  if (deadlines.length === 0) {
    return <EmptyState icon={<CalendarCheck2 className="size-6" />} title={emptyText} />
  }

  return (
    <ul className="divide-y divide-outline-variant">
      {deadlines.map((d) => (
        <DeadlineRow key={d.id} deadline={d} showMatter={showMatter} compact={compact} />
      ))}
    </ul>
  )
}

function DeadlineRow({ deadline: d, showMatter, compact }: { deadline: Deadline; showMatter: boolean; compact: boolean }) {
  const abilities = useAbilities()
  const [menu, setMenu] = useState(false)
  const [dialog, setDialog] = useState<'complete' | 'reschedule' | 'cancel' | null>(null)
  const Icon = kindIcon[d.kind]
  const open = d.status === 'pending'
  const urgent = open && d.days_remaining <= 3

  return (
    <li className="flex items-start gap-4 px-5 py-4">
      <span className={`mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full ${urgent ? 'bg-danger-container text-on-danger-container' : 'bg-surface-container-high text-on-surface-variant'}`}>
        <Icon className="size-4" aria-hidden="true" />
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="font-medium">{d.title}</p>
          <DeadlineStatusBadge status={d.status} daysRemaining={d.days_remaining} />
        </div>
        <p className="mt-0.5 text-sm text-on-surface-variant">
          <span className={urgent ? 'font-medium text-danger' : undefined}>
            {date(d.due_date)}
            {d.due_time && ` · ${d.due_time}`}
            {open && ` · ${relativeDays(d.days_remaining)}`}
          </span>
          {d.location && ` · ${d.location}`}
        </p>
        {showMatter && d.matter && (
          <Link to={`/matters/${d.matter.id}`} className="mt-0.5 block truncate text-sm text-primary hover:underline">
            {d.matter.reference} · {d.matter.title}
          </Link>
        )}
        {!compact && <p className="mt-0.5 text-xs text-on-surface-variant">
          {d.assignee ? `Assigned to ${d.assignee.name}` : 'Unassigned'}
          {d.rule?.legal_basis && ` · ${d.rule.legal_basis}`}
        </p>}
      </div>

      {open && abilities.work_matters && !compact && (
        <div className="relative flex shrink-0 items-center gap-1">
          <Button variant="outlined" size="sm" icon={<Check className="size-4" />} onClick={() => setDialog('complete')}>
            Done
          </Button>
          {abilities.practice_law && (
            <>
              <IconButton label="More actions" aria-haspopup="menu" aria-expanded={menu} onClick={() => setMenu((m) => !m)}>
                <MoreVertical className="size-4" />
              </IconButton>
              {menu && (
                <>
                  <button type="button" aria-hidden="true" tabIndex={-1} className="fixed inset-0 z-10 cursor-default" onClick={() => setMenu(false)} />
                  <div role="menu" className="absolute top-10 right-0 z-20 w-44 rounded-[3px] border border-outline-variant bg-surface p-1 shadow-(--shadow-elevated)">
                    <button type="button" role="menuitem" className="w-full rounded-[3px] px-3 py-2 text-left text-sm hover:bg-surface-container" onClick={() => { setMenu(false); setDialog('reschedule') }}>
                      Reschedule…
                    </button>
                    <button type="button" role="menuitem" className="w-full rounded-[3px] px-3 py-2 text-left text-sm text-danger hover:bg-surface-container" onClick={() => { setMenu(false); setDialog('cancel') }}>
                      Cancel deadline…
                    </button>
                  </div>
                </>
              )}
            </>
          )}
        </div>
      )}

      <CompleteDialog deadline={d} open={dialog === 'complete'} onClose={() => setDialog(null)} />
      <RescheduleDialog deadline={d} open={dialog === 'reschedule'} onClose={() => setDialog(null)} />
      <CancelDialog deadline={d} open={dialog === 'cancel'} onClose={() => setDialog(null)} />
    </li>
  )
}

function CompleteDialog({ deadline, open, onClose }: { deadline: Deadline; open: boolean; onClose: () => void }) {
  const complete = useCompleteDeadline()
  return (
    <ConfirmDialog
      open={open}
      onClose={onClose}
      title="Mark as done?"
      description={<>Record that <strong>{deadline.title}</strong> was complied with. This is logged with your name and the time.</>}
      reasonLabel="Notes (e.g. how it was filed)"
      confirmLabel="Mark done"
      loading={complete.isPending}
      onConfirm={(notes) => complete.mutate({ id: deadline.id, notes: notes || undefined }, { onSuccess: onClose })}
    />
  )
}

function CancelDialog({ deadline, open, onClose }: { deadline: Deadline; open: boolean; onClose: () => void }) {
  const cancel = useCancelDeadline()
  return (
    <ConfirmDialog
      open={open}
      onClose={onClose}
      title="Cancel this deadline?"
      description={<>No more reminders will be sent for <strong>{deadline.title}</strong>.</>}
      reasonLabel="Reason"
      reasonRequired
      destructive
      confirmLabel="Cancel deadline"
      loading={cancel.isPending}
      onConfirm={(reason) => cancel.mutate({ id: deadline.id, reason }, { onSuccess: onClose })}
    />
  )
}

function RescheduleDialog({ deadline, open, onClose }: { deadline: Deadline; open: boolean; onClose: () => void }) {
  const reschedule = useRescheduleDeadline()
  const [dueDate, setDueDate] = useState(deadline.due_date)
  const [reason, setReason] = useState('')

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Reschedule"
      description={deadline.title}
      footer={
        <>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button
            loading={reschedule.isPending}
            disabled={!reason.trim() || !dueDate}
            onClick={() => reschedule.mutate({ id: deadline.id, due_date: dueDate, reason }, { onSuccess: onClose })}
          >
            Reschedule
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="New date" required>
          {(a) => <Input {...a} type="date" min={today()} value={dueDate} onChange={(e) => setDueDate(e.target.value)} />}
        </Field>
        <Field label="Reason" required hint="e.g. Motion for extension granted per Order dated…">
          {(a) => <Textarea {...a} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
      </div>
    </Dialog>
  )
}
