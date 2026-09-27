import clsx from 'clsx'
import { Pause, Play, Square, Timer } from 'lucide-react'
import { useEffect, useState } from 'react'
import { TimeTrackingForm } from './TimeTrackingForm'

const STORAGE_KEY = 'lexph.timer'

interface TimerState {
  /** Epoch ms when the current run started, or null when paused. */
  startedAt: number | null
  /** Milliseconds accumulated in earlier runs. */
  accumulated: number
}

function load(): TimerState {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return JSON.parse(raw) as TimerState
  } catch {
    // Storage unavailable (private mode) or corrupt: start fresh.
  }
  return { startedAt: null, accumulated: 0 }
}

function persist(state: TimerState) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch {
    // Non-critical: the timer still works for this page view.
  }
}

const elapsed = (s: TimerState, now: number) => s.accumulated + (s.startedAt ? now - s.startedAt : 0)

function clock(ms: number): string {
  const total = Math.floor(ms / 1000)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(Math.floor(total / 3600))}:${pad(Math.floor((total % 3600) / 60))}:${pad(total % 60)}`
}

/**
 * A stopwatch that follows the lawyer across pages and survives reloads
 * (state is timestamps in localStorage, not a ticking counter), then logs
 * the time against a matter in one step.
 */
export function GlobalTimeTracker() {
  const [state, setState] = useState<TimerState>(load)
  const [now, setNow] = useState(() => Date.now())
  const [logging, setLogging] = useState(false)
  const running = state.startedAt !== null
  const ms = elapsed(state, now)

  useEffect(() => {
    if (!running) return
    const interval = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(interval)
  }, [running])

  // Keep multiple tabs in sync.
  useEffect(() => {
    const onStorage = (e: StorageEvent) => e.key === STORAGE_KEY && setState(load())
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  const update = (next: TimerState) => {
    setState(next)
    setNow(Date.now())
    persist(next)
  }

  const toggle = () => {
    const t = Date.now()
    update(running ? { startedAt: null, accumulated: elapsed(state, t) } : { ...state, startedAt: t })
  }

  const stop = () => {
    update({ startedAt: null, accumulated: elapsed(state, Date.now()) })
    setLogging(true)
  }

  const reset = () => update({ startedAt: null, accumulated: 0 })
  // Round up to the nearest minute; at least one minute.
  const minutes = Math.max(1, Math.ceil(ms / 60_000))

  return (
    <>
      <div
        role="group"
        aria-label="Time tracker"
        className={clsx(
          'fixed right-4 bottom-4 z-30 flex items-center gap-1 rounded-[3px] border py-1 pr-1.5 pl-3 shadow-(--shadow-elevated) print:hidden sm:right-6 sm:bottom-6',
          running ? 'border-primary bg-primary-container text-on-primary-container' : 'border-outline-variant bg-surface',
        )}
      >
        <Timer className={clsx('size-4', running && 'animate-pulse')} aria-hidden="true" />
        <span className="mr-1 ml-1 font-mono text-sm tabular-nums" aria-live="off">{clock(ms)}</span>
        <button type="button" onClick={toggle} aria-label={running ? 'Pause timer' : 'Start timer'} className="flex size-9 items-center justify-center rounded-[3px] hover:bg-on-surface/8">
          {running ? <Pause className="size-4" /> : <Play className="size-4" />}
        </button>
        {ms > 0 && (
          <button type="button" onClick={stop} aria-label="Stop and log time" className="flex h-9 items-center gap-1 rounded-[3px] bg-primary px-3 text-sm font-medium text-on-primary hover:bg-primary-hover">
            <Square className="size-3.5" aria-hidden="true" /> Log
          </button>
        )}
      </div>

      <TimeTrackingForm open={logging} onClose={() => setLogging(false)} initialMinutes={minutes} onSaved={reset} />
    </>
  )
}
