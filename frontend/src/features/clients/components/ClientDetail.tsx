import { ArrowLeft, Download, KeyRound, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { MatterForm } from '@/features/matters/components/MatterForm'
import { MatterStatusBadge } from '@/features/matters/components/StatusBadge'
import { CorporateCard } from '@/features/corporate/components/CorporateCard'
import { useTrustAccounts } from '@/features/trust/api'
import { ApiError } from '@/shared/api/axios'
import type { Client } from '@/shared/api/types'
import { date, dateTime, money } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader, DescriptionList, PageHeader, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useClient, useDeleteClient, usePortalAccess } from '../api'
import { ClientForm } from './ClientForm'
import { LOCALES, type Locale } from '@/shared/lib/i18n'

export function ClientDetail() {
  const id = Number(useParams().id)
  const client = useClient(id)
  const abilities = useAbilities()
  const trust = useTrustAccounts({ client_id: id })
  const navigate = useNavigate()
  const remove = useDeleteClient()
  const [dialog, setDialog] = useState<'edit' | 'matter' | 'portal' | 'delete' | null>(null)

  if (client.isPending) return <PageLoader />
  if (client.isError) return <ErrorState error={client.error} onRetry={() => client.refetch()} />
  const c = client.data

  return (
    <>
      <PageHeader
        back={<Link to="/clients" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> Clients</Link>}
        title={c.name}
        description={<span className="capitalize">{c.type} client since {date(c.created_at)}</span>}
        actions={
          <>
            {abilities.manage_finances && !c.matters?.length && (
              <Button variant="text" icon={<Trash2 className="size-4" />} onClick={() => setDialog('delete')}>Delete</Button>
            )}
            {abilities.manage_firm && <DownloadButton href={`/api/v1/clients/${c.id}/personal-data`} icon={<Download className="size-4" />}>Personal data</DownloadButton>}
            {abilities.practice_law && <Button variant="outlined" icon={<KeyRound className="size-4" />} onClick={() => setDialog('portal')}>Portal access</Button>}
            <Button variant="outlined" icon={<Pencil className="size-4" />} onClick={() => setDialog('edit')}>Edit</Button>
            {abilities.work_matters && <Button icon={<Plus className="size-4" />} onClick={() => setDialog('matter')}>New matter</Button>}
          </>
        }
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Matters" />
          {!c.matters?.length ? (
            <EmptyState title="No matters for this client" />
          ) : (
            <Table caption="Client matters">
              <thead><tr><Th>Matter</Th><Th>Status</Th><Th>Responsible</Th><Th>Opened</Th></tr></thead>
              <tbody>
                {c.matters.map((m) => (
                  <Tr key={m.id} onClick={() => navigate(`/matters/${m.id}`)}>
                    <Td>
                      <Link to={`/matters/${m.id}`} onClick={(e) => e.stopPropagation()} className="font-medium hover:text-primary">{m.title}</Link>
                      <div className="text-xs text-on-surface-variant">{m.reference}</div>
                    </Td>
                    <Td><MatterStatusBadge status={m.status} label={m.status_label} /></Td>
                    <Td className="text-on-surface-variant">{m.responsible_lawyer?.name ?? '—'}</Td>
                    <Td className="text-on-surface-variant">{date(m.opened_at)}</Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>

        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader title="Details" />
            <div className="p-5">
              <DescriptionList
                items={[
                  { label: 'Email', value: c.email },
                  { label: 'Phone', value: c.phone },
                  { label: 'TIN', value: c.tin },
                  { label: 'Address', value: c.address },
                  { label: 'Client portal', value: c.portal_enabled ? <Badge tone="success">Enabled</Badge> : <Badge>Off</Badge> },
                  { label: 'Last portal sign-in', value: c.last_portal_login_at ? dateTime(c.last_portal_login_at) : null },
                ]}
              />
              {c.notes && <p className="mt-4 text-sm whitespace-pre-line text-on-surface-variant">{c.notes}</p>}
            </div>
          </Card>
          <Card>
            <CardHeader title="Trust balance" />
            <div className="p-5">
              <p className="text-2xl font-semibold tabular-nums">{money(trust.data?.data.reduce((sum, a) => sum + a.balance_cents, 0))}</p>
              <p className="text-sm text-on-surface-variant">{trust.data?.data.length ?? 0} account(s)</p>
            </div>
          </Card>
          {c.type === 'corporate' && <CorporateCard clientId={c.id} clientName={c.name} />}
        </div>
      </div>

      <ClientForm open={dialog === 'edit'} onClose={() => setDialog(null)} client={c} />
      <MatterForm open={dialog === 'matter'} onClose={() => setDialog(null)} defaultClientId={c.id} />
      {dialog === 'portal' && <PortalAccessDialog client={c} onClose={() => setDialog(null)} />}
      <ConfirmDialog
        open={dialog === 'delete'}
        onClose={() => setDialog(null)}
        title="Delete client?"
        description={<>Delete <strong>{c.name}</strong>? The record is kept for conflict checks as a former client.</>}
        destructive
        confirmLabel="Delete"
        loading={remove.isPending}
        onConfirm={() => remove.mutate(c.id, { onSuccess: () => navigate('/clients') })}
      />
    </>
  )
}

function PortalAccessDialog({ client, onClose }: { client: Client; onClose: () => void }) {
  const access = usePortalAccess(client.id)
  const [enabled, setEnabled] = useState(client.portal_enabled)
  const [password, setPassword] = useState('')
  const [method, setMethod] = useState<'keep' | 'invite' | 'password'>(client.portal_password_set ? 'keep' : 'invite')
  const [locale, setLocale] = useState<Locale>(client.portal_locale)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await access.mutateAsync({
        portal_enabled: enabled,
        locale,
        ...(enabled && method === 'invite' ? { send_invite: true } : {}),
        ...(enabled && method === 'password' && password ? { password } : {}),
      })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Client portal access"
      description="Clients can see their matters' status, upcoming hearings, shared documents, invoices and trust balances. They cannot see internal deadlines or notes."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="portal-form" loading={access.isPending}>Save</Button></>}
    >
      <form id="portal-form" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !error.field('password') ? error.message : undefined} />
        {!client.email && <p className="rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">Add an email address to this client before enabling portal access.</p>}
        <Checkbox label="Allow this client to sign in to the portal" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} disabled={!client.email} />
        {enabled && (
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">Password</legend>
            {client.portal_password_set && (
              <label className="flex items-center gap-2 text-sm"><input type="radio" name="pw" checked={method === 'keep'} onChange={() => setMethod('keep')} className="accent-(--color-primary)" /> Keep the current password</label>
            )}
            <label className="flex items-start gap-2 text-sm">
              <input type="radio" name="pw" checked={method === 'invite'} onChange={() => setMethod('invite')} className="mt-1 accent-(--color-primary)" />
              <span>
                Email {client.email ?? 'the client'} a link to {client.portal_password_set ? 'choose a new' : 'set their own'} password
                <span className="block text-xs text-on-surface-variant">Recommended: nobody else ever knows the password.</span>
              </span>
            </label>
            <label className="flex items-center gap-2 text-sm"><input type="radio" name="pw" checked={method === 'password'} onChange={() => setMethod('password')} className="accent-(--color-primary)" /> Set a password myself</label>
          </fieldset>
        )}
        {enabled && (
          <Field label="Language" hint="Of the portal and of the emails the firm sends this client. They can change it in the portal.">
            {(a) => (
              <Select {...a} value={locale} onChange={(e) => setLocale(e.target.value as Locale)}>
                {LOCALES.map((l) => <option key={l.value} value={l.value}>{l.label}</option>)}
              </Select>
            )}
          </Field>
        )}
        {enabled && method === 'password' && (
          <Field label="Password" required error={error?.field('password')} hint={`Share it with ${client.email} through a secure channel; they sign in at /portal.`}>
            {(a) => <Input {...a} type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} minLength={8} />}
          </Field>
        )}
      </form>
    </Dialog>
  )
}
