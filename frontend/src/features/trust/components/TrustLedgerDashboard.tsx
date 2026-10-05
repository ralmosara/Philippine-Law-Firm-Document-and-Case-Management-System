import { Landmark, Plus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useClientOptions } from '@/features/clients/api'
import { ApiError } from '@/shared/api/axios'
import { money } from '@/shared/lib/format'
import { useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Select } from '@/shared/ui/Form'
import { Card, PageHeader, Pagination, StatCard, Table, Tabs, Td, Th, Tr } from '@/shared/ui/Layout'
import { TrustReconciliationPanel } from './TrustReconciliation'
import { useMatterOptions, useOpenTrustAccount, useTrustAccounts } from '../api'

/** Client funds held in trust, separate from the firm's own money. */
export function TrustLedgerDashboard() {
  const abilities = useAbilities()
  const navigate = useNavigate()
  const [page, setPage] = useUrlPage()
  const [opening, setOpening] = useState(false)
  const query = useTrustAccounts({ page })
  const [tab, setTab] = useUrlState('tab', 'accounts')

  return (
    <>
      <PageHeader
        title="Trust Accounts"
        description="Client money held in trust. Every posting is permanent and carries its running balance; ledgers are reconciled nightly."
        actions={abilities.manage_finances && tab === 'accounts' && <Button icon={<Plus className="size-4" />} onClick={() => setOpening(true)}>Open account</Button>}
      />
      {abilities.manage_finances && (
        <Tabs<'accounts' | 'reconciliation'>
          label="Trust"
          value={tab as 'accounts' | 'reconciliation'}
          onChange={setTab}
          tabs={[{ value: 'accounts', label: 'Accounts' }, { value: 'reconciliation', label: 'Bank reconciliation' }]}
        />
      )}
      {tab === 'reconciliation' && abilities.manage_finances ? <TrustReconciliationPanel /> : (<>

      {query.data && (
        <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <StatCard label="Total held in trust" value={money(query.data.totals.balance_cents)} />
          <StatCard label="Accounts" value={query.data.meta.total} />
        </div>
      )}

      <Card>
        {query.isPending ? (
          <PageLoader />
        ) : query.isError ? (
          <div className="p-4"><ErrorState error={query.error} /></div>
        ) : query.data.data.length === 0 ? (
          <EmptyState icon={<Landmark className="size-6" />} title="No trust accounts" description="Open one when a client deposits funds for fees or costs." />
        ) : (
          <Table caption="Trust accounts">
            <thead>
              <tr><Th>Account</Th><Th>Client</Th><Th>Matter</Th><Th>Status</Th><Th align="right">Balance</Th></tr>
            </thead>
            <tbody>
              {query.data.data.map((a) => (
                <Tr key={a.id} onClick={() => navigate(`/trust/${a.id}`)}>
                  <Td><Link to={`/trust/${a.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-primary hover:underline">{a.account_number}</Link></Td>
                  <Td>{a.client?.name}</Td>
                  <Td className="text-on-surface-variant">{a.matter ? `${a.matter.reference} · ${a.matter.title}` : 'General'}</Td>
                  <Td>{a.status === 'open' ? <Badge tone="success">Open</Badge> : <Badge>Closed</Badge>}</Td>
                  <Td align="right" className="font-medium">{money(a.balance_cents)}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
        <Pagination page={query.data} onPage={setPage} />
      </Card>
      </>)}

      {opening && <OpenAccountDialog onClose={() => setOpening(false)} />}
    </>
  )
}

function OpenAccountDialog({ onClose }: { onClose: () => void }) {
  const clients = useClientOptions()
  const [clientId, setClientId] = useState('')
  const matters = useMatterOptions({ client_id: clientId ? Number(clientId) : undefined, enabled: !!clientId })
  const [matterId, setMatterId] = useState('')
  const open = useOpenTrustAccount()
  const navigate = useNavigate()
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const account = await open.mutateAsync({ client_id: Number(clientId), matter_id: matterId ? Number(matterId) : null })
      onClose()
      navigate(`/trust/${account.id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title="Open trust account" footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="trust-open" loading={open.isPending} disabled={!clientId}>Open account</Button></>}>
      <form id="trust-open" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error?.message} />
        <Field label="Client" required>
          {(a) => (
            <Select {...a} required value={clientId} onChange={(e) => { setClientId(e.target.value); setMatterId('') }}>
              <option value="">Select a client…</option>
              {clients.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Matter" hint="Optional; leave blank for a general client trust account.">
          {(a) => (
            <Select {...a} value={matterId} onChange={(e) => setMatterId(e.target.value)} disabled={!clientId}>
              <option value="">General (not tied to a matter)</option>
              {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
            </Select>
          )}
        </Field>
      </form>
    </Dialog>
  )
}
