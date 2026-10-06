import { useQueryClient } from '@tanstack/react-query'
import { Upload } from 'lucide-react'
import { useRef, useState, type FormEvent } from 'react'
import { ApiError, apiClient } from '@/shared/api/axios'
import { date, money, toCents, today } from '@/shared/lib/format'
import { t } from '@/shared/lib/i18n'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Field, FormError, Input, Select } from '@/shared/ui/Form'
import { useToast } from '@/shared/ui/Toast'
import type { PortalInvoice } from '../api'

const METHODS: [string, string][] = [['bank_transfer', 'Bank transfer or deposit'], ['e_wallet', 'GCash, Maya or other e-wallet'], ['check', 'Cheque'], ['cash', 'Cash'], ['other', 'Other']]

/** What became of the slips the client sent for this bill, and a button to send one. */
export function PaymentProofControls({ invoice }: { invoice: PortalInvoice }) {
  const [open, setOpen] = useState(false)
  const latest = invoice.payment_proofs?.[0]
  const unpaid = invoice.status !== 'paid'

  return (
    <div className="mt-1 flex flex-col items-start gap-1 text-xs sm:items-end">
      {latest?.status === 'pending' && <span className="text-on-surface-variant">{t('Proof of payment sent {date}; we are checking it.', { date: date(latest.created_at) })}</span>}
      {latest?.status === 'rejected' && <span className="text-danger">{t('We could not confirm your proof of payment: {reason}', { reason: latest.reject_reason ?? '' })}</span>}
      {unpaid && latest?.status !== 'pending' && (
        <Button size="sm" variant="text" icon={<Upload className="size-4" />} onClick={() => setOpen(true)}>{t('Paid by transfer? Send proof')}</Button>
      )}
      {open && <SendProofDialog invoice={invoice} onClose={() => setOpen(false)} />}
    </div>
  )
}

function SendProofDialog({ invoice, onClose }: { invoice: PortalInvoice; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const file = useRef<HTMLInputElement>(null)
  const [amount, setAmount] = useState((invoice.balance_cents / 100).toFixed(2))
  const [paidOn, setPaidOn] = useState(today())
  const [method, setMethod] = useState('bank_transfer')
  const [reference, setReference] = useState('')
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    const chosen = file.current?.files?.[0]
    if (!chosen) return setError(new ApiError(422, t('Choose the photo or PDF of your deposit slip or transfer.'), { file: [t('Choose the photo or PDF of your deposit slip or transfer.')] }))
    setSending(true)
    setError(null)
    try {
      const body = new FormData()
      body.append('file', chosen)
      body.append('amount_cents', String(toCents(amount)))
      body.append('paid_on', paidOn)
      body.append('method', method)
      if (reference.trim()) body.append('reference', reference.trim())
      await apiClient.post(`/portal/invoices/${invoice.id}/payment-proofs`, body)
      await queryClient.invalidateQueries({ queryKey: ['portal'] })
      toast.success(t('Sent. We will confirm your payment once we see it in our account.'))
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    } finally {
      setSending(false)
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={t('Send proof of payment for {number}', { number: invoice.number })}
      description={t('A photo or screenshot of your deposit slip or transfer confirmation. Balance: {amount}.', { amount: money(invoice.balance_cents) })}
      footer={<><Button variant="text" onClick={onClose}>{t('Cancel')}</Button><Button type="submit" form="payment-proof" loading={sending}>{t('Send')}</Button></>}
    >
      <form id="payment-proof" onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label={t('Deposit slip or screenshot')} required error={error?.field('file')}>
          {(a) => <input {...a} ref={file} type="file" accept="image/*,application/pdf" className="text-sm" />}
        </Field>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label={t('Amount paid (₱)')} required error={error?.field('amount_cents')}>{(a) => <Input {...a} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />}</Field>
          <Field label={t('Date paid')} required error={error?.field('paid_on')}>{(a) => <Input {...a} type="date" max={today()} value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />}</Field>
        </div>
        <Field label={t('How you paid')} error={error?.field('method')}>
          {(a) => <Select {...a} value={method} onChange={(e) => setMethod(e.target.value)}>{METHODS.map(([v, l]) => <option key={v} value={v}>{t(l)}</option>)}</Select>}
        </Field>
        <Field label={t('Reference number')} hint={t('From the slip or the confirmation message, if any.')} error={error?.field('reference')}>
          {(a) => <Input {...a} value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} />}
        </Field>
        <FormError message={error && !['file', 'amount_cents', 'paid_on', 'method', 'reference'].some((f) => error.field(f)) ? error.message : null} />
      </form>
    </Dialog>
  )
}
