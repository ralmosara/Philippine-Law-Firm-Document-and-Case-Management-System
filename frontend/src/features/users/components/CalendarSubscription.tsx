import { useQuery } from '@tanstack/react-query'
import { CalendarSync, Copy } from 'lucide-react'
import { useState } from 'react'
import { del, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Input } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'

interface Feed { scope: 'mine' | 'firm'; show_details: boolean; created_at: string; last_accessed_at: string | null }

/** Subscribe to hearings and deadlines from Google Calendar, Outlook or a phone. */
export function CalendarSubscription() {
  const toast = useToast()
  const feed = useQuery({ queryKey: ['calendar-feed'], queryFn: async () => (await get<{ feed: Feed | null }>('/v1/calendar-feed')).feed })
  const create = useApiMutation((input: { scope: string; show_details: boolean }) => post<{ feed: Feed; url: string }>('/v1/calendar-feed', input), { invalidate: [['calendar-feed']] })
  const revoke = useApiMutation(() => del('/v1/calendar-feed'), { invalidate: [['calendar-feed']], success: 'Calendar link turned off' })
  const [scope, setScope] = useState<'mine' | 'firm'>('mine')
  const [details, setDetails] = useState(false)
  const [url, setUrl] = useState<string | null>(null)
  const [confirmRevoke, setConfirmRevoke] = useState(false)

  const generate = () => create.mutate({ scope, show_details: details }, { onSuccess: (r) => setUrl(r.url) })
  const copy = async () => {
    if (!url) return
    try {
      await navigator.clipboard.writeText(url)
      toast.success('Calendar link copied')
    } catch {
      toast.error('Copy failed; select the link and copy it manually.')
    }
  }

  return (
    <Card>
      <CardHeader
        title="Calendar subscription"
        description="See your hearings, filing deadlines and tasks in Google Calendar, Outlook or your phone. It updates about every hour."
        actions={feed.data ? <Badge tone="success">On</Badge> : <Badge>Off</Badge>}
      />
      <div className="flex flex-col gap-4 p-5 text-sm">
        {feed.isPending ? <PageLoader /> : (
          <>
            {feed.data && !url && (
              <p className="text-on-surface-variant">
                Active since {dateTime(feed.data.created_at)} · {feed.data.scope === 'firm' ? 'whole firm' : 'my matters'} · {feed.data.show_details ? 'with case details' : 'private titles'}
                {feed.data.last_accessed_at ? ` · last synced ${dateTime(feed.data.last_accessed_at)}` : ' · not synced yet'}
              </p>
            )}

            {url ? (
              <div className="flex flex-col gap-3 rounded-[3px] bg-surface-container p-4">
                <p className="font-medium">Your private calendar link. Copy it now; it won’t be shown again.</p>
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Input aria-label="Calendar link" readOnly value={url} onFocus={(e) => e.target.select()} className="flex-1 font-mono text-xs" />
                  <Button icon={<Copy className="size-4" />} onClick={copy}>Copy</Button>
                </div>
                <a href={url.replace(/^https?:/, 'webcal:')} className="font-medium text-primary hover:underline">Open in Apple Calendar or Outlook</a>
                <ul className="list-disc space-y-1 pl-5 text-on-surface-variant">
                  <li><strong>Google Calendar:</strong> Other calendars → + → From URL → paste the link.</li>
                  <li><strong>Outlook:</strong> Add calendar → Subscribe from web → paste the link.</li>
                  <li><strong>iPhone:</strong> Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar.</li>
                </ul>
                <p className="text-xs text-on-surface-variant">Anyone with this link can see these events. Don’t share it; turn it off here if it leaks.</p>
              </div>
            ) : (
              <>
                <fieldset className="flex flex-col gap-2">
                  <legend className="mb-1 font-medium">Include</legend>
                  <label className="flex items-center gap-2"><input type="radio" name="scope" checked={scope === 'mine'} onChange={() => setScope('mine')} className="accent-(--color-primary)" /> My matters and tasks assigned to me</label>
                  <label className="flex items-center gap-2"><input type="radio" name="scope" checked={scope === 'firm'} onChange={() => setScope('firm')} className="accent-(--color-primary)" /> Everything in the firm</label>
                </fieldset>
                <div>
                  <Checkbox label="Show case titles, courts and notes" checked={details} onChange={(e) => setDetails(e.target.checked)} />
                  <p className="mt-1 pl-6 text-xs text-on-surface-variant">
                    Off by default: events then show only “Hearing: M-2026-0001”. Calendar providers store what you sync, so client names leave the firm’s control when this is on.
                  </p>
                </div>
              </>
            )}

            <div className="flex flex-wrap gap-2">
              {!url && <Button icon={<CalendarSync className="size-4" />} loading={create.isPending} onClick={generate}>{feed.data ? 'Replace link' : 'Create calendar link'}</Button>}
              {url && <Button variant="text" onClick={() => setUrl(null)}>Done</Button>}
              {feed.data && !url && <Button variant="text" onClick={() => setConfirmRevoke(true)}>Turn off</Button>}
            </div>
            {feed.data && !url && <p className="text-xs text-on-surface-variant">Replacing the link stops the old one from working.</p>}
          </>
        )}
      </div>
      <ConfirmDialog
        open={confirmRevoke}
        onClose={() => setConfirmRevoke(false)}
        title="Turn off the calendar link?"
        description="Calendars subscribed to it stop updating and will show no new events."
        destructive
        confirmLabel="Turn off"
        loading={revoke.isPending}
        onConfirm={() => revoke.mutate(undefined, { onSuccess: () => setConfirmRevoke(false) })}
      />
    </Card>
  )
}
