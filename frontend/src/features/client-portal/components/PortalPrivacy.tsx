import { useQuery } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader } from '@/shared/ui/Layout'
import { t } from '@/shared/lib/i18n'
import { usePortalSession } from '../api'
import { TwoFactorCard } from './PortalTwoFactor'

export interface PortalPrivacy {
  notice: string
  version: number
  accepted_version: number | null
  accepted_at: string | null
  needs_acceptance: boolean
  dpo: { name: string | null; email: string | null }
  request_types: { value: string; label: string }[]
  requests: { id: number; type: string; type_label: string; details: string | null; status: 'open' | 'completed' | 'denied'; due_on: string; resolution: string | null; created_at: string }[]
}

function usePortalPrivacy(enabled = true) {
  return useQuery({ queryKey: ['portal', 'privacy'], queryFn: () => get<PortalPrivacy>('/portal/privacy'), enabled })
}

function useAcceptNotice() {
  return useApiMutation(() => post<PortalPrivacy>('/portal/privacy/accept'), { invalidate: [['portal', 'privacy']] })
}

/**
 * Shown in place of the portal until the client has read and accepted the
 * current privacy notice (again, whenever the firm changes it).
 */
export function PrivacyConsentGate({ firmName, children }: { firmName: string; children: React.ReactNode }) {
  const privacy = usePortalPrivacy()
  const accept = useAcceptNotice()

  if (privacy.isPending) return <PageLoader />
  if (privacy.isError) return <ErrorState error={privacy.error} onRetry={() => privacy.refetch()} />
  if (!privacy.data.needs_acceptance) return <>{children}</>

  return (
    <Card className="mx-auto max-w-2xl">
      <CardHeader
        title={<span className="flex items-center gap-2"><ShieldCheck className="size-5 text-primary" aria-hidden /> {t('How {firm} handles your information', { firm: firmName })}</span>}
        description={privacy.data.accepted_version ? t('Our privacy notice has changed. Please read the new version.') : t('Before you continue, please read our privacy notice.')}
      />
      <div className="max-h-[50vh] overflow-y-auto p-5 text-sm whitespace-pre-line" tabIndex={0} aria-label={t('Privacy notice')}>{privacy.data.notice}</div>
      <div className="flex flex-col gap-3 border-t border-outline-variant p-5 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-xs text-on-surface-variant">{t('Questions? Contact {firm}', { firm: privacy.data.dpo.name ?? t('our Data Protection Officer') })}{privacy.data.dpo.email && ` ${t('at {phone}', { phone: privacy.data.dpo.email })}`}.</p>
        <Button loading={accept.isPending} onClick={() => accept.mutate()}>{t('I have read this notice')}</Button>
      </div>
    </Card>
  )
}

/** "My data": the notice, and requests to see, correct or delete personal data. */
export function PortalPrivacyPage() {
  const privacy = usePortalPrivacy()
  const session = usePortalSession()
  const [type, setType] = useState('access')
  const [details, setDetails] = useState('')
  const send = useApiMutation((input: { type: string; details?: string }) => post<PortalPrivacy>('/portal/privacy/requests', input), {
    invalidate: [['portal', 'privacy']],
    success: () => t('Request sent. We will answer within 15 days.'),
    toastErrors: false,
  })
  const error = send.error ? ApiError.from(send.error) : null

  if (privacy.isPending) return <PageLoader />
  if (privacy.isError) return <ErrorState error={privacy.error} onRetry={() => privacy.refetch()} />
  const p = privacy.data

  const submit = (e: FormEvent) => {
    e.preventDefault()
    send.mutate({ type, details: details || undefined }, { onSuccess: () => setDetails('') })
  }

  return (
    <>
      <PageHeader title={t('My data')} description={t('Under the Data Privacy Act you may see, correct or delete the information we hold about you, object to how we use it, or get a copy to take elsewhere.')} />
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {session.data && <div className="lg:col-span-2"><TwoFactorCard client={session.data} /></div>}
        <Card>
          <CardHeader title={t('Make a request')} />
          <form onSubmit={submit} className="flex flex-col gap-4 p-5">
            <Field label={t('I would like to')}>
              {(a) => (
                <Select {...a} value={type} onChange={(e) => setType(e.target.value)}>
                  {p.request_types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </Select>
              )}
            </Field>
            <Field label={t('Details')} error={error?.field('details')} hint={type === 'erasure' ? t('We may need to keep some records while a case is open or the law requires it; we will explain.') : undefined}>
              {(a) => <Textarea {...a} rows={4} value={details} onChange={(e) => setDetails(e.target.value)} placeholder={type === 'correction' ? t('What should we correct?') : t('Anything we should know (optional)')} />}
            </Field>
            <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
            <Button type="submit" loading={send.isPending} className="self-start">{t('Send request')}</Button>
          </form>
        </Card>

        <Card>
          <CardHeader title={t('Your requests')} />
          {p.requests.length === 0 ? <EmptyState title={t('No requests yet')} /> : (
            <ul className="divide-y divide-outline-variant">
              {p.requests.map((r) => (
                <li key={r.id} className="p-5 text-sm">
                  <p className="flex flex-wrap items-center gap-2 font-medium">
                    {r.type_label}
                    {r.status === 'open' ? <Badge tone="warning">{t('Answer by {date}', { date: date(r.due_on) })}</Badge> : r.status === 'completed' ? <Badge tone="success">{t('Done')}</Badge> : <Badge>{t('Declined')}</Badge>}
                  </p>
                  <p className="text-xs text-on-surface-variant">{t('Sent {date}', { date: date(r.created_at) })}</p>
                  {r.details && <p className="mt-1 whitespace-pre-line">{r.details}</p>}
                  {r.resolution && <p className="mt-2 rounded-[3px] bg-surface-container p-3"><span className="font-medium">{t('Our answer:')}</span> {r.resolution}</p>}
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title={t('Privacy notice')} description={p.accepted_at ? t('Version {version}. You read it on {date}.', { version: p.version, date: date(p.accepted_at) }) : t('Version {version}.', { version: p.version })} />
          <div className="p-5 text-sm whitespace-pre-line">{p.notice}</div>
        </Card>
      </div>
    </>
  )
}
