import { useState, type FormEvent } from 'react'
import { ApiError, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Invoice } from '@/shared/api/types'
import { money } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Field, FormError, Input, Select } from '@/shared/ui/Form'

const REASONS = [
  { value: 'duplicate', label: 'Paid twice (duplicate)' },
  { value: 'requested_by_customer', label: 'The client asked for it back' },
  { value: 'others', label: 'Other reason' },
  { value: 'fraudulent', label: 'Fraudulent payment' },
]

/** Refund, through PayMongo, an online payment that arrived after the invoice was settled or voided. */
export function RefundDialog({ invoiceId, payment, onClose }: { invoiceId: number; payment: { id: number; amount_cents: number }; onClose: () => void }) {
  const [reason, setReason] = useState('duplicate')
  const [notes, setNotes] = useState('')
  const refund = useApiMutation((input: { reason: string; notes: string | null }) => post<Invoice>(`/v1/online-payments/${payment.id}/refund`, input), {
    invalidate: [['invoices', invoiceId], ['invoices']],
    success: 'Refund sent to PayMongo',
    toastErrors: false,
  })
  const error = refund.error ? ApiError.from(refund.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    refund.mutate({ reason, notes: notes.trim() || null }, { onSuccess: onClose })
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Refund ${money(payment.amount_cents)}?`}
      description="PayMongo returns the full amount to the card or e-wallet it came from. This cannot be undone here."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="refund-form" loading={refund.isPending}>Refund</Button></>}
    >
      <form id="refund-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error ? (error.field('payment') ?? error.message) : null} />
        <Field label="Reason">
          {(a) => (
            <Select {...a} value={reason} onChange={(e) => setReason(e.target.value)}>
              {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Note for PayMongo" hint="Optional, up to 255 characters.">
          {(a) => <Input {...a} maxLength={255} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. Also paid by bank transfer on Oct 3" />}
        </Field>
      </form>
    </Dialog>
  )
}
