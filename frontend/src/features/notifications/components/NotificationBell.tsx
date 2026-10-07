import clsx from 'clsx'
import { AlarmClock, AlertTriangle, Bell, BadgeCheck, Clock, MessageSquareHeart, Building2, ClipboardCheck, Gauge, Landmark, Mail, HandCoins, TrendingUp, CalendarClock, FileSignature, Inbox, MessagesSquare, ShieldCheck, UserPlus, Wallet } from 'lucide-react'
import { useEffect, useState, type ComponentType } from 'react'
import { useNavigate } from 'react-router-dom'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { useLiveNotifications, useMarkAllRead, useMarkRead, useNotifications, type AppNotification } from '../api'

const ICONS: Record<string, ComponentType<{ className?: string }>> = {
  message: MessagesSquare,
  intake: Inbox,
  deadline: CalendarClock,
  deadline_missed: AlertTriangle,
  assigned: UserPlus,
  payment: Wallet,
  signature: FileSignature,
  signature_declined: FileSignature,
  privacy: ShieldCheck,
  tax: Landmark,
  corporate: Building2,
  email: Mail,
  disbursement: HandCoins,
  prospect: TrendingUp,
  document_uploaded: ClipboardCheck,
  budget: Gauge,
  prescription: AlarmClock,
  feedback: MessageSquareHeart,
  time: Clock,
  credentials: BadgeCheck,
  trust: Landmark,
  aml: ShieldCheck,
}

/** The notification center: a bell with the unread count and the latest 50. */
export function NotificationBell({ userId }: { userId: number }) {
  const [open, setOpen] = useState(false)
  const query = useNotifications()
  const markRead = useMarkRead()
  const markAll = useMarkAllRead()
  const navigate = useNavigate()
  useLiveNotifications(userId, query.data?.realtime)
  const unread = query.data?.unread ?? 0

  useEffect(() => {
    if (!open) return
    const close = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [open])

  const openItem = (n: AppNotification) => {
    if (!n.read_at) markRead.mutate(n.id)
    setOpen(false)
    navigate(n.url)
  }

  return (
    <div className="relative">
      <button
        type="button"
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        onClick={() => setOpen((o) => !o)}
        className="relative flex size-9 items-center justify-center rounded-[3px] text-current hover:bg-white/10"
      >
        <Bell className="size-5" aria-hidden="true" />
        {unread > 0 && (
          <span className="absolute top-0.5 right-0.5 min-w-5 rounded-[2px] bg-danger px-1 text-center text-[11px] leading-5 font-semibold text-white" aria-hidden="true">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <>
          <button type="button" aria-hidden="true" tabIndex={-1} className="fixed inset-0 z-40 cursor-default" onClick={() => setOpen(false)} />
          <div role="dialog" aria-label="Notifications" className="fixed inset-x-2 top-16 z-50 flex max-h-[70vh] flex-col rounded-[3px] border border-outline bg-surface text-on-surface shadow-(--shadow-elevated) sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-96">
            <div className="flex items-center justify-between border-b border-outline-variant px-4 py-3">
              <p className="font-medium">Notifications</p>
              {unread > 0 && <Button size="sm" variant="text" onClick={() => markAll.mutate()}>Mark all read</Button>}
            </div>
            <ul className="flex-1 overflow-y-auto">
              {!query.data?.data.length ? (
                <li className="px-4 py-10 text-center text-sm text-on-surface-variant">You're all caught up.</li>
              ) : query.data.data.map((n) => {
                const Icon = ICONS[n.kind] ?? Bell
                return (
                  <li key={n.id}>
                    <button type="button" onClick={() => openItem(n)} className={clsx('flex w-full gap-3 px-4 py-3 text-left hover:bg-surface-container', !n.read_at && 'bg-primary-container/30')}>
                      <Icon className={clsx('mt-0.5 size-5 shrink-0', n.kind === 'deadline_missed' || n.kind === 'signature_declined' ? 'text-danger' : 'text-primary')} aria-hidden />
                      <span className="min-w-0 flex-1">
                        <span className={clsx('block text-sm', !n.read_at && 'font-semibold')}>{n.title}</span>
                        {n.body && <span className="block truncate text-xs text-on-surface-variant">{n.body}</span>}
                        <span className="block text-xs text-on-surface-variant">{dateTime(n.created_at)}</span>
                      </span>
                      {!n.read_at && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-label="Unread" />}
                    </button>
                  </li>
                )
              })}
            </ul>
          </div>
        </>
      )}
    </div>
  )
}
