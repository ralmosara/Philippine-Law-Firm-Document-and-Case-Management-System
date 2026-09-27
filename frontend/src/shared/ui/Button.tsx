import clsx from 'clsx'
import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Link, type LinkProps } from 'react-router-dom'
import { Spinner } from './Feedback'

type Variant = 'filled' | 'tonal' | 'outlined' | 'text' | 'danger'
type Size = 'sm' | 'md'

const base =
  'inline-flex items-center justify-center gap-1.5 rounded-[3px] font-semibold transition-colors select-none disabled:pointer-events-none disabled:opacity-50 whitespace-nowrap'

const variants: Record<Variant, string> = {
  filled: 'border border-primary-hover bg-primary text-on-primary hover:bg-primary-hover',
  tonal: 'border border-outline bg-surface-container-high text-on-surface hover:bg-outline-variant',
  outlined: 'border border-outline bg-surface text-on-surface hover:bg-surface-container',
  text: 'text-primary hover:bg-primary/8 hover:underline',
  danger: 'border border-danger bg-danger text-white hover:brightness-110 dark:text-on-danger-container',
}

const sizes: Record<Size, string> = {
  sm: 'h-7 px-2.5 text-xs',
  md: 'h-8 px-3.5 text-sm',
}

export function buttonClass(variant: Variant = 'filled', size: Size = 'md', className?: string): string {
  return clsx(base, variants[variant], sizes[size], className)
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
  icon?: ReactNode
}

export function Button({ variant = 'filled', size = 'md', loading = false, icon, className, children, disabled, type = 'button', ...props }: ButtonProps) {
  return (
    <button type={type} className={buttonClass(variant, size, className)} disabled={disabled || loading} aria-busy={loading || undefined} {...props}>
      {loading ? <Spinner className="size-4" /> : icon}
      {children}
    </button>
  )
}

export function ButtonLink({ variant = 'filled', size = 'md', className, icon, children, ...props }: LinkProps & { variant?: Variant; size?: Size; icon?: ReactNode }) {
  return (
    <Link className={buttonClass(variant, size, className)} {...props}>
      {icon}
      {children}
    </Link>
  )
}

export function IconButton({ label, className, children, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={clsx('inline-flex size-8 items-center justify-center rounded-[3px] text-on-surface-variant transition-colors hover:bg-on-surface/8 disabled:opacity-40', className)}
      {...props}
    >
      {children}
    </button>
  )
}

/** A download link styled as a button (same-origin file routes use the session cookie). */
export function DownloadButton({ href, variant = 'text', size = 'md', icon, children, className }: { href: string; variant?: Variant; size?: Size; icon?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <a href={href} download className={buttonClass(variant, size, className)}>
      {icon}
      {children}
    </a>
  )
}
