import { AlertTriangle, CloudOff, RefreshCw, X } from 'lucide-react'
import { useState } from 'react'
import { dateTime } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { useOfflineQueue } from './hooks'
import { dismissFailed } from './queue'

/**
 * In the top bar: "Offline" when there is no connection, and how many saves
 * are waiting to be sent. Opens the list, with anything the server refused
 * when it finally arrived (e.g. the hearing was recorded from the office).
 */
export function OfflineStatus({ userId }: { userId: number }) {
  const { online, waiting, refused, send } = useOfflineQueue(userId)
  const [open, setOpen] = useState(false)
  const [sending, setSending] = useState(false)

  if (online && !waiting.length && !refused.length) return null

  const label = !online ? (waiting.length ? `Offline · ${waiting.length} to send` : 'Offline') : refused.length ? `${refused.length} not saved` : `${waiting.length} to send`

  return (
    <>
      <button type="button" onClick={() => setOpen(true)} aria-label={label} title="Saved on this device, waiting for a connection"
        className={`flex h-8 items-center gap-1.5 rounded-[3px] px-2 text-xs font-semibold hover:bg-nav-hover ${refused.length ? 'text-[#ffb4a9]' : ''}`}>
        {refused.length ? <AlertTriangle className="size-4" aria-hidden /> : <CloudOff className="size-4" aria-hidden />}
        <span className="hidden sm:inline">{label}</span>
        <span className="sm:hidden">{waiting.length || refused.length || ''}</span>
      </button>
      {open && (
        <Dialog open onClose={() => setOpen(false)} title={online ? 'Waiting to send' : 'You are offline'}
          description={online ? 'These were saved on this device while there was no connection.' : 'You can keep recording time and hearing outcomes; they are sent when the connection returns. Screens you opened recently can still be viewed.'}
          footer={<><Button variant="text" onClick={() => setOpen(false)}>Close</Button>{online && waiting.length > 0 && <Button icon={<RefreshCw className="size-4" />} loading={sending} onClick={async () => { setSending(true); await send(); setSending(false) }}>Send now</Button>}</>}>
          <div className="flex flex-col gap-3 text-sm">
            {waiting.length === 0 && refused.length === 0 && <p className="text-on-surface-variant">Nothing is waiting.</p>}
            {waiting.length > 0 && (
              <ul className="divide-y divide-outline-variant rounded-[3px] border border-outline-variant">
                {waiting.map((w) => <li key={w.id} className="px-3 py-2">{w.label}<div className="text-xs text-on-surface-variant">Saved {dateTime(w.createdAt)}</div></li>)}
              </ul>
            )}
            {refused.length > 0 && (
              <>
                <p className="font-semibold text-danger">Not saved: the server refused these when they arrived.</p>
                <ul className="divide-y divide-outline-variant rounded-[3px] border border-danger/40">
                  {refused.map((w) => (
                    <li key={w.id} className="flex items-start gap-2 px-3 py-2">
                      <div className="min-w-0 flex-1">{w.label}<div className="text-xs text-danger">{w.error}</div><div className="text-xs text-on-surface-variant">Saved {dateTime(w.createdAt)}</div></div>
                      <IconButton label="Dismiss" onClick={() => void dismissFailed(w.id)}><X className="size-4" /></IconButton>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </Dialog>
      )}
    </>
  )
}
