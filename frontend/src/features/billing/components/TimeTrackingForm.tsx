import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useMatterOptions } from '@/features/trust/api'
import { applyServerErrors } from '@/shared/api/hooks'
import type { TimeEntry } from '@/shared/api/types'
import { today } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { useSaveTimeEntry } from '../api'

const schema = z
  .object({
    matter_id: z.coerce.number<string>().int().positive('Choose a matter.'),
    work_date: z.iso.date('Enter a date.').refine((d) => d <= today(), 'Time cannot be logged in the future.'),
    hours: z.coerce.number<string>().int().min(0).max(24),
    minutes: z.coerce.number<string>().int().min(0).max(59),
    description: z.string().trim().min(3, 'Describe the work done.').max(2000),
    is_billable: z.boolean(),
  })
  .refine((v) => v.hours * 60 + v.minutes > 0, { message: 'Enter the time spent.', path: ['minutes'] })

type Values = z.input<typeof schema>
const FIELDS = ['matter_id', 'work_date', 'minutes', 'description', 'is_billable'] as const

interface Props {
  open: boolean
  onClose: () => void
  entry?: TimeEntry
  matterId?: number
  /** Pre-filled duration, e.g. from the running timer. */
  initialMinutes?: number
  onSaved?: () => void
}

/** Log (or correct) billable time. The rate is the lawyer's standard rate, set by the server. */
export function TimeTrackingForm({ open, onClose, entry, matterId, initialMinutes, onSaved }: Props) {
  const matters = useMatterOptions({ enabled: open })
  const save = useSaveTimeEntry(entry?.id)
  const startMinutes = entry?.minutes ?? initialMinutes ?? 0

  const { register, handleSubmit, formState, setError, reset } = useForm<Values, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
    values: {
      matter_id: String(entry?.matter?.id ?? matterId ?? ''),
      work_date: entry?.work_date ?? today(),
      hours: String(Math.floor(startMinutes / 60)),
      minutes: String(startMinutes % 60),
      description: entry?.description ?? '',
      is_billable: entry?.is_billable ?? true,
    },
  })

  const close = () => {
    reset()
    onClose()
  }

  const onSubmit = handleSubmit(async ({ hours, minutes, ...values }) => {
    try {
      await save.mutateAsync({ ...values, minutes: hours * 60 + minutes })
      onSaved?.()
      close()
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  return (
    <Dialog
      open={open}
      onClose={close}
      title={entry ? 'Edit time entry' : 'Log time'}
      footer={
        <>
          <Button variant="text" onClick={close}>Cancel</Button>
          <Button type="submit" form="time-form" loading={formState.isSubmitting}>{entry ? 'Save' : 'Log time'}</Button>
        </>
      }
    >
      <form id="time-form" onSubmit={onSubmit} noValidate className="grid grid-cols-2 gap-4">
        <div className="col-span-2"><FormError message={formState.errors.root?.message} /></div>
        {entry || matterId ? (
          <div className="col-span-2">
            <input type="hidden" {...register('matter_id')} />
            <p className="text-sm font-medium">Matter</p>
            <p className="text-sm text-on-surface-variant">
              {entry?.matter ? `${entry.matter.reference} · ${entry.matter.title}` : matters.data?.find((m) => m.id === matterId)?.title ?? '…'}
            </p>
          </div>
        ) : (
          <Field label="Matter" required error={formState.errors.matter_id?.message} className="col-span-2">
            {(a) => (
              <Select {...a} {...register('matter_id')}>
                <option value="">Select a matter…</option>
                {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
              </Select>
            )}
          </Field>
        )}
        <Field label="Date" required error={formState.errors.work_date?.message} className="col-span-2 sm:col-span-1">
          {(a) => <Input {...a} type="date" max={today()} {...register('work_date')} />}
        </Field>
        <div className="col-span-2 grid grid-cols-2 gap-2 sm:col-span-1">
          <Field label="Hours" error={formState.errors.hours?.message}>
            {(a) => <Input {...a} type="number" inputMode="numeric" min={0} max={24} {...register('hours')} />}
          </Field>
          <Field label="Minutes" error={formState.errors.minutes?.message}>
            {(a) => <Input {...a} type="number" inputMode="numeric" min={0} max={59} step={1} {...register('minutes')} />}
          </Field>
        </div>
        <Field label="Work done" required error={formState.errors.description?.message} className="col-span-2" hint="This description appears on the client's invoice.">
          {(a) => <Textarea {...a} rows={3} placeholder="Drafted and finalized Answer with Compulsory Counterclaim" {...register('description')} />}
        </Field>
        <Checkbox label="Billable" className="col-span-2" {...register('is_billable')} />
      </form>
    </Dialog>
  )
}
