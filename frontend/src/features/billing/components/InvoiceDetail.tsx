import { ArrowLeft, Copy, FileDown, Link2, Printer } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAbilities, useCurrentSession } from '@/features/auth/session'
import { InvoiceStatusBadge } from '@/features/matters/components/StatusBadge'
import { useTrustAccounts } from '@/features/trust/api'
import { date, dateTime, duration, money } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import { useInvoice, useInvoiceAction, usePaymentLink } from '../api'

export function InvoiceDetail() {
  const id = Number(useParams().id)
  const invoice = useInvoice(id)
  const { firm } = useCurrentSession()
  const abilities = useAbilities()
  const actions = useInvoiceAction(id)
  const [dialog, setDialog] = useState<'pay' | 'void' | 'link' | null>(null)

  if (invoice.isPending) return <PageLoader />
  if (invoice.isError) return <ErrorState error={invoice.error} onRetry={() => invoice.refetch()} />
  const inv = invoice.data

  return (
    <>
      <PageHeader
        back={<Link to="/billing?tab=invoices" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary print:hidden"><ArrowLeft className="size-4" /> Invoices</Link>}
        title={<span className="flex items-center gap-3">Invoice {inv.number} <InvoiceStatusBadge status={inv.status} overdue={inv.is_overdue} /></span>}
        actions={
          <div className="flex gap-2 print:hidden">
            <DownloadButton href={`/api/v1/invoices/${inv.id}/pdf`} icon={<FileDown className="size-4" />}>PDF</DownloadButton>
            <Button variant="text" icon={<Printer className="size-4" />} onClick={() => window.print()}>Print</Button>
            {abilities.manage_finances && inv.status === 'draft' && <Button onClick={() => actions.issue.mutate()} loading={actions.issue.isPending}>Issue to client</Button>}
            {abilities.manage_finances && inv.can_pay_online && <Button variant="tonal" icon={<Link2 className="size-4" />} onClick={() => setDialog('link')}>Payment link</Button>}
            {abilities.manage_finances && inv.status === 'issued' && <Button onClick={() => setDialog('pay')}>Record payment</Button>}
            {abilities.manage_finances && (inv.status === 'draft' || inv.status === 'issued') && <Button variant="outlined" onClick={() => setDialog('void')}>Void</Button>}
          </div>
        }
      />

      <Card className="mx-auto max-w-4xl p-8 sm:p-12 print:border-0 print:p-0">
        <div className="flex flex-col justify-between gap-6 sm:flex-row">
          <div>
            <p className="text-lg font-semibold">{firm.name}</p>
            {firm.address && <p className="text-sm text-on-surface-variant">{firm.address}</p>}
            {firm.tin && <p className="text-sm text-on-surface-variant">TIN {firm.tin}{firm.vat_registered ? ' · VAT-registered' : ''}</p>}
          </div>
          <div className="text-sm sm:text-right">
            <p className="text-2xl font-semibold tracking-tight">BILLING STATEMENT</p>
            <p className="text-on-surface-variant">No. {inv.number}</p>
            <p className="text-on-surface-variant">Issued {date(inv.issued_at)} · Due {date(inv.due_at)}</p>
          </div>
        </div>

        <div className="mt-8 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
          <div>
            <p className="text-xs font-medium tracking-wide text-on-surface-variant uppercase">Bill to</p>
            <p className="font-medium">{inv.client?.name}</p>
          </div>
          <div>
            <p className="text-xs font-medium tracking-wide text-on-surface-variant uppercase">Matter</p>
            <p className="font-medium">{inv.matter?.title}</p>
            <p className="text-on-surface-variant">{inv.matter?.reference}</p>
          </div>
        </div>

        <div className="mt-8">
          <Table caption="Invoice lines">
            <thead>
              <tr><Th>Date</Th><Th>Description</Th><Th align="right">Time</Th><Th align="right">Rate</Th><Th align="right">Amount</Th></tr>
            </thead>
            {[
              { title: 'Professional fees', lines: inv.lines?.filter((l) => l.kind !== 'expense') ?? [] },
              { title: 'Reimbursable expenses (at cost, not subject to VAT)', lines: inv.lines?.filter((l) => l.kind === 'expense') ?? [] },
            ].filter((group) => group.lines.length > 0).map((group) => (
              <tbody key={group.title}>
                <tr><Td colSpan={5} className="bg-surface-container text-xs font-semibold tracking-wide text-on-surface-variant uppercase">{group.title}</Td></tr>
                {group.lines.map((line) => (
                  <tr key={line.id}>
                    <Td className="whitespace-nowrap">{date(line.work_date)}</Td>
                    <Td>{line.description}</Td>
                    <Td align="right">{line.minutes ? duration(line.minutes) : '—'}</Td>
                    <Td align="right">{line.rate_cents ? `${money(line.rate_cents)}/hr` : '—'}</Td>
                    <Td align="right">{money(line.amount_cents)}</Td>
                  </tr>
                ))}
              </tbody>
            ))}
          </Table>
        </div>

        <dl className="mt-6 ml-auto flex w-full max-w-xs flex-col gap-2 text-sm">
          <div className="flex justify-between"><dt className="text-on-surface-variant">Professional fees</dt><dd className="tabular-nums">{money(inv.subtotal_cents)}</dd></div>
          <div className="flex justify-between"><dt className="text-on-surface-variant">VAT (12%)</dt><dd className="tabular-nums">{money(inv.vat_cents)}</dd></div>
          {inv.expenses_cents > 0 && <div className="flex justify-between"><dt className="text-on-surface-variant">Reimbursable expenses</dt><dd className="tabular-nums">{money(inv.expenses_cents)}</dd></div>}
          <div className="flex justify-between border-t border-outline-variant pt-2 text-base font-semibold"><dt>Total due</dt><dd className="tabular-nums">{money(inv.total_cents)}</dd></div>
        </dl>

        {inv.status === 'paid' && (
          <p className="mt-6 rounded-lg bg-success-container p-3 text-sm text-on-success-container">
            Paid {date(inv.paid_at)}{inv.payment_reference && ` · Ref. ${inv.payment_reference}`}
          </p>
        )}
        {inv.notes && <p className="mt-6 text-sm whitespace-pre-line text-on-surface-variant">{inv.notes}</p>}
      </Card>

      {!!inv.payments?.length && (
        <Card className="mx-auto mt-6 max-w-4xl print:hidden">
          <CardHeader title="Online payments" description="Checkouts opened through PayMongo. Only confirmed payments mark the invoice paid." />
          <Table caption="Online payments" compact>
            <thead><tr><Th>Started</Th><Th>Status</Th><Th>Method</Th><Th>Reference</Th><Th align="right">Amount</Th></tr></thead>
            <tbody>
              {inv.payments.map((p) => (
                <tr key={p.id}>
                  <Td className="whitespace-nowrap">{dateTime(p.created_at)}</Td>
                  <Td>
                    {p.status === 'paid' ? <Badge tone="success">Paid {dateTime(p.paid_at)}</Badge> : p.status === 'unapplied' ? <Badge tone="danger">Received, not applied</Badge> : <Badge>Not completed</Badge>}
                    {p.status === 'unapplied' && <div className="mt-1 text-xs text-on-surface-variant">The invoice was already settled or voided. Refund or reallocate this payment in PayMongo.</div>}
                  </Td>
                  <Td>{p.method ?? '—'}</Td>
                  <Td className="font-mono text-xs">{p.reference ?? '—'}</Td>
                  <Td align="right">{money(p.amount_cents)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      {dialog === 'link' && <PaymentLinkDialog invoiceId={id} onClose={() => setDialog(null)} />}

      {dialog === 'pay' && inv.client && <PayDialog invoiceId={id} clientId={inv.client.id} totalCents={inv.total_cents} onClose={() => setDialog(null)} />}
      <ConfirmDialog
        open={dialog === 'void'}
        onClose={() => setDialog(null)}
        title={`Void invoice ${inv.number}?`}
        description="The invoice is kept for the record but no longer collectible. Its time entries become billable again."
        destructive
        confirmLabel="Void invoice"
        loading={actions.void.isPending}
        onConfirm={() => actions.void.mutate(undefined, { onSuccess: () => setDialog(null) })}
      />
    </>
  )
}

function PayDialog({ invoiceId, clientId, totalCents, onClose }: { invoiceId: number; clientId: number; totalCents: number; onClose: () => void }) {
  const { pay } = useInvoiceAction(invoiceId)
  const trust = useTrustAccounts({ client_id: clientId })
  const [source, setSource] = useState('external')
  const [reference, setReference] = useState('')
  const eligible = trust.data?.data.filter((a) => a.status === 'open') ?? []

  return (
    <Dialog
      open
      onClose={onClose}
      title="Record payment"
      description={`Amount: ${money(totalCents)}`}
      footer={
        <>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button
            loading={pay.isPending}
            onClick={() =>
              pay.mutate(source === 'external' ? { payment_reference: reference || undefined } : { trust_account_id: Number(source) }, { onSuccess: onClose })
            }
          >
            Record payment
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <Field label="Paid from">
          {(a) => (
            <Select {...a} value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="external">Direct payment (cash, check, bank transfer, e-wallet)</option>
              {eligible.map((acc) => (
                <option key={acc.id} value={acc.id} disabled={acc.balance_cents < totalCents}>
                  Client trust {acc.account_number} — balance {money(acc.balance_cents)}{acc.balance_cents < totalCents ? ' (insufficient)' : ''}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {source === 'external' ? (
          <Field label="Reference" hint="OR number, check number or transaction ID">
            {(a) => <Input {...a} value={reference} onChange={(e) => setReference(e.target.value)} />}
          </Field>
        ) : (
          <p className="rounded-lg bg-warning-container p-3 text-sm text-on-warning-container">
            {money(totalCents)} will be disbursed from the client’s trust account and recorded in its ledger. Confirm the client has authorised applying trust funds to fees.
          </p>
        )}
      </div>
    </Dialog>
  )
}

function PaymentLinkDialog({ invoiceId, onClose }: { invoiceId: number; onClose: () => void }) {
  const link = usePaymentLink(invoiceId)
  const toast = useToast()
  const { mutate } = link

  // Once per dialog, even under StrictMode's double effects: each call opens a checkout.
  const started = useRef(false)
  useEffect(() => {
    if (started.current) return
    started.current = true
    mutate()
  }, [mutate])

  const copy = async () => {
    if (!link.data) return
    try {
      await navigator.clipboard.writeText(link.data.checkout_url)
      toast.success('Payment link copied')
    } catch {
      toast.error('Copy failed; select the link and copy it manually.')
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Payment link"
      description="Send this to the client by email or chat. They can pay by card, GCash, Maya or QR Ph; the invoice is marked paid automatically when PayMongo confirms."
      footer={<Button variant="text" onClick={onClose}>Close</Button>}
    >
      {link.isPending || link.isIdle ? (
        <PageLoader label="Creating link…" />
      ) : link.isError ? (
        <ErrorState error={link.error} onRetry={() => mutate()} />
      ) : (
        <div className="flex flex-col gap-3 sm:flex-row">
          <Input aria-label="Payment link" readOnly value={link.data.checkout_url} onFocus={(e) => e.target.select()} className="flex-1 font-mono text-xs" />
          <Button icon={<Copy className="size-4" />} onClick={copy}>Copy</Button>
        </div>
      )}
    </Dialog>
  )
}
