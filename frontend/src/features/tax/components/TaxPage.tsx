import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, FileDown } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useFirmSettings, useSaveFirmSettings } from '@/features/settings/api'
import { ApiError, get, patch } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, money, today } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Tabs, Td, Th } from '@/shared/ui/Layout'

interface SawtRow {
  payor_tin: string | null
  payor_name: string
  atc: string
  nature: string
  income_payment_cents: number
  rate: number | null
  tax_withheld_cents: number
  with_2307: boolean
  payments: number
}

interface Quarter {
  year: number
  quarter: number
  from: string
  to: string
  invoiced: { count: number; fees_cents: number; vat_cents: number; expenses_cents: number; total_cents: number }
  collected: { count: number; cash_cents: number; withheld_cents: number }
  creditable: { with_2307_cents: number; without_2307_cents: number; without_2307_count: number }
  firm: { name: string; tin: string | null; vat_registered: boolean; taxpayer_type: 'individual' | 'juridical'; withholding_atc: string; has_employees: boolean }
  sawt: SawtRow[]
}

interface Filing {
  id: number
  form: string
  description: string
  period: string
  due_on: string
  status: 'pending' | 'filed' | 'not_applicable'
  is_overdue: boolean
  filed_on: string | null
  reference: string | null
  notes: string | null
  filed_by: string | null
}

type Tab = 'quarter' | 'calendar' | 'setup'
const thisYear = new Date().getFullYear()
const thisQuarter = Math.floor(new Date().getMonth() / 3) + 1

/** The firm's own BIR compliance. */
export function TaxPage() {
  const [tab, setTab] = useUrlState('tab', 'quarter')

  return (
    <>
      <PageHeader title="BIR tax compliance" description="The firm's own returns: figures for the quarter, the SAWT from clients' Forms 2307, and the filing calendar." />
      <p className="mb-4 flex items-start gap-2 rounded-[3px] border border-warning/40 bg-warning-container px-3 py-2 text-sm text-on-warning-container">
        <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
        Figures come from the invoices and payments recorded here, and deadlines are those commonly applied to eBIRForms filers. Have your accountant confirm them before filing; eFPS filers have staggered dates.
      </p>
      <Tabs<Tab> label="Tax sections" value={tab as Tab} onChange={setTab} tabs={[{ value: 'quarter', label: 'Quarter & SAWT' }, { value: 'calendar', label: 'Filing calendar' }, { value: 'setup', label: 'Setup' }]} />
      {tab === 'quarter' && <QuarterTab />}
      {tab === 'calendar' && <CalendarTab />}
      {tab === 'setup' && <SetupTab />}
    </>
  )
}

function QuarterTab() {
  const [year, setYear] = useUrlState('year', String(thisYear))
  const [quarter, setQuarter] = useUrlState('q', String(thisQuarter))
  const query = useQuery({ queryKey: ['tax', 'quarter', year, quarter], queryFn: () => get<Quarter>('/v1/tax/quarter', { year, quarter }) })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2">
        <Select aria-label="Year" value={year} onChange={(e) => setYear(e.target.value)} className="w-28">
          {[thisYear + 1, thisYear, thisYear - 1, thisYear - 2].map((y) => <option key={y}>{y}</option>)}
        </Select>
        <Select aria-label="Quarter" value={quarter} onChange={(e) => setQuarter(e.target.value)} className="w-40">
          {[1, 2, 3, 4].map((q) => <option key={q} value={q}>Q{q} ({['Jan–Mar', 'Apr–Jun', 'Jul–Sep', 'Oct–Dec'][q - 1]})</option>)}
        </Select>
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <ErrorState error={query.error} onRetry={() => query.refetch()} /> : (() => {
        const d = query.data
        return (
          <>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard label="Fees invoiced" value={money(d.invoiced.fees_cents)} detail={`${d.invoiced.count} invoices; VAT on services follows sales invoiced (RA 11976)`} />
              <StatCard label={d.firm.vat_registered ? 'Output VAT (12%)' : 'Percentage tax base'} value={money(d.firm.vat_registered ? d.invoiced.vat_cents : d.invoiced.fees_cents)} detail={d.firm.vat_registered ? 'For the 2550Q' : 'For the 2551Q'} />
              <StatCard label="Collected" value={money(d.collected.cash_cents)} detail={`${d.collected.count} payments received in the quarter`} />
              <StatCard label="Tax withheld by clients" value={money(d.collected.withheld_cents)} tone={d.creditable.without_2307_count ? 'warning' : undefined}
                detail={d.creditable.without_2307_count ? `${money(d.creditable.without_2307_cents)} still without a Form 2307` : 'All backed by Form 2307'} />
            </div>
            <Card>
              <CardHeader
                title={`SAWT: ${d.year} Q${d.quarter}`}
                description={`Summary Alphalist of Withholding Taxes, attached to the ${d.firm.taxpayer_type === 'individual' ? '1701Q/1701' : '1702Q/1702'}. Only tax backed by a Form 2307 can be claimed. Where a 2307 shows different amounts, use the 2307.`}
                actions={<DownloadButton href={`/api/v1/tax/sawt.csv?year=${d.year}&quarter=${d.quarter}`} size="sm" variant="outlined" icon={<FileDown className="size-4" />}>CSV</DownloadButton>}
              />
              {d.sawt.length === 0 ? <EmptyState title="No tax withheld this quarter" /> : (
                <Table caption="SAWT">
                  <thead><tr><Th>TIN of withholding agent</Th><Th>Registered name</Th><Th>ATC</Th><Th>Nature</Th><Th align="right">Income payment</Th><Th align="right">Rate</Th><Th align="right">Tax withheld</Th><Th>Form 2307</Th></tr></thead>
                  <tbody>
                    {d.sawt.map((r, i) => (
                      <tr key={i}>
                        <Td className="whitespace-nowrap tabular-nums">{r.payor_tin ?? <span className="text-danger">No TIN on file</span>}</Td>
                        <Td>{r.payor_name}</Td>
                        <Td>{r.atc}</Td>
                        <Td>{r.nature}</Td>
                        <Td align="right">{money(r.income_payment_cents)}</Td>
                        <Td align="right">{r.rate !== null ? `${r.rate}%` : '—'}</Td>
                        <Td align="right">{money(r.tax_withheld_cents)}</Td>
                        <Td>{r.with_2307 ? <Badge tone="success">Received</Badge> : <Link to="/billing?tab=form-2307" className="text-danger hover:underline">Missing: collect it</Link>}</Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          </>
        )
      })()}
    </div>
  )
}

function CalendarTab() {
  const [year, setYear] = useUrlState('year', String(thisYear))
  const [show, setShow] = useUrlState('show', 'open')
  const [editing, setEditing] = useState<Filing | null>(null)
  const query = useQuery({ queryKey: ['tax', 'filings', year], queryFn: () => get<{ filings: Filing[] }>('/v1/tax/filings', { year }) })
  const rows = (query.data?.filings ?? []).filter((f) => show === 'all' || f.status === 'pending')

  return (
    <Card>
      <CardHeader
        title={`Filing calendar ${year}`}
        description="Returns for the tax year, with deadlines moved past weekends and holidays. Finance partners are reminded a week before, the day before and when overdue."
        actions={
          <div className="flex gap-1.5">
            <Select aria-label="Year" value={year} onChange={(e) => setYear(e.target.value)} className="w-24">{[thisYear + 1, thisYear, thisYear - 1].map((y) => <option key={y}>{y}</option>)}</Select>
            <Select aria-label="Show" value={show} onChange={(e) => setShow(e.target.value)} className="w-32"><option value="open">Not yet filed</option><option value="all">All returns</option></Select>
          </div>
        }
      />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : rows.length === 0 ? <EmptyState icon={<CheckCircle2 className="size-6" />} title="Nothing left to file for this year" /> : (
        <Table caption="BIR filings">
          <thead><tr><Th>Due</Th><Th>Form</Th><Th>Return</Th><Th>Period</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
          <tbody>
            {rows.map((f) => (
              <tr key={f.id}>
                <Td className="whitespace-nowrap">{date(f.due_on)}</Td>
                <Td className="font-semibold">{f.form}</Td>
                <Td>{f.description}</Td>
                <Td className="whitespace-nowrap">{f.period}</Td>
                <Td className="whitespace-nowrap">
                  {f.status === 'filed' ? <Badge tone="success">Filed {date(f.filed_on)}</Badge> : f.status === 'not_applicable' ? <Badge>Not applicable</Badge> : f.is_overdue ? <Badge tone="danger">Overdue</Badge> : f.due_on <= today() ? <Badge tone="warning">Due today</Badge> : <Badge tone="primary">Pending</Badge>}
                  {f.reference && <div className="text-xs text-on-surface-variant">Ref. {f.reference}</div>}
                </Td>
                <Td align="right"><Button size="sm" variant="text" onClick={() => setEditing(f)}>{f.status === 'pending' ? 'Mark filed' : 'Edit'}</Button></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {editing && <FilingDialog filing={editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

function FilingDialog({ filing, onClose }: { filing: Filing; onClose: () => void }) {
  const [status, setStatus] = useState<Filing['status']>(filing.status === 'pending' ? 'filed' : filing.status)
  const [filedOn, setFiledOn] = useState(filing.filed_on ?? today())
  const [reference, setReference] = useState(filing.reference ?? '')
  const [dueOn, setDueOn] = useState(filing.due_on)
  const save = useApiMutation((input: object) => patch(`/v1/tax/filings/${filing.id}`, input), { invalidate: [['tax']], success: 'Saved', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ status, filed_on: status === 'filed' ? filedOn : null, reference: reference || null, due_on: dueOn }, { onSuccess: onClose })
  }

  return (
    <Dialog open onClose={onClose} title={`${filing.form} for ${filing.period}`} description={filing.description}
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="filing-form" loading={save.isPending}>Save</Button></>}>
      <form id="filing-form" onSubmit={submit} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label="Status">
          {(a) => (
            <Select {...a} value={status} onChange={(e) => setStatus(e.target.value as Filing['status'])}>
              <option value="filed">Filed</option>
              <option value="pending">Not yet filed</option>
              <option value="not_applicable">Not applicable to the firm</option>
            </Select>
          )}
        </Field>
        {status === 'filed' && <Field label="Filed on" error={error?.field('filed_on')}>{(a) => <Input {...a} type="date" max={today()} value={filedOn} onChange={(e) => setFiledOn(e.target.value)} />}</Field>}
        <Field label="Confirmation / payment reference" className="sm:col-span-2">{(a) => <Input {...a} value={reference} onChange={(e) => setReference(e.target.value)} placeholder="eBIRForms or eFPS confirmation no., bank reference" />}</Field>
        <Field label="Due date" hint="Change it if your filing group has a different deadline." error={error?.field('due_on')}>{(a) => <Input {...a} type="date" value={dueOn} onChange={(e) => setDueOn(e.target.value)} />}</Field>
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
      </form>
    </Dialog>
  )
}

function SetupTab() {
  const firm = useFirmSettings()
  if (firm.isPending) return <PageLoader />
  if (firm.isError) return <ErrorState error={firm.error} />
  return <SetupForm initial={firm.data} />
}

function SetupForm({ initial }: { initial: NonNullable<ReturnType<typeof useFirmSettings>['data']> }) {
  const save = useSaveFirmSettings()
  const [form, setForm] = useState({ taxpayer_type: initial.taxpayer_type, withholding_atc: initial.withholding_atc, has_employees: initial.has_employees })
  const error = save.error ? ApiError.from(save.error) : null

  return (
    <Card>
      <CardHeader title="Tax profile" description={`TIN ${initial.tin ?? 'not set'} · ${initial.vat_registered ? 'VAT-registered (2550Q)' : 'non-VAT (2551Q percentage tax)'}. Change the TIN and VAT status under Firm Settings.`} />
      <form onSubmit={(e) => { e.preventDefault(); save.mutate(form) }} className="grid max-w-2xl grid-cols-1 gap-4 p-4 sm:grid-cols-2">
        <Field label="The firm files as" hint="Decides 1701Q/1701 or 1702Q/1702.">
          {(a) => (
            <Select {...a} value={form.taxpayer_type} onChange={(e) => setForm({ ...form, taxpayer_type: e.target.value as typeof form.taxpayer_type })}>
              <option value="juridical">Partnership or corporation</option>
              <option value="individual">Individual (sole practitioner)</option>
            </Select>
          )}
        </Field>
        <Field label="ATC on clients' Forms 2307" hint="The code clients use for fees paid to the firm, e.g. WC010 or WC011; WI010 or WI011 for individuals. Copy it from a 2307 you received." error={error?.field('withholding_atc')}>
          {(a) => <Input {...a} value={form.withholding_atc} maxLength={5} onChange={(e) => setForm({ ...form, withholding_atc: e.target.value.toUpperCase() })} />}
        </Field>
        <div className="sm:col-span-2"><Checkbox label="The firm has employees (1601-C monthly, 1604-C annual)" checked={form.has_employees} onChange={(e) => setForm({ ...form, has_employees: e.target.checked })} /></div>
        <div className="sm:col-span-2"><Button type="submit" loading={save.isPending}>Save</Button></div>
      </form>
    </Card>
  )
}
