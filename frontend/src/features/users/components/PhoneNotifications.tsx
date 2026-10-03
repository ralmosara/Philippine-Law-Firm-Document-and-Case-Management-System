import { useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Smartphone } from 'lucide-react'
import { useEffect, useState } from 'react'
import { get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { Button } from '@/shared/ui/Button'
import { Badge } from '@/shared/ui/Feedback'
import { Checkbox } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'

interface PushStatus {
  enabled: boolean
  public_key: string | null
  devices: number
  push_details: boolean
}

/** Push keys arrive as base64url; the browser wants raw bytes. */
function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
  return bytes
}

const supported = typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
const isIos = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent)
const installed = typeof window !== 'undefined' && window.matchMedia?.('(display-mode: standalone)').matches

/**
 * Notifications on this phone or computer: hearing and deadline reminders,
 * assignments and approvals, even when Lex PH is closed. Off by default
 * on each device. What shows on a lock screen is only the kind of
 * notification unless the user asks for details.
 */
export function PhoneNotifications() {
  const queryClient = useQueryClient()
  const toast = useToast()
  const status = useQuery({ queryKey: ['push'], queryFn: () => get<PushStatus>('/v1/push') })
  const [thisDevice, setThisDevice] = useState<boolean | null>(null)
  const [busy, setBusy] = useState(false)
  const details = useApiMutation((push_details: boolean) => put<PushStatus>('/v1/push/preferences', { push_details }), { invalidate: [['push']], success: 'Saved' })
  const test = useApiMutation(() => post('/v1/push/test'), { success: 'Test notification sent' })

  useEffect(() => {
    if (!supported) return void setThisDevice(false)
    void navigator.serviceWorker.getRegistration().then(async (reg) => setThisDevice(Boolean(await reg?.pushManager.getSubscription())))
  }, [])

  const turnOn = async () => {
    if (!status.data?.public_key) return
    setBusy(true)
    try {
      if ((await Notification.requestPermission()) !== 'granted') {
        toast.error('Notifications are blocked for this site. Allow them in the browser settings, then try again.')
        return
      }
      const reg = await navigator.serviceWorker.ready
      const subscription = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(status.data.public_key) }))
      await post('/v1/push/subscriptions', subscription.toJSON())
      setThisDevice(true)
      await queryClient.invalidateQueries({ queryKey: ['push'] })
      toast.success('Notifications are on for this device')
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not turn on notifications')
    } finally {
      setBusy(false)
    }
  }

  const turnOff = async () => {
    setBusy(true)
    try {
      const reg = await navigator.serviceWorker.getRegistration()
      const subscription = await reg?.pushManager.getSubscription()
      if (subscription) {
        await post('/v1/push/subscriptions/remove', { endpoint: subscription.endpoint })
        await subscription.unsubscribe()
      }
      setThisDevice(false)
      await queryClient.invalidateQueries({ queryKey: ['push'] })
    } finally {
      setBusy(false)
    }
  }

  const s = status.data
  return (
    <Card>
      <CardHeader
        title="Notifications on your phone"
        description="Hearing and deadline reminders, new assignments and approvals, even when Lex PH is closed. Turn them on separately on each phone or computer."
      />
      <div className="flex flex-col gap-3 p-5 text-sm">
        {!s ? null : !s.enabled ? (
          <p className="text-on-surface-variant">Not set up on this server yet. Ask whoever runs Lex PH to add the notification keys (php artisan ops:vapid-keys).</p>
        ) : !supported ? (
          <p className="text-on-surface-variant">
            {isIos && !installed
              ? 'On an iPhone or iPad, first add Lex PH to the Home Screen (Share, then "Add to Home Screen"), open it from there, and turn notifications on.'
              : 'This browser cannot receive notifications.'}
          </p>
        ) : (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <Smartphone className="size-4 text-on-surface-variant" aria-hidden />
              <span>This device:</span>
              {thisDevice ? <Badge tone="success">On</Badge> : <Badge>Off</Badge>}
              <span className="text-on-surface-variant">· {s.devices} device{s.devices === 1 ? '' : 's'} in all</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {thisDevice
                ? <><Button variant="outlined" loading={busy} onClick={turnOff}>Turn off on this device</Button><Button variant="text" icon={<BellRing className="size-4" />} loading={test.isPending} onClick={() => test.mutate()}>Send a test</Button></>
                : <Button icon={<BellRing className="size-4" />} loading={busy} onClick={turnOn}>Turn on for this device</Button>}
            </div>
            <Checkbox
              label="Show client and case details on the lock screen"
              checked={s.push_details}
              disabled={details.isPending}
              onChange={(e) => details.mutate(e.target.checked)}
            />
            <p className="text-xs text-on-surface-variant">
              Off: a notification says only what kind it is ("A deadline was missed"), so nothing confidential shows on a locked phone. Tap it to see the details in Lex PH.
            </p>
          </>
        )}
      </div>
    </Card>
  )
}
