import { useState, type FormEvent } from 'react'
import { useSetMinimumBalance } from '@/features/billing/api'
import { ApiError } from '@/shared/api/axios'
import type { TrustAccount } from '@/shared/api/types'
import { dateTime, money, toCents } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { StatCard } from '@/shared/ui/Layout'

/**
 * The agreed minimum deposit ("evergreen retainer"). Below it, the client is
 * asked to top up automatically.
 */
export function MinimumBalance({ account, canEdit }: { account: TrustAccount; canEdit: boolean }) {
  const [editing, setEditing] = useState(false)
  const min = account.minimum_balance_cents ?? null

  return (
    <>
      <StatCard
        label="Minimum balance"
        value={min === null ? 'Not set' : money(min)}
        tone={account.below_minimum ? 'warning' : undefined}
        detail={
          <span className="flex flex-col items-start gap-1">
            {account.below_minimum
              ? `Below minimum${account.replenishment_requested_at ? `; client asked to top up ${dateTime(account.replenishment_requested_at)}` : ''}`
              : 'Below it, the client is asked to top up.'}
            {canEdit && <Button size="sm" variant="text" className="-ml-3" onClick={() => setEditing(true)}>{min === null ? 'Set minimum' : 'Change'}</Button>}
          </span>
        }
      />
      {editing && <MinimumDialog accountId={account.id} current={min} onClose={() => setEditing(false)} />}
    </>
  )
}

function MinimumDialog({ accountId, current, onClose }: { accountId: number; current: number | null; onClose: () => void }) {
  const save = useSetMinimumBalance(accountId)
  const [amount, setAmount] = useState(current ? (current / 100).toFixed(2) : '')
  const error = save.error ? ApiError.from(save.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(amount.trim() === '' ? null : toCents(amount), { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} title="Minimum trust balance" description="Usually agreed in the engagement letter. Leave blank for no minimum." footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="minimum-form" loading={save.isPending}>Save</Button></>}>
      <form id="minimum-form" onSubmit={submit} className="flex flex-col gap-3">
        <Field label="Minimum (₱)" error={error?.field('minimum_balance_cents')}>{(a) => <Input {...a} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="50,000.00" />}</Field>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
      </form>
    </Dialog>
  )
}
