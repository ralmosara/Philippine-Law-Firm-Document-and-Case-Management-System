import { CalendarClock, ChevronRight, CreditCard, FileDown, Landmark, PenLine, ReceiptText, X } from 'lucide-react'
import { useEffect } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import { date, dateTime, money } from '@/shared/lib/format'
import { Button, ButtonLink, IconButton } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { FormError } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Td, Th } from '@/shared/ui/Layout'
import { usePayInvoice, usePortalInvoices, usePortalMatters, usePortalSession, usePortalSignatureRequests, usePortalTrust } from '../api'
import { DocumentRequestsBanner } from './PortalDocumentRequests'
import { t } from '@/shared/lib/i18n'
import { CalendarCard } from './PortalExperience'
import { PaymentProofControls } from './PortalPaymentProof'

export function ClientDashboard() {
  const session = usePortalSession()
  const matters = usePortalMatters()
  const invoices = usePortalInvoices()
  const trust = usePortalTrust()
  const pay = usePayInvoice()
  const trustTotal = trust.data?.reduce((s, a) => s + a.balance_cents, 0) ?? 0

  return (
    <>
      <PageHeader title={t('Welcome, {name}', { name: session.data?.name ?? '' })} description={t('Here is where your matters stand today.')} />

      <PaymentReturnBanner />
      <SignatureRequests />
      <DocumentRequestsBanner />
      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatCard label={t('Amount due')} value={money(invoices.data?.outstanding_cents)} tone={invoices.data?.data.some((i) => i.is_overdue) ? 'danger' : undefined} detail={invoices.data?.data.some((i) => i.is_overdue) ? t('An invoice is past due') : undefined} />
        <StatCard label={t('Held for you in trust')} value={money(trustTotal)} detail={t('Deposits for fees and costs, held separately from the firm’s funds')} />
      </div>

      <section aria-labelledby="matters-heading" className="mb-8">
        <h2 id="matters-heading" className="mb-3 text-lg font-semibold">{t('Your matters')}</h2>
        {matters.isPending ? (
          <PageLoader />
        ) : matters.isError ? (
          <ErrorState error={matters.error} />
        ) : matters.data.length === 0 ? (
          <Card><EmptyState title={t('No matters yet')} /></Card>
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {matters.data.map((m) => (
              <Link key={m.id} to={`/portal/matters/${m.id}`} className="group rounded-(--radius-card) border border-outline-variant bg-surface p-5 transition-colors hover:border-primary">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-semibold group-hover:text-primary">{m.title}</p>
                    <p className="text-sm text-on-surface-variant">{m.case_type}{m.case_number && ` · ${m.case_number}`}</p>
                  </div>
                  <ChevronRight className="size-5 text-on-surface-variant" aria-hidden="true" />
                </div>
                <div className="mt-4 mb-1 flex justify-between text-sm">
                  <span>{t('Stage:')} <strong>{m.status_label}</strong></span>
                </div>
                <ProgressBar value={m.progress} label={t('{title} progress', { title: m.title })} tone={m.status === 'closed' ? 'success' : 'primary'} />
                {m.next_hearing && (
                  <p className="mt-4 flex items-center gap-2 text-sm text-on-surface-variant">
                    <CalendarClock className="size-4" aria-hidden="true" />
                    {t('Next hearing:')} {date(m.next_hearing.date)}{m.next_hearing.time && ` ${t('at {time}', { time: m.next_hearing.time })}`}
                  </p>
                )}
                {m.lawyer && <p className="mt-1 text-sm text-on-surface-variant">{t('Your lawyer:')} {m.lawyer.name}</p>}
              </Link>
            ))}
          </div>
        )}
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title={t('Invoices')} description={invoices.data?.data.some((i) => i.can_pay_online) ? t('Pay by card, GCash, Maya or QR Ph through PayMongo.') : undefined} actions={<a href="/api/portal/statement/pdf" download className="inline-flex items-center gap-1 text-sm text-primary hover:underline"><FileDown className="size-4" aria-hidden />{t('Statement of account')}</a>} />
          {pay.isError && <div className="px-5 pt-3"><FormError message={ApiError.from(pay.error).message} /></div>}
          {invoices.isPending ? <PageLoader /> : !invoices.data?.data.length ? <EmptyState icon={<ReceiptText className="size-6" />} title={t('No invoices')} /> : (
            <>
            {/* Phones: one card per invoice, so the amount and the Pay button are never cut off. */}
            <ul className="divide-y divide-outline-variant sm:hidden">
              {invoices.data.data.map((i) => (
                <li key={i.id} className="flex flex-col gap-2 px-4 py-3 text-sm">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="font-medium">{i.number} <a href={`/api/portal/invoices/${i.id}/pdf`} download className="ml-1 text-xs font-normal text-primary hover:underline">PDF</a></p>
                      {i.matter && <p className="text-xs text-on-surface-variant">{i.matter.title}</p>}
                    </div>
                    <p className="shrink-0 text-right font-medium tabular-nums">{money(i.total_cents)}</p>
                  </div>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="flex items-center gap-2">
                      {i.status === 'paid' ? <Badge tone="success">{t('Paid')}</Badge> : i.is_overdue ? <Badge tone="danger">{t('Overdue')}</Badge> : i.status === 'partially_paid' ? <Badge tone="primary">{t('Partly paid')}</Badge> : <Badge tone="warning">{t('Unpaid')}</Badge>}
                      <span className="text-xs text-on-surface-variant">{t('Due')} {date(i.due_at)}</span>
                    </span>
                    {i.status === 'partially_paid' && <span className="text-xs text-on-surface-variant">{t('Balance')} {money(i.balance_cents)}</span>}
                  </div>
                  <PaymentProofControls invoice={i} />
                  {i.can_pay_online && (
                    <Button icon={<CreditCard className="size-4" />} className="h-11 w-full" loading={pay.isPending && pay.variables === i.id} disabled={pay.isPending} onClick={() => pay.mutate(i.id)}>
                      {i.status === 'partially_paid' ? t('Pay {amount}', { amount: money(i.balance_cents) }) : t('Pay')}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
            <div className="hidden sm:block">
            <Table caption={t('Invoices')} compact>
              <thead><tr><Th>{t('Number')}</Th><Th>{t('Due')}</Th><Th>{t('Status')}</Th><Th align="right">{t('Amount')}</Th></tr></thead>
              <tbody>
                {invoices.data.data.map((i) => (
                  <tr key={i.id}>
                    <Td className="font-medium">{i.number} <a href={`/api/portal/invoices/${i.id}/pdf`} download className="ml-1 text-xs font-normal text-primary hover:underline">PDF</a><div className="text-xs font-normal text-on-surface-variant">{i.matter?.title}</div></Td>
                    <Td>{date(i.due_at)}</Td>
                    <Td>{i.status === 'paid' ? <Badge tone="success">{t('Paid')}</Badge> : i.is_overdue ? <Badge tone="danger">{t('Overdue')}</Badge> : i.status === 'partially_paid' ? <Badge tone="primary">{t('Partly paid')}</Badge> : <Badge tone="warning">{t('Unpaid')}</Badge>}</Td>
                    <Td align="right">
                      {money(i.total_cents)}
                      {i.status === 'partially_paid' && <div className="text-xs text-on-surface-variant">{t('Balance')} {money(i.balance_cents)}</div>}
                      {i.can_pay_online && (
                        <div className="mt-1">
                          <Button size="sm" icon={<CreditCard className="size-4" />} loading={pay.isPending && pay.variables === i.id} disabled={pay.isPending} onClick={() => pay.mutate(i.id)}>
                            {i.status === 'partially_paid' ? t('Pay {amount}', { amount: money(i.balance_cents) }) : t('Pay')}
                          </Button>
                        </div>
                      )}
                      <PaymentProofControls invoice={i} />
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            </div>
            </>
          )}
        </Card>

        <Card>
          <CardHeader title={t('Trust account activity')} />
          {trust.isPending ? <PageLoader /> : !trust.data?.length ? <EmptyState icon={<Landmark className="size-6" />} title={t('No trust deposits')} /> : (
            <ul className="divide-y divide-outline-variant">
              {trust.data.flatMap((a) => a.transactions.slice(0, 5).map((t) => ({ ...t, account: a.account_number }))).slice(0, 8).map((t) => (
                <li key={t.id} className="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                  <span>
                    <span className="block">{t.description}</span>
                    <span className="block text-xs text-on-surface-variant">{date(t.date)} · {t.account}</span>
                  </span>
                  <span className={t.type === 'deposit' ? 'font-medium text-success tabular-nums' : 'tabular-nums'}>
                    {t.type === 'deposit' ? '+' : '−'}{money(t.amount_cents)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <CalendarCard />
    </>
  )
}

/** Documents the firm has asked this client to sign. */
function SignatureRequests() {
  const requests = usePortalSignatureRequests()
  if (!requests.data?.length) return null

  return (
    <Card className="mb-6 border-primary">
      <CardHeader title={t('Waiting for your signature')} description={t('Review each document and sign it online.')} />
      <ul className="divide-y divide-outline-variant">
        {requests.data.map((r) => (
          <li key={r.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
            <PenLine className="hidden size-5 shrink-0 text-primary sm:block" aria-hidden="true" />
            <div className="min-w-0 flex-1">
              <p className="font-medium">{r.document.title}</p>
              <p className="text-sm text-on-surface-variant">
                {[r.matter?.title, r.requested_by && t('from {name}', { name: r.requested_by }), r.expires_at && t('respond by {date}', { date: dateTime(r.expires_at) })].filter(Boolean).join(' · ')}
              </p>
            </div>
            <ButtonLink to={`/portal/sign/${r.id}`} size="sm">{t('Review and sign')}</ButtonLink>
          </li>
        ))}
      </ul>
    </Card>
  )
}

/**
 * Shown after returning from PayMongo. The return itself proves nothing; the
 * invoice turns "Paid" when PayMongo's confirmation reaches the firm, so
 * refresh the invoice list for a little while.
 */
function PaymentReturnBanner() {
  const [params, setParams] = useSearchParams()
  const outcome = params.get('payment')
  const invoices = usePortalInvoices()
  const invoiceId = Number(params.get('invoice'))
  const { refetch } = invoices
  const paid = invoices.data?.data.find((i) => i.id === invoiceId)?.status === 'paid'

  useEffect(() => {
    if (outcome !== 'success' || paid) return
    const timer = window.setInterval(() => void refetch(), 4000)
    const stop = window.setTimeout(() => window.clearInterval(timer), 60_000)
    return () => {
      window.clearInterval(timer)
      window.clearTimeout(stop)
    }
  }, [outcome, paid, refetch])

  if (outcome !== 'success' && outcome !== 'cancelled') return null

  const dismiss = () => setParams((p) => { p.delete('payment'); p.delete('invoice'); return p }, { replace: true })
  const text =
    outcome === 'cancelled'
      ? t('Payment was cancelled. Nothing was charged.')
      : paid
        ? t('Payment received. Thank you!')
        : t('Thank you. We’re confirming your payment with PayMongo; this usually takes a few seconds.')

  return (
    <div role="status" className={`mb-6 flex items-start gap-3 rounded-[3px] p-4 text-sm ${outcome === 'cancelled' ? 'bg-surface-container-high' : 'bg-success-container text-on-success-container'}`}>
      <p className="flex-1">{text}</p>
      <IconButton label={t('Dismiss')} onClick={dismiss}><X className="size-4" /></IconButton>
    </div>
  )
}
