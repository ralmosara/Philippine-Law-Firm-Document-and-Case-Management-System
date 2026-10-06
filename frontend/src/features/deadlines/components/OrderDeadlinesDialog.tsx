import { useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Sparkles } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { useToast } from '@/shared/ui/Toast'

interface Suggested { title: string; kind: 'filing' | 'hearing' | 'task'; period_days: number | null; due_date: string | null; due_time: string | null; basis: string; quote: string }
interface Suggestions { document_type: string; document_date: string | null; date_received: string | null; date_received_quote: string | null; summary: string; deadlines: Suggested[] }
type Row = Suggested & { keep: boolean }

/**
 * The AI assistant reads a court order and proposes the deadlines it
 * creates. The lawyer checks the date received and each deadline (periods
 * are recounted here under Rule 22 if the date changes); nothing is
 * scheduled until they confirm.
 */
export function OrderDeadlinesDialog({ matterId, file, onClose }: { matterId: number; file: { id: number; name: string }; onClose: () => void }) {
  const { user } = useCurrentSession()
  const toast = useToast()
  const queryClient = useQueryClient()
  const read = useApiMutation(() => post<Suggestions>(`/v1/matters/${matterId}/files/${file.id}/deadline-suggestions`), { toastErrors: false })
  const [received, setReceived] = useState('')
  const [rows, setRows] = useState<Row[]>([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const { mutate } = read

  // Read once per dialog (each read is a paid call to the model).
  const started = useRef(false)
  useEffect(() => {
    if (started.current) return
    started.current = true
    mutate(undefined, { onSuccess: (s) => { setReceived(s.date_received ?? ''); setRows(s.deadlines.map((d) => ({ ...d, keep: true }))) } })
  }, [mutate])

  // A corrected date of receipt recounts every period-based deadline.
  const recount = async (value: string) => {
    setReceived(value)
    if (!value) return setRows((r) => r.map((d) => (d.period_days ? { ...d, due_date: null } : d)))
    const next = await Promise.all(rows.map(async (d) => (d.period_days
      ? { ...d, due_date: (await post<{ due_date: string }>('/v1/deadlines/compute', { trigger_date: value, period_days: d.period_days })).due_date }
      : d)))
    setRows(next)
  }

  const set = (i: number, patch: Partial<Row>) => setRows(rows.map((d, j) => (j === i ? { ...d, ...patch } : d)))
  const chosen = rows.filter((d) => d.keep)
  const missingDates = chosen.some((d) => !d.due_date)

  const schedule = async () => {
    setSaving(true)
    setError(null)
    try {
      for (const d of chosen) {
        await post(`/v1/matters/${matterId}/deadlines`, {
          kind: d.kind,
          title: d.title,
          due_date: d.due_date,
          due_time: d.due_time,
          notes: `${d.basis}${d.period_days ? ` (${d.period_days} days from receipt on ${received})` : ''}.\nFrom “${file.name}”: “${d.quote}”\nSuggested by the AI assistant; checked by ${user.name}.`,
        })
      }
      await queryClient.invalidateQueries({ queryKey: ['deadlines'] })
      await queryClient.invalidateQueries({ queryKey: ['matters'] })
      toast.success(chosen.length === 1 ? 'Scheduled 1 deadline' : `Scheduled ${chosen.length} deadlines`)
      onClose()
    } catch (err) {
      setError(ApiError.from(err).message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      size="xl"
      title={`Deadlines from ${file.name}`}
      description="Suggested by the AI assistant from the text of the file. Check the date of receipt and every deadline against the order before scheduling: the assistant can misread a scan or miss a period."
      footer={<>
        <Button variant="text" onClick={onClose}>Cancel</Button>
        {read.data && <Button loading={saving} disabled={chosen.length === 0 || missingDates} onClick={schedule}>{chosen.length === 1 ? 'Schedule 1 deadline' : `Schedule ${chosen.length} deadlines`}</Button>}
      </>}
    >
      {read.isPending || read.isIdle ? <PageLoader label="Reading the order…" /> : read.isError ? <ErrorState error={read.error} /> : (
        <div className="flex flex-col gap-4 text-sm">
          <div className="rounded-[3px] bg-surface-container p-3">
            <p className="flex items-center gap-2 font-medium"><Sparkles className="size-4" aria-hidden /> {read.data.document_type}{read.data.document_date && ` dated ${date(read.data.document_date)}`}</p>
            {read.data.summary && <p className="mt-1 text-on-surface-variant">{read.data.summary}</p>}
          </div>
          <Field
            label="Date received"
            hint={read.data.date_received_quote ? `From the document: “${read.data.date_received_quote}”` : 'Not shown in the document. Enter the date the firm or the client actually received it (Rule 13); periods are counted from it.'}
          >
            {(a) => <Input {...a} type="date" value={received} onChange={(e) => void recount(e.target.value)} className="sm:w-48" />}
          </Field>
          {!received && rows.some((d) => d.period_days) && <p className="flex items-center gap-2 text-danger"><AlertTriangle className="size-4" aria-hidden /> Enter the date received to count the periods.</p>}

          {rows.length === 0 ? <p className="text-on-surface-variant">No deadlines were found in this document.</p> : (
            <ul className="flex flex-col gap-3">
              {rows.map((d, i) => (
                <li key={i} className="rounded-[3px] border border-outline-variant p-3">
                  <div className="flex flex-wrap items-end gap-3">
                    <Checkbox label="Schedule" checked={d.keep} onChange={(e) => set(i, { keep: e.target.checked })} />
                    <Field label="Title" className="min-w-56 flex-1">{(a) => <Input {...a} value={d.title} onChange={(e) => set(i, { title: e.target.value })} />}</Field>
                    <Field label={d.period_days ? `Due (${d.period_days} days)` : 'Date'}>{(a) => <Input {...a} type="date" value={d.due_date ?? ''} onChange={(e) => set(i, { due_date: e.target.value || null })} />}</Field>
                    {d.kind === 'hearing' && <Field label="Time">{(a) => <Input {...a} type="time" value={d.due_time ?? ''} onChange={(e) => set(i, { due_time: e.target.value || null })} />}</Field>}
                  </div>
                  <p className="mt-2 text-xs text-on-surface-variant"><span className="font-medium">{d.basis}</span>{d.quote && <> · “{d.quote}”</>}</p>
                </li>
              ))}
            </ul>
          )}
          <FormError message={error} />
        </div>
      )}
    </Dialog>
  )
}
