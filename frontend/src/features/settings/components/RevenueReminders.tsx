import { Send } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useSendAllStatements } from '@/features/statements/api'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useSaveFirmSettings, type FirmSettings } from '../api'

const DAYS = Array.from({ length: 28 }, (_, i) => i + 1)
const ordinal = (n: number) => `${n}${n % 10 === 1 && n !== 11 ? 'st' : n % 10 === 2 && n !== 12 ? 'nd' : n % 10 === 3 && n !== 13 ? 'rd' : 'th'}`

/**
 * Monthly statements of account to clients: off until the firm turns them on,
 * since they go to clients by email.
 */
export function StatementsCard({ firm }: { firm: FirmSettings }) {
  const save = useSaveFirmSettings()
  const sendAll = useSendAllStatements()
  const [confirming, setConfirming] = useState(false)
  const on = firm.statements_enabled

  return (
    <Card>
      <CardHeader title="Monthly statements of account" description="Each client who owes the firm or has funds in trust is emailed a statement once a month: open billing statements by age, payments received and trust activity. The email is in English or Filipino, as the client chose." actions={on ? <Badge tone="success">On</Badge> : <Badge>Off</Badge>} />
      <div className="flex flex-col gap-4 p-5 text-sm">
        <Field label="Send on the" hint="Of each month, in the morning. Clients without an email address are skipped.">
          {(a) => (
            <Select {...a} value={firm.statement_day} onChange={(e) => save.mutate({ statement_day: Number(e.target.value) })} className="sm:w-40">
              {DAYS.map((d) => <option key={d} value={d}>{ordinal(d)}</option>)}
            </Select>
          )}
        </Field>
        <div className="flex flex-wrap gap-2">
          <Button variant={on ? 'outlined' : 'filled'} loading={save.isPending} onClick={() => save.mutate({ statements_enabled: !on })}>{on ? 'Turn off' : 'Turn on'}</Button>
          <Button variant="tonal" icon={<Send className="size-4" />} onClick={() => setConfirming(true)}>Send this month’s now</Button>
        </div>
      </div>
      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        title="Send statements of account now?"
        description="Every client with an email who owes the firm or has funds in trust, and has not been sent one this month, is emailed their statement. Those sent now are not sent again this month."
        confirmLabel="Send"
        loading={sendAll.isPending}
        onConfirm={() => sendAll.mutate(undefined, { onSettled: () => setConfirming(false) })}
      />
    </Card>
  )
}

/** Reminders to log time, and a weekly hours summary to the managing partner. */
export function TimeRemindersCard({ firm }: { firm: FirmSettings }) {
  const save = useSaveFirmSettings()
  const on = firm.time_reminders_enabled
  const [hours, setHours] = useState(String(firm.daily_target_minutes / 60))
  const [error, setError] = useState<string | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const n = Number(hours)
    if (!Number.isFinite(n) || n < 0.5 || n > 12) {
      setError('Enter hours from 0.5 to 12.')
      return
    }
    setError(null)
    save.mutate({ daily_target_minutes: Math.round(n * 60) }, { onError: (err) => setError(ApiError.from(err).message) })
  }

  return (
    <Card>
      <CardHeader title="Time reminders" description="Each working morning, lawyers and paralegals who logged less than their daily target on the previous working day are reminded (holidays and weekends skipped). On Mondays, the managing partner gets last week’s hours against target for everyone." actions={on ? <Badge tone="success">On</Badge> : <Badge>Off</Badge>} />
      <form onSubmit={submit} className="flex flex-col gap-4 p-5 text-sm">
        <div className="flex flex-wrap items-end gap-2">
          <Field label="Daily target (hours)" hint="Change it for one person, or set 0 to leave them out, under Users.">
            {(a) => <Input {...a} inputMode="decimal" value={hours} onChange={(e) => setHours(e.target.value)} className="sm:w-32" />}
          </Field>
          <Button type="submit" variant="outlined" loading={save.isPending && save.variables?.daily_target_minutes !== undefined}>Save</Button>
        </div>
        {error && <FormError message={error} />}
        <div>
          <Button type="button" variant={on ? 'outlined' : 'filled'} loading={save.isPending && save.variables?.time_reminders_enabled !== undefined} onClick={() => save.mutate({ time_reminders_enabled: !on })}>{on ? 'Turn off' : 'Turn on'}</Button>
        </div>
      </form>
    </Card>
  )
}
