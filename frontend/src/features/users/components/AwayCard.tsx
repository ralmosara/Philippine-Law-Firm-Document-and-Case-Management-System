import { useState, type FormEvent } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Badge } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useStaffOptions } from '../api'

/**
 * Leave, a trip, a hospital stay: while away, the colleague covering also
 * gets your deadline reminders, missed-deadline alerts and new assignments.
 */
export function AwayCard() {
  const { user } = useCurrentSession()
  const staff = useStaffOptions()
  const [from, setFrom] = useState(user.away_from ?? '')
  const [until, setUntil] = useState(user.away_until ?? '')
  const [cover, setCover] = useState(user.cover_user_id ? String(user.cover_user_id) : '')
  const save = useApiMutation((input: { away_from: string | null; away_until: string | null; cover_user_id: number | null }) => put<{ user: { away_from: string | null } }>('/v1/auth/away', input), {
    invalidate: [['session'], ['users']],
    success: (r) => (r.user.away_from ? 'Saved. Your cover will be kept informed.' : 'Back in the office'),
    toastErrors: false,
  })
  const error = save.error ? ApiError.from(save.error) : null
  const scheduled = !!user.away_from

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ away_from: from || null, away_until: until || null, cover_user_id: cover ? Number(cover) : null })
  }
  const clear = () => {
    setFrom('')
    setUntil('')
    setCover('')
    save.mutate({ away_from: null, away_until: null, cover_user_id: null })
  }

  return (
    <Card>
      <CardHeader
        title="Out of office"
        description="While you are away, the colleague covering for you also gets your deadline reminders, missed-deadline alerts and new assignments. You still get them too."
        actions={user.is_away ? <Badge tone="warning">Away until {date(user.away_until)}</Badge> : scheduled ? <Badge>From {date(user.away_from)}</Badge> : undefined}
      />
      <form onSubmit={submit} className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-3">
        <Field label="Away from" error={error?.field('away_from')}>{(a) => <Input {...a} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
        <Field label="Back after" error={error?.field('away_until')}>{(a) => <Input {...a} type="date" value={until} min={from || undefined} onChange={(e) => setUntil(e.target.value)} />}</Field>
        <Field label="Covering for you" error={error?.field('cover_user_id')}>
          {(a) => (
            <Select {...a} value={cover} onChange={(e) => setCover(e.target.value)}>
              <option value="">No one</option>
              {staff.data?.filter((u) => u.id !== user.id).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </Select>
          )}
        </Field>
        <div className="sm:col-span-3"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
        <div className="flex gap-2 sm:col-span-3">
          <Button type="submit" loading={save.isPending && !!from}>Save</Button>
          {scheduled && <Button type="button" variant="text" onClick={clear}>I'm back / cancel</Button>}
        </div>
      </form>
    </Card>
  )
}
