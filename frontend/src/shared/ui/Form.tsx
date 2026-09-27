import clsx from 'clsx'
import { Search } from 'lucide-react'
import { useId, type InputHTMLAttributes, type ReactNode, type Ref, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'

const control =
  'block w-full rounded-lg border border-outline bg-surface px-3 text-sm text-on-surface placeholder:text-on-surface-variant/60 transition-colors hover:border-on-surface-variant focus:border-primary focus:outline-2 focus:outline-offset-0 focus:outline-primary disabled:opacity-60 aria-[invalid=true]:border-danger aria-[invalid=true]:outline-danger'

interface FieldProps {
  label: string
  error?: string
  hint?: string
  required?: boolean
  className?: string
  children: (props: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string }) => ReactNode
}

/**
 * Label, control, hint and error, wired together for screen readers.
 * Children receive the id and aria attributes to spread on the control.
 */
export function Field({ label, error, hint, required, className, children }: FieldProps) {
  const id = useId()
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ') || undefined

  return (
    <div className={clsx('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-sm font-medium text-on-surface">
        {label}
        {required && <span className="text-danger" aria-hidden="true"> *</span>}
      </label>
      {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy })}
      {hint && !error && (
        <p id={`${id}-hint`} className="text-xs text-on-surface-variant">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-xs text-danger">
          {error}
        </p>
      )}
    </div>
  )
}

export function Input({ className, ref, ...props }: InputHTMLAttributes<HTMLInputElement> & { ref?: Ref<HTMLInputElement> }) {
  return <input ref={ref} className={clsx(control, 'h-10', className)} {...props} />
}

export function Textarea({ className, ref, ...props }: TextareaHTMLAttributes<HTMLTextAreaElement> & { ref?: Ref<HTMLTextAreaElement> }) {
  return <textarea ref={ref} className={clsx(control, 'min-h-24 py-2', className)} {...props} />
}

export function Select({ className, ref, children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { ref?: Ref<HTMLSelectElement> }) {
  return (
    <select ref={ref} className={clsx(control, 'h-10 pr-8', className)} {...props}>
      {children}
    </select>
  )
}

export function Checkbox({ label, className, ref, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; ref?: Ref<HTMLInputElement> }) {
  return (
    <label className={clsx('inline-flex cursor-pointer items-center gap-2 text-sm text-on-surface', className)}>
      <input ref={ref} type="checkbox" className="size-4 rounded accent-(--color-primary)" {...props} />
      {label}
    </label>
  )
}

export function SearchInput({ value, onChange, placeholder = 'Search', label = 'Search', className }: { value: string; onChange: (value: string) => void; placeholder?: string; label?: string; className?: string }) {
  return (
    <div className={clsx('relative', className)}>
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-on-surface-variant" aria-hidden="true" />
      <input
        type="search"
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="h-10 w-full rounded-full border border-transparent bg-surface-container-high pr-4 pl-9 text-sm text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:bg-surface focus:outline-none"
      />
    </div>
  )
}

/** Surface a server-side (non-field) error at the top of a form. */
export function FormError({ message }: { message?: string | null }) {
  if (!message) return null
  return (
    <p role="alert" className="rounded-lg bg-danger-container px-3 py-2 text-sm text-on-danger-container">
      {message}
    </p>
  )
}
