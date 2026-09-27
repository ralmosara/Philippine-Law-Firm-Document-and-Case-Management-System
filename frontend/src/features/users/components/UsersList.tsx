import { Pencil, Plus, ShieldOff, UserMinus } from 'lucide-react'
import { useState } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import type { User } from '@/shared/api/types'
import { dateTime, money } from '@/shared/lib/format'
import { useDebounced } from '@/shared/lib/hooks'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { SearchInput } from '@/shared/ui/Form'
import { Card, Pagination, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useDeactivateUser, useResetUserTwoFactor, useUsers } from '../api'
import { UserForm } from './UserForm'

export function UsersList() {
  const { user: me } = useCurrentSession()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const query = useUsers({ search: useDebounced(search), page })
  const deactivate = useDeactivateUser()
  const resetTwoFactor = useResetUserTwoFactor()
  const [editing, setEditing] = useState<User | 'new' | null>(null)
  const [removing, setRemoving] = useState<User | null>(null)
  const [resetting, setResetting] = useState<User | null>(null)

  return (
    <Card>
      <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row">
        <SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1) }} placeholder="Search name or email" className="flex-1" />
        <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>Add user</Button>
      </div>
      {query.isPending ? (
        <PageLoader />
      ) : query.isError ? (
        <div className="p-4"><ErrorState error={query.error} /></div>
      ) : (
        <Table caption="Users">
          <thead><tr><Th>Name</Th><Th>Role</Th><Th>Roll No.</Th><Th align="right">Rate</Th><Th>Last sign-in</Th><Th>2-step</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {query.data.data.map((u) => (
              <Tr key={u.id}>
                <Td><span className="font-medium">{u.name}</span><div className="text-xs text-on-surface-variant">{u.email}</div></Td>
                <Td>{u.role_label}</Td>
                <Td className="text-on-surface-variant tabular-nums">{u.roll_number ?? '—'}</Td>
                <Td align="right">{u.hourly_rate_cents ? `${money(u.hourly_rate_cents)}/hr` : '—'}</Td>
                <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(u.last_login_at)}</Td>
                <Td>{u.two_factor_enabled ? <Badge tone="success">On</Badge> : <span className="text-on-surface-variant">Off</span>}</Td>
                <Td>{u.is_active ? <Badge tone="success">Active</Badge> : <Badge>Deactivated</Badge>}</Td>
                <Td align="right" className="whitespace-nowrap">
                  <IconButton label={`Edit ${u.name}`} onClick={() => setEditing(u)}><Pencil className="size-4" /></IconButton>
                  {u.two_factor_enabled && u.id !== me.id && <IconButton label={`Reset two-step verification for ${u.name}`} onClick={() => setResetting(u)}><ShieldOff className="size-4" /></IconButton>}
                  {u.is_active && u.id !== me.id && <IconButton label={`Deactivate ${u.name}`} onClick={() => setRemoving(u)}><UserMinus className="size-4" /></IconButton>}
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      )}
      <Pagination page={query.data} onPage={setPage} />

      {editing && <UserForm open onClose={() => setEditing(null)} user={editing === 'new' ? undefined : editing} />}
      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        title={`Deactivate ${removing?.name}?`}
        description="They will be signed out and unable to sign in. Their history (time entries, notarial entries, status changes) is kept."
        destructive
        confirmLabel="Deactivate"
        loading={deactivate.isPending}
        onConfirm={() => removing && deactivate.mutate(removing.id, { onSuccess: () => setRemoving(null) })}
      />
      <ConfirmDialog
        open={resetting !== null}
        onClose={() => setResetting(null)}
        title={`Reset two-step verification for ${resetting?.name}?`}
        description="Use this when they have lost their phone. They can then sign in with their password alone and should turn two-step verification on again from their profile."
        destructive
        confirmLabel="Reset"
        loading={resetTwoFactor.isPending}
        onConfirm={() => resetting && resetTwoFactor.mutate(resetting.id, { onSuccess: () => setResetting(null) })}
      />
    </Card>
  )
}
