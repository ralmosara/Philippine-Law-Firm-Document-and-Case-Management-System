import { BookOpen, Plus } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useLookups } from '@/features/auth/session'
import { useMatterOptions } from '@/features/trust/api'
import { ApiError } from '@/shared/api/axios'
import { dateTime, money, toCents } from '@/shared/lib/format'
import { useDebounced, useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, SearchInput, Select } from '@/shared/ui/Form'
import { Card, Pagination, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useCreateNotarialEntry, useNextNotarialNumber, useNotarialEntries } from '../api'

/** The notarial register (2004 Rules on Notarial Practice, Rule VI). Entries are permanent. */
export function NotarialRegister() {
  const [search, setSearch] = useUrlState('q')
  const [year, setYear] = useUrlState('year', String(new Date().getFullYear()))
  const [page, setPage] = useUrlPage()
  const [creating, setCreating] = useState(false)
  const query = useNotarialEntries({ search: useDebounced(search), series_year: Number(year), page })
  const years = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i)

  return (
    <Card>
      <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-center">
        <SearchInput value={search} onChange={setSearch} placeholder="Search document or principal" className="flex-1" />
        <Select aria-label="Series year" value={year} onChange={(e) => setYear(e.target.value)} className="sm:w-36">
          {years.map((y) => <option key={y} value={y}>Series of {y}</option>)}
        </Select>
        <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>New entry</Button>
      </div>

      {query.isPending ? (
        <PageLoader />
      ) : query.isError ? (
        <div className="p-4"><ErrorState error={query.error} /></div>
      ) : query.data.data.length === 0 ? (
        <EmptyState icon={<BookOpen className="size-6" />} title="No entries for this year" />
      ) : (
        <Table caption="Notarial register">
          <thead>
            <tr><Th>Doc / Page / Book</Th><Th>Document</Th><Th>Act</Th><Th>Principal & ID</Th><Th>Notary</Th><Th>Date</Th><Th align="right">Fee</Th></tr>
          </thead>
          <tbody>
            {query.data.data.map((e) => (
              <Tr key={e.id}>
                <Td className="whitespace-nowrap tabular-nums">{e.doc_number} / {e.page_number} / {e.book_number}</Td>
                <Td>{e.document_title}{e.matter && <div className="text-xs text-on-surface-variant">{e.matter.reference}</div>}</Td>
                <Td className="capitalize">{e.act_type.replace(/_/g, ' ')}</Td>
                <Td>{e.principal_name}<div className="text-xs text-on-surface-variant">{e.competent_evidence}</div></Td>
                <Td className="text-on-surface-variant">{e.notary?.name}</Td>
                <Td className="whitespace-nowrap">{dateTime(e.notarized_at)}</Td>
                <Td align="right">{money(e.fee_cents)}</Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      )}
      <Pagination page={query.data} onPage={setPage} />
      {creating && <NotarialEntryDialog onClose={() => setCreating(false)} />}
    </Card>
  )
}

function NotarialEntryDialog({ onClose }: { onClose: () => void }) {
  const lookups = useLookups()
  const matters = useMatterOptions()
  const next = useNextNotarialNumber(true)
  const create = useCreateNotarialEntry()
  const now = new Date()
  const [form, setForm] = useState({
    act_type: 'acknowledgment',
    document_title: '',
    principal_name: '',
    competent_evidence: '',
    fee: '',
    notarized_at: new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 16),
    matter_id: '',
  })
  const [error, setError] = useState<ApiError | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await create.mutateAsync({
        act_type: form.act_type,
        document_title: form.document_title,
        principal_name: form.principal_name,
        competent_evidence: form.competent_evidence,
        fee_cents: form.fee ? toCents(form.fee) : 0,
        notarized_at: form.notarized_at.replace('T', ' ') + ':00',
        matter_id: form.matter_id ? Number(form.matter_id) : null,
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
      title="New notarial entry"
      size="lg"
      description={next.data && <>Will be entered as <strong>Doc. No. {next.data.doc_number}, Page No. {next.data.page_number}, Book No. {next.data.book_number}, Series of {next.data.series_year}</strong>. Entries cannot be edited afterwards.</>}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="notarial-form" loading={create.isPending}>Enter in register</Button></>}
    >
      <form id="notarial-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>
        <Field label="Notarial act" required>
          {(a) => <Select {...a} value={form.act_type} onChange={set('act_type')}>{lookups.data?.notarial_act_types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</Select>}
        </Field>
        <Field label="Date and time" required error={error?.field('notarized_at')}>
          {(a) => <Input {...a} type="datetime-local" required value={form.notarized_at} onChange={set('notarized_at')} />}
        </Field>
        <Field label="Document" required className="sm:col-span-2" error={error?.field('document_title')}>
          {(a) => <Input {...a} required placeholder="Deed of Absolute Sale" value={form.document_title} onChange={set('document_title')} />}
        </Field>
        <Field label="Principal" required error={error?.field('principal_name')}>
          {(a) => <Input {...a} required value={form.principal_name} onChange={set('principal_name')} />}
        </Field>
        <Field label="Competent evidence of identity" required error={error?.field('competent_evidence')} hint="Rule II, Sec. 12: ID type and number">
          {(a) => <Input {...a} required placeholder="Philippine Passport P1234567A" value={form.competent_evidence} onChange={set('competent_evidence')} />}
        </Field>
        <Field label="Notarial fee (₱)" error={error?.field('fee_cents')}>
          {(a) => <Input {...a} inputMode="decimal" placeholder="500.00" value={form.fee} onChange={set('fee')} />}
        </Field>
        <Field label="Related matter">
          {(a) => (
            <Select {...a} value={form.matter_id} onChange={set('matter_id')}>
              <option value="">None</option>
              {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
            </Select>
          )}
        </Field>
      </form>
    </Dialog>
  )
}
