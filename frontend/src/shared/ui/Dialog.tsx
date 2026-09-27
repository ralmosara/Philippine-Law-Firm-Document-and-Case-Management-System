import clsx from 'clsx'
import { X } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Button, IconButton } from './Button'
import { Field, Textarea } from './Form'

interface DialogProps {
  open: boolean
  onClose: () => void
  title: string
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'md' | 'lg' | 'xl'
}

/**
 * Modal built on the native <dialog> element: focus trapping, Escape to
 * close and the inert background come from the browser.
 */
export function Dialog({ open, onClose, title, description, children, footer, size = 'md' }: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={ref}
      onClose={onClose}
      onCancel={(e) => {
        e.preventDefault()
        onClose()
      }}
      aria-labelledby="dialog-title"
      className={clsx(
        'm-auto w-[calc(100%-2rem)] rounded-[3px] border border-nav bg-surface p-0 text-on-surface shadow-(--shadow-elevated)',
        { md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }[size],
      )}
    >
      {open && (
        <div className="flex max-h-[85vh] flex-col">
          <div className="flex items-center justify-between gap-4 bg-nav py-1.5 pr-1.5 pl-4 text-on-nav">
            <h2 id="dialog-title" className="text-base font-semibold">
              {title}
            </h2>
            <IconButton label="Close" onClick={onClose} className="!text-on-nav hover:!bg-nav-hover">
              <X className="size-4" />
            </IconButton>
          </div>
          {description && <div className="border-b border-outline-variant bg-surface-container px-4 py-2 text-sm text-on-surface-variant">{description}</div>}
          <div className="overflow-y-auto px-4 py-4">{children}</div>
          {footer && <div className="flex justify-end gap-1.5 border-t border-outline-variant bg-surface-container px-4 py-2.5">{footer}</div>}
        </div>
      )}
    </dialog>
  )
}

interface ConfirmProps {
  open: boolean
  onClose: () => void
  onConfirm: (reason: string) => void
  title: string
  description: ReactNode
  confirmLabel?: string
  destructive?: boolean
  loading?: boolean
  /** Ask for a reason (recorded in audit trails). */
  reasonLabel?: string
  reasonRequired?: boolean
}

export function ConfirmDialog({ open, onClose, onConfirm, title, description, confirmLabel = 'Confirm', destructive, loading, reasonLabel, reasonRequired }: ConfirmProps) {
  const [reason, setReason] = useState('')

  const submit = (e: FormEvent) => {
    e.preventDefault()
    onConfirm(reason.trim())
  }

  return (
    <Dialog open={open} onClose={onClose} title={title}>
      <form onSubmit={submit} className="flex flex-col gap-4">
        <div className="text-sm text-on-surface-variant">{description}</div>
        {reasonLabel && (
          <Field label={reasonLabel} required={reasonRequired}>
            {(a) => <Textarea {...a} value={reason} onChange={(e) => setReason(e.target.value)} required={reasonRequired} maxLength={2000} />}
          </Field>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="text" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" variant={destructive ? 'danger' : 'filled'} loading={loading} disabled={reasonRequired && !reason.trim()}>
            {confirmLabel}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}
