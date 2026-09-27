import { FileCheck2, FileDown } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities, useCurrentSession } from '@/features/auth/session'
import { useTrustAccounts } from '@/features/trust/api'
import { ApiError } from '@/shared/api/axios'
import type { Invoice, InvoicePayment, PaymentMethod } from '@/shared/api/types'
import { date, money, today, toCents } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { useAwaiting2307, useReceive2307, useRecordPayment, useVoidPayment } from '../api'

const METHOD_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  check: 'Check',
  bank_transfer: 'Bank transfer',
  e_wallet: 'GCash / Maya',
  card: 'Card',
  online: 'Online (PayMongo)',
  trust: 'Client trust',
  other: 'Other',
}

const FORM_2307_ACCEPT = '.pdf,.jpg,.jpeg,.png,.heic,.tif,.tiff'

/** Payments recorded against one invoice, with the tax withheld and each Form 2307. */
export function PaymentsCard({ invoice }: { invoice: Invoice }) {
  const abilities = useAbilities()
  const voidPayment = useVoidPayment()
  const [voiding, setVoiding] = useState<InvoicePayment | null>(null)
  const [receiving, setReceiving] = useState<InvoicePayment | null>(null)
  const payments = invoice.invoice_payments ?? []

  if (payments.length === 0) return null

  return (
    <Card className="mx-auto mt-6 max-w-4xl print:hidden">
      <CardHeader
        title="Payments"
        description={`${money(invoice.settled_cents)} settled of ${money(invoice.total_cents)}${invoice.withholding_cents > 0 ? `, including ${money(invoice.withholding_cents)} tax withheld` : ''}. Balance ${money(invoice.balance_cents)}.`}
      />
      <Table caption="Payments recorded" compact>
        <thead>
          <tr><Th>Received</Th><Th>Method · reference</Th><Th align="right">Cash</Th><Th align="right">Tax withheld</Th><Th>Form 2307</Th>{abilities.manage_finances && <Th><span className="sr-only">Actions</span></Th>}</tr>
        </thead>
        <tbody>
          {payments.map((p) => {
            const voided = p.voided_at !== null
            return (
              <tr key={p.id} className={voided ? 'text-on-surface-variant' : undefined}>
                <Td className="whitespace-nowrap">
                  <span className={voided ? 'line-through' : undefined}>{date(p.received_on)}</span>
                  {p.recorded_by && <div className="text-xs text-on-surface-variant">by {p.recorded_by}</div>}
                </Td>
                <Td>
                  {METHOD_LABELS[p.method]}
                  {p.reference && <div className="font-mono text-xs text-on-surface-variant">{p.reference}</div>}
                </Td>
                <Td align="right"><span className={voided ? 'line-through' : undefined}>{money(p.amount_cents)}</span></Td>
                <Td align="right"><span className={voided ? 'line-through' : undefined}>{p.withholding_cents > 0 ? money(p.withholding_cents) : '—'}</span></Td>
                <Td>
                  {voided ? (
                    <Badge>Voided</Badge>
                  ) : p.withholding_cents === 0 ? (
                    <span className="text-on-surface-variant">Not needed</span>
                  ) : p.form_2307_received_at ? (
                    <span className="inline-flex items-center gap-2">
                      <Badge tone="success">Received</Badge>
                      {p.form_2307_file_id && <a href={`/api/v1/files/${p.form_2307_file_id}/download`} download className="text-xs text-primary hover:underline">Download</a>}
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 whitespace-nowrap">
                      <Badge tone="warning">Awaiting</Badge>
                      {abilities.manage_finances && <Button size="sm" variant="text" onClick={() => setReceiving(p)}>Mark received</Button>}
                    </span>
                  )}
                  {voided && p.void_reason && <div className="mt-1 text-xs">{p.void_reason}</div>}
                </Td>
                {abilities.manage_finances && (
                  <Td align="right" className="whitespace-nowrap">
                    {!voided && !p.is_online && invoice.status !== 'void' && (
                      <Button size="sm" variant="text" onClick={() => setVoiding(p)}>Void</Button>
                    )}
                  </Td>
                )}
              </tr>
            )
          })}
        </tbody>
      </Table>

      <ConfirmDialog
        open={voiding !== null}
        onClose={() => setVoiding(null)}
        title="Void this payment?"
        description={
          voiding && (
            <>
              The payment of {money(voiding.credited_cents)} stays on record as voided and the balance goes back up.
              {voiding.is_trust && ' The amount is returned to the client’s trust account.'}
            </>
          )
        }
        reasonLabel="Reason"
        reasonRequired
        destructive
        confirmLabel="Void payment"
        loading={voidPayment.isPending}
        onConfirm={(reason) => voiding && voidPayment.mutate({ id: voiding.id, reason }, { onSuccess: () => setVoiding(null) })}
      />
      {receiving && <Receive2307Dialog payment={receiving} onClose={() => setReceiving(null)} />}
    </Card>
  )
}

/**
 * Record money received, full or partial. Corporate clients usually pay net
 * of creditable withholding tax on the professional fees (not on VAT or
 * expenses) and send BIR Form 2307 later.
 */
export function RecordPaymentDialog({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const { firm } = useCurrentSession()
  const record = useRecordPayment(invoice.id)
  const trust = useTrustAccounts({ client_id: invoice.client?.id })
  const trustAccounts = trust.data?.data.filter((a) => a.status === 'open') ?? []

  const [source, setSource] = useState('direct')
  const [receivedOn, setReceivedOn] = useState(today())
  const [method, setMethod] = useState<PaymentMethod>('bank_transfer')
  const [amount, setAmount] = useState((invoice.balance_cents / 100).toFixed(2))
  const [withholding, setWithholding] = useState('')
  const [reference, setReference] = useState('')
  const [form2307, setForm2307] = useState<File | null>(null)

  const rateBps = firm.default_withholding_bps ?? 1000
  const amountCents = toCents(amount || '0')
  const withholdingCents = source === 'direct' ? toCents(withholding || '0') : 0
  const credited = (Number.isNaN(amountCents) ? 0 : amountCents) + (Number.isNaN(withholdingCents) ? 0 : withholdingCents)
  const remaining = invoice.balance_cents - credited
  const errors = record.error instanceof ApiError ? record.error.errors : {}
  const selectedTrust = trustAccounts.find((a) => String(a.id) === source)

  /**
   * Settle the whole balance. Over the invoice the client withholds the
   * rate on all the fees; whatever of that was not withheld on earlier
   * installments is withheld now.
   */
  const applyWithholding = () => {
    const expected = Math.round((invoice.subtotal_cents * rateBps) / 10000)
    const tax = Math.max(0, Math.min(invoice.withholding_room_cents, invoice.balance_cents, expected - invoice.withholding_cents))
    setWithholding((tax / 100).toFixed(2))
    setAmount(((invoice.balance_cents - tax) / 100).toFixed(2))
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    record.mutate(
      {
        received_on: receivedOn,
        method: source === 'direct' ? method : 'other',
        amount_cents: amountCents,
        withholding_cents: withholdingCents,
        reference: reference.trim() || undefined,
        trust_account_id: source === 'direct' ? undefined : Number(source),
        form_2307: withholdingCents > 0 ? form2307 : null,
      },
      { onSuccess: onClose },
    )
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Record payment"
      description={`Balance due ${money(invoice.balance_cents)} of ${money(invoice.total_cents)}. Record part of it or all of it.`}
      footer={
        <>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button type="submit" form="record-payment" loading={record.isPending} disabled={credited <= 0 || remaining < 0}>Record payment</Button>
        </>
      }
    >
      <form id="record-payment" onSubmit={submit} className="flex flex-col gap-4">
        <Field label="Paid from">
          {(a) => (
            <Select {...a} value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="direct">Paid by the client (cash, check, transfer, e-wallet)</option>
              {trustAccounts.map((acc) => (
                <option key={acc.id} value={acc.id} disabled={acc.balance_cents <= 0}>
                  Client trust {acc.account_number}: balance {money(acc.balance_cents)}
                </option>
              ))}
            </Select>
          )}
        </Field>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Date received" error={errors.received_on?.[0]} required>
            {(a) => <Input {...a} type="date" value={receivedOn} max={today()} onChange={(e) => setReceivedOn(e.target.value)} required />}
          </Field>
          {source === 'direct' && (
            <Field label="Method">
              {(a) => (
                <Select {...a} value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod)}>
                  {(['bank_transfer', 'check', 'cash', 'e_wallet', 'card', 'other'] as const).map((m) => <option key={m} value={m}>{METHOD_LABELS[m]}</option>)}
                </Select>
              )}
            </Field>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Amount received (₱)" error={errors.amount_cents?.[0]} required>
            {(a) => <Input {...a} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} required />}
          </Field>
          {source === 'direct' && (
            <Field label="Tax withheld (₱)" hint={`Up to ${money(invoice.withholding_room_cents)}: fees only, never VAT or expenses`} error={errors.withholding_cents?.[0]}>
              {(a) => <Input {...a} inputMode="decimal" value={withholding} placeholder="0.00" onChange={(e) => setWithholding(e.target.value)} />}
            </Field>
          )}
        </div>

        {source === 'direct' && invoice.withholding_room_cents > 0 && (
          <div>
            <Button variant="tonal" size="sm" onClick={applyWithholding}>Full balance, less {rateBps / 100}% withholding</Button>
          </div>
        )}

        {source === 'direct' ? (
          <Field label="Reference" hint="OR number, check number or bank transaction ID" error={errors.reference?.[0]}>
            {(a) => <Input {...a} value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} />}
          </Field>
        ) : (
          <p className="rounded-lg bg-warning-container p-3 text-sm text-on-warning-container">
            {money(amountCents || 0)} will be disbursed from trust account {selectedTrust?.account_number} and recorded in its ledger. Confirm the client has authorised applying trust funds to fees.
          </p>
        )}

        {withholdingCents > 0 && (
          <Field label="BIR Form 2307 (optional)" hint="Attach it now if you have it; otherwise it appears on the Form 2307 list until it arrives." error={errors.form_2307?.[0]}>
            {(a) => <input {...a} type="file" accept={FORM_2307_ACCEPT} onChange={(e) => setForm2307(e.target.files?.[0] ?? null)} className="text-sm" />}
          </Field>
        )}

        <p className="rounded-lg bg-surface-container p-3 text-sm tabular-nums" aria-live="polite">
          Credits {money(credited)} · {remaining < 0 ? <span className="text-danger">{money(-remaining)} more than the balance</span> : remaining === 0 ? 'settles the invoice in full' : `leaves ${money(remaining)} due`}
        </p>
        <FormError message={record.error && Object.keys(errors).length === 0 ? record.error.message : null} />
      </form>
    </Dialog>
  )
}

function Receive2307Dialog({ payment, onClose }: { payment: InvoicePayment; onClose: () => void }) {
  const receive = useReceive2307()
  const [file, setFile] = useState<File | null>(null)

  return (
    <Dialog
      open
      onClose={onClose}
      title="Form 2307 received"
      description={`Certificate for ${money(payment.withholding_cents)} withheld on ${date(payment.received_on)}. The scan is filed with the matter's documents.`}
      footer={
        <>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button loading={receive.isPending} onClick={() => receive.mutate({ id: payment.id, file }, { onSuccess: onClose })}>Mark received</Button>
        </>
      }
    >
      <Field label="Scan of the form (optional)">
        {(a) => <input {...a} type="file" accept={FORM_2307_ACCEPT} onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" />}
      </Field>
    </Dialog>
  )
}

/** Tax withheld by clients whose Form 2307 has not arrived: the firm needs these to claim the credit. */
export function Awaiting2307Tab() {
  const query = useAwaiting2307()
  const [receiving, setReceiving] = useState<InvoicePayment | null>(null)

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const rows = query.data
  const total = rows.reduce((sum, p) => sum + p.withholding_cents, 0)

  return (
    <Card>
      <CardHeader
        title="Form 2307 to collect"
        description={rows.length ? `${rows.length} payment${rows.length === 1 ? '' : 's'} · ${money(total)} withheld without a certificate yet. Without the 2307 the firm cannot credit this tax.` : undefined}
      />
      {rows.length === 0 ? (
        <EmptyState icon={<FileCheck2 className="size-6" />} title="Nothing outstanding" description="Every tax withheld by clients has its Form 2307." />
      ) : (
        <Table caption="Payments awaiting Form 2307">
          <thead><tr><Th>Received</Th><Th>Client</Th><Th>Invoice</Th><Th>Reference</Th><Th align="right">Tax withheld</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {rows.map((p) => (
              <tr key={p.id}>
                <Td className="whitespace-nowrap">{date(p.received_on)}</Td>
                <Td>{p.invoice?.client ?? '—'}</Td>
                <Td><Link to={`/billing/invoices/${p.invoice_id}`} className="font-medium text-primary hover:underline">{p.invoice?.number}</Link></Td>
                <Td className="font-mono text-xs">{p.reference ?? '—'}</Td>
                <Td align="right">{money(p.withholding_cents)}</Td>
                <Td align="right"><Button size="sm" variant="text" onClick={() => setReceiving(p)}>Mark received</Button></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      <div className="border-t border-outline-variant p-4">
        <DownloadButton href="/api/v1/reports/collections?format=csv" size="sm" icon={<FileDown className="size-4" />}>Collections CSV (with tax withheld)</DownloadButton>
      </div>
      {receiving && <Receive2307Dialog payment={receiving} onClose={() => setReceiving(null)} />}
    </Card>
  )
}
