import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarPlus, Check, Copy, Star } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, del, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { t } from '@/shared/lib/i18n'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { FormError, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import type { PortalMatterDetail } from '../api'

type Feedback = NonNullable<PortalMatterDetail['feedback']>

/** On a closed matter: how did the firm do? A rating out of 5 and an optional comment. */
export function FeedbackCard({ matterId, feedback }: { matterId: number; feedback: Feedback }) {
  const queryClient = useQueryClient()
  const [rating, setRating] = useState(feedback.rating ?? 0)
  const [comment, setComment] = useState(feedback.comment ?? '')
  const [editing, setEditing] = useState(feedback.responded_at === null)
  const send = useApiMutation((input: { rating: number; comment: string | null }) => post<Feedback>(`/portal/feedback/${feedback.id}`, input), {
    success: () => t('Thank you for your feedback.'),
    toastErrors: false,
  })
  const error = send.error ? ApiError.from(send.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    send.mutate({ rating, comment: comment.trim() || null }, {
      onSuccess: () => {
        setEditing(false)
        void queryClient.invalidateQueries({ queryKey: ['portal', 'matters', matterId] })
      },
    })
  }

  if (!editing) {
    return (
      <Card className="mb-6 p-5">
        <p className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-medium">{t('Your feedback')}</span>
          <Stars value={feedback.rating ?? rating} />
          {feedback.responded_at && <span className="text-on-surface-variant">{dateTime(feedback.responded_at)}</span>}
        </p>
        {(feedback.comment ?? comment) && <p className="mt-2 text-sm whitespace-pre-line">{feedback.comment ?? comment}</p>}
        {feedback.can_change && <Button variant="text" size="sm" className="mt-1 -ml-3" onClick={() => setEditing(true)}>{t('Change my answer')}</Button>}
      </Card>
    )
  }

  return (
    <Card className="mb-6 border-primary">
      <CardHeader title={t('How did we do?')} description={t('Your matter is closed. A rating, and a comment if you like: only the firm sees your answer.')} />
      <form onSubmit={submit} className="flex flex-col gap-4 p-5">
        <FormError message={error ? (error.field('rating') ?? error.message) : null} />
        <fieldset>
          <legend className="mb-2 text-sm font-medium">{t('Your rating')}</legend>
          <div className="flex gap-1">
            {[1, 2, 3, 4, 5].map((n) => (
              <label key={n} className="cursor-pointer">
                <input type="radio" name="rating" value={n} checked={rating === n} onChange={() => setRating(n)} className="peer sr-only" />
                <span className="flex size-11 items-center justify-center rounded-[3px] peer-focus-visible:outline-2 peer-focus-visible:outline-primary">
                  <Star className={`size-8 ${n <= rating ? 'fill-warning text-warning' : 'text-outline'}`} aria-hidden="true" />
                </span>
                <span className="sr-only">{t('{n} out of 5', { n })}</span>
              </label>
            ))}
          </div>
        </fieldset>
        <label className="flex flex-col gap-1 text-sm">
          <span className="font-medium">{t('Comment (optional)')}</span>
          <Textarea rows={3} maxLength={2000} value={comment} onChange={(e) => setComment(e.target.value)} placeholder={t('What went well, and what could we do better?')} />
        </label>
        <Button type="submit" loading={send.isPending} disabled={rating === 0} className="self-start">{t('Send feedback')}</Button>
      </form>
    </Card>
  )
}

function Stars({ value }: { value: number }) {
  return (
    <span className="inline-flex" role="img" aria-label={t('{n} out of 5', { n: value })}>
      {[1, 2, 3, 4, 5].map((n) => <Star key={n} className={`size-4 ${n <= value ? 'fill-warning text-warning' : 'text-outline'}`} aria-hidden="true" />)}
    </span>
  )
}

/** On the portal home: subscribe to your own hearings in Google Calendar, an iPhone or Outlook. */
export function CalendarCard() {
  const status = useQuery({ queryKey: ['portal', 'calendar'], queryFn: () => get<{ enabled: boolean; created_at: string | null; last_accessed_at: string | null }>('/portal/calendar') })
  const [url, setUrl] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const create = useApiMutation(() => post<{ url: string }>('/portal/calendar'), { invalidate: [['portal', 'calendar']] })
  const remove = useApiMutation(() => del('/portal/calendar'), { invalidate: [['portal', 'calendar']], success: () => t('Calendar link turned off') })

  const copy = async () => {
    if (!url) return
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
    } catch {
      // The link stays selectable on screen.
    }
  }

  return (
    <Card className="mt-6">
      <CardHeader title={t('Your hearings in your calendar')} description={t('Add your hearings to Google Calendar, an iPhone or Outlook. They update on their own when a hearing is set or moved.')} />
      <div className="flex flex-col gap-3 p-5 text-sm">
        {url ? (
          <>
            <p>{t('Copy this private link and add it to your calendar app as a subscription ("From URL" in Google Calendar, "Add Subscription Calendar" on an iPhone). Anyone with the link can see your hearings, so keep it to yourself.')}</p>
            <div className="flex flex-col gap-2 sm:flex-row">
              <input readOnly value={url} onFocus={(e) => e.currentTarget.select()} aria-label={t('Calendar link')} className="min-w-0 flex-1 rounded-[3px] border border-outline bg-surface-container px-3 py-2 font-mono text-xs" />
              <Button variant="tonal" icon={copied ? <Check className="size-4" /> : <Copy className="size-4" />} onClick={() => void copy()}>{copied ? t('Copied') : t('Copy')}</Button>
            </div>
          </>
        ) : status.data?.enabled ? (
          <p className="text-on-surface-variant">
            {t('Your calendar link is on.')}{' '}
            {status.data.last_accessed_at ? t('Last updated by your calendar app {date}.', { date: dateTime(status.data.last_accessed_at) }) : t('Your calendar app has not used it yet.')}
          </p>
        ) : null}
        <div className="flex flex-wrap gap-2">
          <Button variant={status.data?.enabled ? 'outlined' : 'filled'} icon={<CalendarPlus className="size-4" />} loading={create.isPending} onClick={() => create.mutate(undefined, { onSuccess: (r) => { setUrl(r.url); setCopied(false) } })}>
            {status.data?.enabled ? t('Get a new link') : t('Get my calendar link')}
          </Button>
          {status.data?.enabled && <Button variant="text" loading={remove.isPending} onClick={() => remove.mutate(undefined, { onSuccess: () => setUrl(null) })}>{t('Turn off')}</Button>}
        </div>
        {status.data?.enabled && !url && <p className="text-xs text-on-surface-variant">{t('A new link replaces the old one, which stops working.')}</p>}
      </div>
    </Card>
  )
}
