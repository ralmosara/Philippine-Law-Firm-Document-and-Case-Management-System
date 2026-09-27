import { ArrowDownLeft, ArrowLeft, ArrowUpRight, ShieldCheck } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { dateTime, money, toCents } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, Pagination, StatCard, Table, Td, Th } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import { MinimumBalance } from './MinimumBalance'
import { useCloseTrustAccount, usePostTrustTransaction, useReconcile, useTrustAccount, useTrustTransactions } from '../api'

type PostType = 'deposit' | 'disbursement'

export function TrustAccountDetail() {
  const id = Number(useParams().id)
  const account = useTrustAccount(id)
  const [page, setPage] = useState(1)
  const ledger = useTrustTransactions(id, page)
  const abilities = useAbilities()
  const reconcile = useReconcile(id)
  const close = useCloseTrustAccount(id)
  const toast = useToast()
  const [posting, setPosting] = useState<PostType | null>(null)
  const [closing, setClosing] = useState(false)

  if (account.isPending) return <PageLoader />
  if (account.isError) return <ErrorState error={account.error} onRetry={() => account.refetch()} />
  const a = account.data
  const isOpen = a.status === 'open'

  const runReconcile = () =>
    reconcile.mutate(undefined, {
      onSuccess: (r) => (r.reconciled ? toast.success('Ledger reconciles: every running balance checks out.') : toast.error(`Reconciliation failed: ${r.problems.join(' ')}`)),
    })

  return (
    <>
      <PageHeader
        back={<Link to="/trust" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> Trust accounts</Link>}
        title={<span className="flex items-center gap-3">{a.account_number} {!isOpen && <Badge>Closed</Badge>}</span>}
        description={
          <>
            {a.client && <Link to={`/clients/${a.client.id}`} className="text-primary hover:underline">{a.client.name}</Link>}
            {a.matter && <> · <Link to={`/matters/${a.matter.id}`} className="text-primary hover:underline">{a.matter.reference}</Link></>}
          </>
        }
        actions={
          <>
            {abilities.manage_finances && <Button variant="text" icon={<ShieldCheck className="size-4" />} loading={reconcile.isPending} onClick={runReconcile}>Reconcile</Button>}
            {abilities.manage_finances && isOpen && a.balance_cents === 0 && <Button variant="outlined" onClick={() => setClosing(true)}>Close account</Button>}
            {isOpen && abilities.manage_finances && <Button variant="outlined" icon={<ArrowUpRight className="size-4" />} onClick={() => setPosting('disbursement')}>Disburse</Button>}
            {isOpen && abilities.work_matters && <Button icon={<ArrowDownLeft className="size-4" />} onClick={() => setPosting('deposit')}>Record deposit</Button>}
          </>
        }
      />

      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Balance held in trust" value={money(a.balance_cents)} />
        <MinimumBalance account={a} canEdit={abilities.manage_finances && isOpen} />
      </div>

      <Card>
        <CardHeader title="Ledger" description="Append-only. Corrections are recorded as new, offsetting entries." />
        {ledger.isPending ? (
          <PageLoader />
        ) : ledger.isError ? (
          <ErrorState error={ledger.error} />
        ) : ledger.data.data.length === 0 ? (
          <EmptyState title="No transactions yet" />
        ) : (
          <Table caption="Trust ledger">
            <thead>
              <tr><Th>Date</Th><Th>Description</Th><Th>Reference</Th><Th>By</Th><Th align="right">Deposit</Th><Th align="right">Disbursement</Th><Th align="right">Balance</Th></tr>
            </thead>
            <tbody>
              {ledger.data.data.map((t) => (
                <tr key={t.id}>
                  <Td className="whitespace-nowrap">{dateTime(t.created_at)}</Td>
                  <Td>{t.description}</Td>
                  <Td className="text-on-surface-variant">{t.reference ?? '—'}</Td>
                  <Td className="text-on-surface-variant">{t.creator?.name ?? '—'}</Td>
                  <Td align="right" className="text-success">{t.type === 'deposit' ? money(t.amount_cents) : ''}</Td>
                  <Td align="right">{t.type === 'disbursement' ? `(${money(t.amount_cents)})` : ''}</Td>
                  <Td align="right" className="font-medium">{money(t.balance_after_cents)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
        <Pagination page={ledger.data} onPage={setPage} />
      </Card>

      {posting && <PostDialog accountId={id} type={posting} balanceCents={a.balance_cents} onClose={() => setPosting(null)} />}
      <ConfirmDialog
        open={closing}
        onClose={() => setClosing(false)}
        title="Close this trust account?"
        description="No further deposits or disbursements can be posted. The ledger remains available."
        confirmLabel="Close account"
        loading={close.isPending}
        onConfirm={() => close.mutate(undefined, { onSuccess: () => setClosing(false) })}
      />
    </>
  )
}

function PostDialog({ accountId, type, balanceCents, onClose }: { accountId: number; type: PostType; balanceCents: number; onClose: () => void }) {
  const post = usePostTrustTransaction(accountId)
  const [amount, setAmount] = useState('')
  const [description, setDescription] = useState(type === 'deposit' ? 'Client deposit' : '')
  const [reference, setReference] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const cents = toCents(amount)
  const invalid = !Number.isFinite(cents) || cents <= 0
  const overdraw = type === 'disbursement' && !invalid && cents > balanceCents

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await post.mutateAsync({ type, amount_cents: cents, description, reference: reference || undefined })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={type === 'deposit' ? 'Record deposit' : 'Disburse trust funds'}
      description={type === 'disbursement' ? `Available: ${money(balanceCents)}. Disburse only for the client’s authorised purposes.` : undefined}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="trust-post" loading={post.isPending} disabled={invalid || overdraw || !description.trim()}>Post {type}</Button></>}
    >
      <form id="trust-post" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !error.field('amount') && !error.field('amount_cents') ? error.message : undefined} />
        <Field label="Amount (₱)" required error={overdraw ? 'Exceeds the available balance.' : error?.field('amount') ?? error?.field('amount_cents')}>
          {(a) => <Input {...a} required inputMode="decimal" placeholder="0.00" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />}
        </Field>
        <Field label="Description" required error={error?.field('description')}>
          {(a) => <Input {...a} required value={description} onChange={(e) => setDescription(e.target.value)} placeholder={type === 'disbursement' ? 'RTC docket and filing fees' : undefined} />}
        </Field>
        <Field label="Reference" hint="Official receipt, check or bank transaction number">
          {(a) => <Input {...a} value={reference} onChange={(e) => setReference(e.target.value)} />}
        </Field>
      </form>
    </Dialog>
  )
}
