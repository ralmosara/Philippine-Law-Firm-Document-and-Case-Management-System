import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, FileDown, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, dateTime, money, toCents } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

interface Proof {
  id: number
  status: 'pending' | 'confirmed' | 'rejected'
  amount_cents: number
  paid_on: string
  method_label: string
  reference: string | null
  note: string | null
  created_at: string
  invoice: { id: number; number: string; balance_cents: number; status: string } | null
  client: string | null
  file: { id: number; name: string } | null
}

const invalidate = [['payment-proofs'], ['invoices'], ['collections'], ['dashboard']]

/** Deposit slips and screenshots clients sent from the portal, waiting to be matched with the bank. */
export function PaymentProofQueue() {
  const query = useQuery({ queryKey: ['payment-proofs', 'pending'], queryFn: () => get<{ data: Proof[] }>('/v1/payment-proofs', { status: 'pending' }) })
  const [confirming, setConfirming] = useState<Proof | null>(null)
  const [rejecting, setRejecting] = useState<Proof | null>(null)

  return (
    <Card>
      <CardHeader title="Proofs of payment" description="Sent by clients from the portal. Check each against the bank or e-wallet, then confirm it to record the payment, or reject it with a reason the client will see." />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? (
        <EmptyState title="Nothing to check" description="New proofs of payment appear here and in the notification bell." />
      ) : (
        <Table caption="Proofs of payment to check" compact>
          <thead><tr><Th>Client</Th><Th>Billing statement</Th><Th>Paid</Th><Th>How</Th><Th align="right">Amount</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {query.data.data.map((p) => (
              <tr key={p.id}>
                <Td className="font-medium">{p.client}<div className="text-xs font-normal text-on-surface-variant">Sent {dateTime(p.created_at)}</div></Td>
                <Td>{p.invoice ? <Link to={`/billing/invoices/${p.invoice.id}`} className="text-primary hover:underline">{p.invoice.number}</Link> : '—'}{p.invoice && <div className="text-xs text-on-surface-variant">Balance {money(p.invoice.balance_cents)}</div>}</Td>
                <Td className="whitespace-nowrap">{date(p.paid_on)}</Td>
                <Td>{p.method_label}{p.reference && <div className="font-mono text-xs text-on-surface-variant">{p.reference}</div>}</Td>
                <Td align="right" className="font-medium">{money(p.amount_cents)}</Td>
                <Td align="right">
                  <div className="flex justify-end gap-1">
                    {p.file && <DownloadButton size="sm" href={`/api/v1/files/${p.file.id}/download`} icon={<FileDown className="size-4" />}>Slip</DownloadButton>}
                    <Button size="sm" variant="tonal" icon={<CheckCircle2 className="size-4" />} onClick={() => setConfirming(p)}>Confirm</Button>
                    <Button size="sm" variant="text" icon={<XCircle className="size-4" />} onClick={() => setRejecting(p)}>Reject</Button>
                  </div>
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {confirming && <ConfirmProofDialog proof={confirming} onClose={() => setConfirming(null)} />}
      {rejecting && <RejectProofDialog proof={rejecting} onClose={() => setRejecting(null)} />}
    </Card>
  )
}

function ConfirmProofDialog({ proof, onClose }: { proof: Proof; onClose: () => void }) {
  const [amount, setAmount] = useState((proof.amount_cents / 100).toFixed(2))
  const [withheld, setWithheld] = useState('')
  const [receivedOn, setReceivedOn] = useState(proof.paid_on)
  const confirm = useApiMutation((input: object) => post(`/v1/payment-proofs/${proof.id}/confirm`, input), { invalidate, success: 'Payment recorded; the client has been told', toastErrors: false })
  const error = confirm.error ? ApiError.from(confirm.error) : null

  return (
    <Dialog
      open
      onClose={onClose}
      title={`Record ${proof.client}'s payment?`}
      description="Correct the amount or date if the bank shows something different. Tax withheld is for clients who withhold on our fees (Form 2307)."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button loading={confirm.isPending} onClick={() => confirm.mutate({ amount_cents: toCents(amount), withholding_cents: withheld.trim() ? toCents(withheld) : 0, received_on: receivedOn }, { onSuccess: onClose })}>Record payment</Button></>}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Field label="Amount received (₱)" error={error?.field('amount_cents')}>{(a) => <Input {...a} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />}</Field>
        <Field label="Tax withheld (₱)" error={error?.field('withholding_cents')}>{(a) => <Input {...a} inputMode="decimal" value={withheld} placeholder="0.00" onChange={(e) => setWithheld(e.target.value)} />}</Field>
        <Field label="Received on" error={error?.field('received_on')}>{(a) => <Input {...a} type="date" value={receivedOn} onChange={(e) => setReceivedOn(e.target.value)} />}</Field>
      </div>
      <div className="mt-3"><FormError message={error && !error.field('amount_cents') && !error.field('withholding_cents') && !error.field('received_on') ? (error.field('proof') ?? error.message) : null} /></div>
    </Dialog>
  )
}

function RejectProofDialog({ proof, onClose }: { proof: Proof; onClose: () => void }) {
  const reject = useApiMutation((reason: string) => post(`/v1/payment-proofs/${proof.id}/reject`, { reason }), { invalidate, success: 'Rejected; the client has been told why' })

  return (
    <ConfirmDialog
      open
      onClose={onClose}
      title={`Reject ${proof.client}'s proof of payment?`}
      description="The client is emailed the reason, in their language, and can send it again from the portal."
      reasonLabel="Reason the client will see"
      reasonRequired
      destructive
      confirmLabel="Reject"
      loading={reject.isPending}
      onConfirm={(reason) => reject.mutate(reason, { onSuccess: onClose })}
    />
  )
}
