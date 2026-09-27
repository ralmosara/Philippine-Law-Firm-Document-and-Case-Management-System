import clsx from 'clsx'
import { AlertCircle, Inbox } from 'lucide-react'
import type { ReactNode } from 'react'
import { ApiError } from '@/shared/api/axios'

export function Spinner({ className }: { className?: string }) {
  return (
    <svg className={clsx('animate-spin text-current', className ?? 'size-5')} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  )
}

export function PageLoader({ label = 'Loading…' }: { label?: string }) {
  return (
    <div role="status" className="flex min-h-48 items-center justify-center gap-3 text-sm text-on-surface-variant">
      <Spinner />
      {label}
    </div>
  )
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = ApiError.from(error).message
  return (
    <div role="alert" className="flex flex-col items-center gap-3 rounded-2xl border border-danger-container bg-danger-container/40 p-8 text-center">
      <AlertCircle className="size-8 text-danger" aria-hidden="true" />
      <p className="text-sm text-on-danger-container">{message}</p>
      {onRetry && (
        <button type="button" onClick={onRetry} className="text-sm font-medium text-primary hover:underline">
          Try again
        </button>
      )}
    </div>
  )
}

export function EmptyState({ title, description, action, icon }: { title: string; description?: string; action?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
      <div className="mb-2 flex size-12 items-center justify-center rounded-full bg-surface-container text-on-surface-variant">
        {icon ?? <Inbox className="size-6" aria-hidden="true" />}
      </div>
      <p className="font-medium text-on-surface">{title}</p>
      {description && <p className="max-w-sm text-sm text-on-surface-variant">{description}</p>}
      {action && <div className="mt-3">{action}</div>}
    </div>
  )
}

type Tone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger'

const tones: Record<Tone, string> = {
  neutral: 'bg-surface-container-high text-on-surface-variant',
  primary: 'bg-primary-container text-on-primary-container',
  success: 'bg-success-container text-on-success-container',
  warning: 'bg-warning-container text-on-warning-container',
  danger: 'bg-danger-container text-on-danger-container',
}

export function Badge({ tone = 'neutral', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={clsx('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone], className)}>{children}</span>
}

export function ProgressBar({ value, label, tone = 'primary' }: { value: number; label: string; tone?: 'primary' | 'success' | 'warning' | 'danger' }) {
  const clamped = Math.max(0, Math.min(100, value))
  const fill = { primary: 'bg-primary', success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger' }[tone]
  return (
    <div role="progressbar" aria-label={label} aria-valuenow={Math.round(clamped)} aria-valuemin={0} aria-valuemax={100} className="h-2 w-full overflow-hidden rounded-full bg-surface-container-high">
      <div className={clsx('h-full rounded-full transition-[width] duration-500', fill)} style={{ width: `${clamped}%` }} />
    </div>
  )
}

export function Avatar({ name, className }: { name: string; className?: string }) {
  const letters = name
    .replace(/^Atty\.\s*/i, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('')
  return (
    <span aria-hidden="true" className={clsx('inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-container text-xs font-semibold text-on-primary-container', className)}>
      {letters}
    </span>
  )
}
