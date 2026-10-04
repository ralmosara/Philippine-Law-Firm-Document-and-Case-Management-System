import { LogOut, MessagesSquare, Scale, ShieldCheck } from 'lucide-react'
import { Navigate, Outlet, useNavigate } from 'react-router-dom'
import { Button, ButtonLink } from '@/shared/ui/Button'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { usePortalLogout, usePortalSession, usePortalThreads } from '../api'
import { PrivacyConsentGate } from './PortalPrivacy'
import { t } from '@/shared/lib/i18n'
import { LanguageSwitcher } from '../i18n'

/** Shell for the client portal: a separate, read-only surface with its own session. */
export function ClientPortalDashboard() {
  const session = usePortalSession()
  const logout = usePortalLogout()
  const unread = usePortalThreads(undefined, !!session.data).data?.unread ?? 0
  const navigate = useNavigate()

  if (session.isPending) return <PageLoader />
  if (session.isError) return <div className="p-8"><ErrorState error={session.error} onRetry={() => session.refetch()} /></div>
  if (!session.data) return <Navigate to="/portal/login" replace />
  const client = session.data

  return (
    <div className="min-h-dvh">
      <header className="bg-nav text-on-nav shadow-[0_1px_3px_rgb(0_0_0/0.25)]">
        <div className="mx-auto flex h-12 max-w-5xl items-center gap-3 px-4">
          <span className="flex size-9 items-center justify-center rounded-[3px] bg-primary text-on-primary"><Scale className="size-5" aria-hidden="true" /></span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold">{client.firm.name}</p>
            <p className="truncate text-xs opacity-75">{t('Client portal')} · {client.name}</p>
          </div>
          <LanguageSwitcher signedIn className="hidden sm:inline" />
          <ButtonLink to="/portal/messages" variant="text" className="!text-on-nav hover:!bg-nav-hover hover:no-underline" icon={<MessagesSquare className="size-4" />} aria-label={unread > 0 ? t('Messages, {count} unread', { count: unread }) : t('Messages')}>
            <span className="hidden sm:inline" aria-hidden="true">{t('Messages')}</span>
            {unread > 0 && <span className="rounded-full bg-primary px-2 text-xs font-semibold text-on-primary" aria-hidden="true">{unread}</span>}
          </ButtonLink>
          <ButtonLink to="/portal/privacy" variant="text" className="!text-on-nav hover:!bg-nav-hover hover:no-underline" icon={<ShieldCheck className="size-4" />} aria-label={t('My data')}>
            <span className="hidden sm:inline" aria-hidden="true">{t('My data')}</span>
          </ButtonLink>
          <Button variant="text" className="!text-on-nav hover:!bg-nav-hover hover:no-underline" icon={<LogOut className="size-4" />} aria-label={t('Sign out')} onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/portal/login', { replace: true }) })}>
            <span className="hidden sm:inline" aria-hidden="true">{t('Sign out')}</span>
          </Button>
        </div>
      </header>
      <main id="main" className="mx-auto max-w-5xl px-4 py-8">
        <PrivacyConsentGate firmName={client.firm.name}>
          <Outlet />
        </PrivacyConsentGate>
      </main>
      <footer className="mx-auto max-w-5xl px-4 pb-8 text-xs text-on-surface-variant">
        <LanguageSwitcher signedIn className="mb-3 block sm:hidden" />
        {t('Questions? Contact {firm}', { firm: client.firm.name })}{client.firm.phone && ` ${t('at {phone}', { phone: client.firm.phone })}`}{client.firm.email && ` ${t('or {email}', { email: client.firm.email })}`}. {t('Information here is confidential and privileged.')}
      </footer>
    </div>
  )
}
