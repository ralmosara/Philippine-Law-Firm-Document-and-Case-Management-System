import { CalendarClock, Info } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useStaffOptions } from '@/features/users/api'
import { ApiError } from '@/shared/api/axios'
import type { DeadlineKind } from '@/shared/api/types'
import { longDate, today } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Spinner } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Tabs } from '@/shared/ui/Layout'
import { useComputeDeadline, useCreateDeadline, useDeadlineRules } from '../api'
import { ClashWarning, useHearingClashes } from './HearingClashes'

type Mode = 'rule' | 'manual'

/**
 * Schedule a deadline on a matter. "Reglementary period" computes the due
 * date server-side from a Rules of Court rule and shows exactly how it was
 * derived before anything is saved.
 */
export function DeadlineForm({ matterId, open, onClose, initialKind }: { matterId: number; open: boolean; onClose: () => void; initialKind?: DeadlineKind }) {
  const [mode, setMode] = useState<Mode>(initialKind ? 'manual' : 'rule')
  const [ruleId, setRuleId] = useState<number | null>(null)
  const [triggerDate, setTriggerDate] = useState(today())
  const [kind, setKind] = useState<DeadlineKind>(initialKind ?? 'hearing')
  const [priority, setPriority] = useState('normal')
  const [repeat, setRepeat] = useState('')
  const [repeatUntil, setRepeatUntil] = useState('')
  const [title, setTitle] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [dueTime, setDueTime] = useState('')
  const [location, setLocation] = useState('')
  const [notifyClient, setNotifyClient] = useState(true)
  const [assignedTo, setAssignedTo] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<ApiError | null>(null)

  const rules = useDeadlineRules()
  const staff = useStaffOptions(open)
  const preview = useComputeDeadline(mode === 'rule' ? ruleId : null, triggerDate)
  const create = useCreateDeadline(matterId)
  const selectedRule = rules.data?.find((r) => r.id === ruleId)
  const clashes = useHearingClashes({ matter_id: matterId, date: dueDate, time: dueTime || undefined, assigned_to: assignedTo ? Number(assignedTo) : undefined }, open && mode === 'manual' && kind === 'hearing')

  const close = () => {
    setError(null)
    setRuleId(null)
    setTitle('')
    setDueDate('')
    setDueTime('')
    setLocation('')
    setNotes('')
    setRepeat('')
    setRepeatUntil('')
    onClose()
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    const assigned_to = assignedTo ? Number(assignedTo) : undefined
    try {
      if (mode === 'rule') {
        if (!ruleId) return
        await create.mutateAsync({ deadline_rule_id: ruleId, trigger_date: triggerDate, assigned_to, notes: notes || undefined })
      } else {
        await create.mutateAsync({ kind, title, due_date: dueDate, due_time: dueTime || null, location: location || null, assigned_to, notes: notes || undefined, ...(kind === 'task' ? { priority, repeat: repeat || null, repeat_until: repeat && repeatUntil ? repeatUntil : null } : {}), ...(kind === 'hearing' ? { notify_client: notifyClient } : {}) })
      }
      close()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open={open}
      onClose={close}
      title="Schedule a deadline"
      size="lg"
      footer={
        <>
          <Button variant="text" onClick={close}>Cancel</Button>
          <Button type="submit" form="deadline-form" loading={create.isPending} disabled={mode === 'rule' && !preview.data}>
            Schedule
          </Button>
        </>
      }
    >
      <Tabs
        label="Deadline type"
        value={mode}
        onChange={setMode}
        tabs={[
          { value: 'rule', label: 'Reglementary period' },
          { value: 'manual', label: 'Hearing, task or fixed date' },
        ]}
      />

      <form id="deadline-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : error?.field('deadline_rule_id')} /></div>

        {mode === 'rule' ? (
          <>
            <Field label="Rule" required className="sm:col-span-2" hint={selectedRule ? `${selectedRule.period_days} ${selectedRule.period_type === 'calendar' ? 'calendar' : 'working'} days from ${selectedRule.trigger_event.toLowerCase()} · ${selectedRule.legal_basis ?? ''}` : undefined}>
              {(a) => (
                <Select {...a} required value={ruleId ?? ''} onChange={(e) => setRuleId(e.target.value ? Number(e.target.value) : null)}>
                  <option value="">Select a rule…</option>
                  {rules.data?.map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name} ({r.period_days} days){r.is_system ? '' : ' — firm rule'}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
            <Field label={selectedRule ? `Date of ${selectedRule.trigger_event.toLowerCase()}` : 'Trigger date'} required error={error?.field('trigger_date')}>
              {(a) => <Input {...a} type="date" required value={triggerDate} onChange={(e) => setTriggerDate(e.target.value)} />}
            </Field>

            <div className="flex items-end sm:col-span-1">
              <div aria-live="polite" className="w-full rounded-[3px] bg-primary-container p-3 text-on-primary-container">
                {preview.isFetching ? (
                  <span className="flex items-center gap-2 text-sm"><Spinner className="size-4" /> Computing…</span>
                ) : preview.data ? (
                  <>
                    <p className="text-xs font-medium uppercase tracking-wide opacity-80">Due</p>
                    <p className="font-semibold">{longDate(preview.data.due_date)}</p>
                  </>
                ) : (
                  <span className="flex items-center gap-2 text-sm"><CalendarClock className="size-4" /> Choose a rule to compute</span>
                )}
              </div>
            </div>

            {preview.data && preview.data.adjustments.length > 0 && (
              <p className="flex gap-2 rounded-[3px] bg-surface-container p-3 text-xs text-on-surface-variant sm:col-span-2">
                <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                  The period ends on {longDate(preview.data.nominal_date)}, which is not a working day (
                  {preview.data.adjustments.map((a) => a.reason).join(', ')}). Under Rule 22, Sec. 1 the deadline moves to the next working day.
                </span>
              </p>
            )}
          </>
        ) : (
          <>
            <Field label="Type" required>
              {(a) => (
                <Select {...a} value={kind} onChange={(e) => setKind(e.target.value as DeadlineKind)}>
                  <option value="hearing">Hearing</option>
                  <option value="filing">Filing deadline</option>
                  <option value="task">Internal task</option>
                </Select>
              )}
            </Field>
            <Field label="Title" required error={error?.field('title')}>
              {(a) => <Input {...a} required value={title} onChange={(e) => setTitle(e.target.value)} placeholder={kind === 'hearing' ? 'Pre-trial conference' : 'File position paper'} />}
            </Field>
            <Field label="Date" required error={error?.field('due_date')}>
              {(a) => <Input {...a} type="date" required value={dueDate} onChange={(e) => setDueDate(e.target.value)} />}
            </Field>
            <Field label="Time" error={error?.field('due_time')}>
              {(a) => <Input {...a} type="time" value={dueTime} onChange={(e) => setDueTime(e.target.value)} />}
            </Field>
            {kind === 'task' && (
              <Field label="Priority">
                {(a) => (
                  <Select {...a} value={priority} onChange={(e) => setPriority(e.target.value)}>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                  </Select>
                )}
              </Field>
            )}
            {kind === 'task' && (
              <Field label="Repeats" hint="When it is done, the next one is created." error={error?.field('repeat')}>
                {(a) => (
                  <Select {...a} value={repeat} onChange={(e) => setRepeat(e.target.value)}>
                    <option value="">Does not repeat</option>
                    <option value="weekly">Every week</option>
                    <option value="monthly">Every month</option>
                    <option value="quarterly">Every 3 months</option>
                    <option value="yearly">Every year</option>
                  </Select>
                )}
              </Field>
            )}
            {kind === 'task' && repeat && (
              <Field label="Until" hint="Optional. Leave blank to repeat while the matter is open." error={error?.field('repeat_until')}>
                {(a) => <Input {...a} type="date" value={repeatUntil} min={dueDate || undefined} onChange={(e) => setRepeatUntil(e.target.value)} />}
              </Field>
            )}
            {kind === 'hearing' && (
              <Field label="Location" className="sm:col-span-2">
                {(a) => <Input {...a} value={location} onChange={(e) => setLocation(e.target.value)} placeholder="Sala of RTC Branch 58, Makati City Hall" />}
              </Field>
            )}
            {kind === 'hearing' && clashes.data && clashes.data.clashes.length > 0 && (
              <div className="sm:col-span-2"><ClashWarning clashes={clashes.data.clashes} intro="This lawyer already has a hearing at about that time:" /></div>
            )}
            {kind === 'hearing' && (
              <div className="sm:col-span-2">
                <Checkbox label="Tell the client (notice now, reminders a week before and the day before), if the firm sends client hearing reminders" checked={notifyClient} onChange={(e) => setNotifyClient(e.target.checked)} />
              </div>
            )}
          </>
        )}

        <Field label="Assign to" hint="Defaults to the responsible lawyer. Reminders go to both." error={error?.field('assigned_to')}>
          {(a) => (
            <Select {...a} value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)}>
              <option value="">Responsible lawyer</option>
              {staff.data?.map((u) => <option key={u.id} value={u.id}>{u.name}{u.is_away ? ` (away until ${u.away_until})` : ''}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Notes" className="sm:col-span-2">
          {(a) => <Textarea {...a} rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />}
        </Field>
      </form>
    </Dialog>
  )
}
