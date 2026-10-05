import { useState, type FormEvent } from 'react'
import { ApiError } from '@/shared/api/axios'
import type { Invoice, InvoiceLine } from '@/shared/api/types'
import { date, dateTime, money, toCents } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { useInvoiceAction } from '../api'

const pesos = (cents: number) => (cents / 100).toFixed(2)

/** Write a draft line down (or back up to what was recorded). */
export function AdjustLineDialog({ invoice, line, onClose }: { invoice: Invoice; line: InvoiceLine; onClose: () => void }) {
  const { writeDown } = useInvoiceAction(invoice.id)
  const original = line.original_amount_cents ?? line.amount_cents
  const [amount, setAmount] = useState(pesos(line.amount_cents))
  const [reason, setReason] = useState(line.adjustment_reason ?? '')
  const [error, setError] = useState<string | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const cents = toCents(amount)
    if (!Number.isFinite(cents) || cents < 0 || cents > original) return setError(`Enter an amount from ₱0.00 to ${money(original)}.`)
    setError(null)
    writeDown.mutate({ line: line.id, amount_cents: cents, reason: reason.trim() || null }, { onSuccess: onClose, onError: (err) => setError(ApiError.from(err).message) })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Write down this line"
      description={`${line.description} · recorded at ${money(original)}. The client sees only the amount charged; the reason stays on file.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="adjust-line" loading={writeDown.isPending}>Save</Button></>}
    >
      <form id="adjust-line" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Amount to charge (₱)" required hint={line.original_amount_cents !== null ? `Enter ${pesos(original)} to restore the recorded amount.` : undefined}>
          {(a) => <Input {...a} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />}
        </Field>
        <Field label="Reason" hint="For example: research time not chargeable, junior learning time, agreed cap.">
          {(a) => <Input {...a} value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />}
        </Field>
        {error && <FormError message={error} />}
      </form>
    </Dialog>
  )
}

/** A discount off the professional fees of a draft, before VAT. */
export function DiscountDialog({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const { discount } = useInvoiceAction(invoice.id)
  const fees = (invoice.lines ?? []).filter((l) => l.kind !== 'expense').reduce((sum, l) => sum + l.amount_cents, 0)
  const [amount, setAmount] = useState(invoice.discount_cents ? pesos(invoice.discount_cents) : '')
  const [reason, setReason] = useState(invoice.discount_reason ?? '')
  const [error, setError] = useState<string | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const cents = amount.trim() === '' ? 0 : toCents(amount)
    if (!Number.isFinite(cents) || cents < 0 || cents > fees) return setError(`Enter up to the professional fees, ${money(fees)}.`)
    setError(null)
    discount.mutate({ discount_cents: cents, discount_reason: reason.trim() || null }, { onSuccess: onClose, onError: (err) => setError(ApiError.from(err).message) })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Discount"
      description={`Off the professional fees of ${money(fees)}, before VAT. Expenses are not discounted. Leave blank or 0 to remove it.`}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="discount" loading={discount.isPending}>Save</Button></>}
    >
      <form id="discount" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Discount (₱)">
          {(a) => <Input {...a} inputMode="decimal" value={amount} placeholder="0.00" onChange={(e) => setAmount(e.target.value)} />}
        </Field>
        <Field label="Shown on the bill as" hint="For example: courtesy discount, professional courtesy, volume discount.">
          {(a) => <Input {...a} value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />}
        </Field>
        {error && <FormError message={error} />}
      </form>
    </Dialog>
  )
}

/** Write off what is left of an issued bill. */
export function WriteOffDialog({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const { writeOff } = useInvoiceAction(invoice.id)
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (!reason.trim()) return setError('Say why the balance will not be collected.')
    setError(null)
    writeOff.mutate(reason.trim(), { onSuccess: onClose, onError: (err) => setError(ApiError.from(err).message) })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Write off ${money(invoice.balance_cents)}?`}
      description="The balance stops counting as owed: it leaves receivables, reminders and the client's statement of account. Payments already received stay. You can undo this if the client pays after all."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="write-off" variant="danger" loading={writeOff.isPending}>Write off</Button></>}
    >
      <form id="write-off" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Reason" required hint="For example: client insolvent, uncollectible after demand, settled for less.">
          {(a) => <Input {...a} value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />}
        </Field>
        {error && <FormError message={error} />}
      </form>
    </Dialog>
  )
}

/** Shown on a written-off bill, with the way back. */
export function WrittenOffNotice({ invoice, canUndo }: { invoice: Invoice; canUndo: boolean }) {
  const { undoWriteOff } = useInvoiceAction(invoice.id)
  const [confirming, setConfirming] = useState(false)

  return (
    <div className="mt-6 flex flex-col gap-2 rounded-[3px] bg-surface-container p-3 text-sm sm:flex-row sm:items-center print:hidden">
      <p className="flex-1">
        <span className="font-medium">{money(invoice.written_off_cents)} written off</span> {date(invoice.written_off_at)}
        {invoice.written_off_by && ` by ${invoice.written_off_by}`}: {invoice.write_off_reason}
      </p>
      {canUndo && <Button size="sm" variant="outlined" onClick={() => setConfirming(true)}>Undo write-off</Button>}
      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        title="Undo the write-off?"
        description={`${money(invoice.written_off_cents)} is owed again: it is back in receivables, reminders and the statement of account. Written off ${dateTime(invoice.written_off_at)}.`}
        confirmLabel="Undo write-off"
        loading={undoWriteOff.isPending}
        onConfirm={() => undoWriteOff.mutate(undefined, { onSettled: () => setConfirming(false) })}
      />
    </div>
  )
}
