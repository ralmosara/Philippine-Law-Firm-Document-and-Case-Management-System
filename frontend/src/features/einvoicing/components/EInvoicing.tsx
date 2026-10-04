import { FileJson, RotateCw } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { date, dateTime, money } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { payloadUrl, STATUS_LABELS, STATUS_TONES, useEInvoicing, useInvoiceEInvoices, useRetryEInvoice, useSaveEInvoicingSettings, type EInvoiceRow, type EInvoiceStatus, type EInvoicingSettings } from '../api'

function StatusBadge({ row }: { row: EInvoiceRow }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      <Badge tone={STATUS_TONES[row.status]}>{row.kind === 'cancellation' ? `Cancellation: ${STATUS_LABELS[row.status].toLowerCase()}` : STATUS_LABELS[row.status]}</Badge>
      {row.overdue && <Badge tone="danger">Past due</Badge>}
    </span>
  )
}

/** On an invoice: its e-invoice (and any cancellation), with the answer from the provider. */
export function InvoiceEInvoiceCard({ invoiceId }: { invoiceId: number }) {
  const abilities = useAbilities()
  const query = useInvoiceEInvoices(invoiceId, abilities.manage_finances)
  const retry = useRetryEInvoice()
  if (!abilities.manage_finances || !query.data || (!query.data.enabled && query.data.data.length === 0)) return null

  return (
    <Card className="mx-auto mt-6 max-w-4xl print:hidden">
      <CardHeader title="BIR e-invoice" description="Sent automatically when the invoice is issued; a void sends a cancellation." actions={<Link to="/tax?tab=einvoicing" className="text-sm font-medium text-primary hover:underline">E-invoicing log</Link>} />
      {query.data.data.length === 0 ? <EmptyState title="Created when the invoice is issued" /> : (
        <ul className="divide-y divide-outline-variant">
          {query.data.data.map((row) => <Row key={row.id} row={row} onRetry={() => retry.mutate(row.id)} retrying={retry.isPending && retry.variables === row.id} />)}
        </ul>
      )}
    </Card>
  )
}

function Row({ row, onRetry, retrying }: { row: EInvoiceRow; onRetry: () => void; retrying: boolean }) {
  return (
    <li className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start">
      <div className="min-w-0 flex-1 text-sm">
        <StatusBadge row={row} />
        <p className="mt-1 text-on-surface-variant">
          {[
            row.provider_reference && `Reference ${row.provider_reference}`,
            row.accepted_at ? `accepted ${dateTime(row.accepted_at)}` : row.submitted_at ? `sent ${dateTime(row.submitted_at)}` : null,
            row.status !== 'withdrawn' && !row.submitted_at && `due by ${date(row.due_on)}`,
            row.attempts > 1 && `${row.attempts} attempts`,
          ].filter(Boolean).join(' · ')}
        </p>
        {row.error && <p className="mt-1 text-danger">{row.error}</p>}
        <p className="mt-1 font-mono text-[11px] break-all text-on-surface-variant">SHA-256 {row.payload_sha256}</p>
      </div>
      <div className="flex shrink-0 gap-2">
        <DownloadButton href={payloadUrl(row.id)} size="sm" icon={<FileJson className="size-4" />}>Document</DownloadButton>
        {row.can_retry && <Button size="sm" variant="tonal" icon={<RotateCw className="size-4" />} loading={retrying} onClick={onRetry}>Send again</Button>}
      </div>
    </li>
  )
}

/** Tax page tab: the firm's setting and the log of every e-invoice. */
export function EInvoicingTab() {
  const [status, setStatus] = useUrlState('status', '')
  const query = useEInvoicing(status)
  const retry = useRetryEInvoice()

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const { settings, counts, data } = query.data

  return (
    <div className="flex flex-col gap-6">
      <SettingsCard settings={settings} />
      <Card>
        <CardHeader
          title="E-invoices"
          description={`Each issued invoice, and each cancellation, with the provider's answer. They must reach the BIR within ${settings.deadline_days} days of the invoice.`}
          actions={
            <div className="w-48">
              <Select aria-label="Show" value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">All ({Object.values(counts).reduce((a, b) => a + b, 0)})</option>
                {(Object.keys(STATUS_LABELS) as EInvoiceStatus[]).filter((s) => counts[s]).map((s) => <option key={s} value={s}>{STATUS_LABELS[s]} ({counts[s]})</option>)}
              </Select>
            </div>
          }
        />
        {data.length === 0 ? (
          <EmptyState title="No e-invoices yet" description={settings.einvoicing_enabled ? 'They appear here as invoices are issued.' : 'Turn e-invoicing on above; invoices issued after that are sent.'} />
        ) : (
          <Table caption="E-invoices" compact>
            <thead><tr><Th>Invoice</Th><Th>Status</Th><Th>Due by</Th><Th>Provider reference</Th><Th align="right"><span className="sr-only">Actions</span></Th></tr></thead>
            <tbody>
              {data.map((row) => (
                <tr key={row.id}>
                  <Td>
                    {row.invoice ? <Link to={`/invoices/${row.invoice.id}`} className="font-medium text-primary hover:underline">{row.invoice.number}</Link> : '—'}
                    <div className="text-xs text-on-surface-variant">{row.invoice?.client}{row.invoice && ` · ${money(row.invoice.total_cents)}`}</div>
                  </Td>
                  <Td><StatusBadge row={row} />{row.error && <div className="mt-1 max-w-xs text-xs text-danger">{row.error}</div>}</Td>
                  <Td>{date(row.due_on)}</Td>
                  <Td className="font-mono text-xs">{row.provider_reference ?? '—'}</Td>
                  <Td align="right">
                    <div className="flex justify-end gap-1">
                      <DownloadButton href={payloadUrl(row.id)} size="sm" icon={<FileJson className="size-4" />}>Document</DownloadButton>
                      {row.can_retry && <Button size="sm" variant="tonal" loading={retry.isPending && retry.variables === row.id} onClick={() => retry.mutate(row.id)}>Send again</Button>}
                    </div>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </div>
  )
}

function SettingsCard({ settings }: { settings: EInvoicingSettings }) {
  const abilities = useAbilities()
  const save = useSaveEInvoicingSettings()
  const [enabled, setEnabled] = useState(settings.einvoicing_enabled)
  const [branch, setBranch] = useState(settings.tin_branch_code)
  const error = save.error ? ApiError.from(save.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ einvoicing_enabled: enabled, tin_branch_code: branch })
  }

  const connection = settings.driver === 'record'
    ? 'No provider connected yet: each e-invoice is built and kept on file, ready to send once you choose an accredited provider.'
    : settings.ready ? `Sending through the configured provider (${settings.driver}).` : `The provider connection is misconfigured: ${settings.problem}`

  return (
    <Card>
      <CardHeader title="Electronic invoicing (BIR EIS)" description="When on, every invoice the firm issues is sent to the BIR as an e-invoice, and every void as a cancellation." />
      <form onSubmit={submit} className="flex flex-col gap-4 p-5">
        <p className={`rounded-[3px] p-3 text-sm ${settings.driver !== 'record' && !settings.ready ? 'bg-danger-container text-on-danger-container' : 'bg-surface-container'}`}>{connection}</p>
        <FormError message={error && !Object.keys(error.errors).length ? error.message : error?.field('einvoicing_enabled')} />
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Firm TIN" hint="Change it in the firm settings.">{(a) => <Input {...a} value={settings.tin ?? 'Not set'} readOnly disabled />}</Field>
          <Field label="Branch code" error={error?.field('tin_branch_code')} hint="00000 for the head office.">
            {(a) => <Input {...a} inputMode="numeric" maxLength={5} value={branch} onChange={(e) => setBranch(e.target.value)} disabled={!abilities.manage_firm} />}
          </Field>
        </div>
        <Checkbox label="Send e-invoices for invoices issued from now on" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} disabled={!abilities.manage_firm} />
        {abilities.manage_firm && <Button type="submit" loading={save.isPending} className="self-start">Save</Button>}
      </form>
    </Card>
  )
}
