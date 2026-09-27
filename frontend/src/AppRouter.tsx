import { lazy, Suspense, type ComponentType, type ReactNode } from 'react'
import { createBrowserRouter, Link, Outlet, useRouteError } from 'react-router-dom'
import { AppShell } from './app/AppShell'
import { RequireAbility, RequireAuth } from './features/auth/components/RequireAuth'
import { LoginForm } from './features/auth/components/LoginForm'
import { useUnauthorizedListener } from './features/auth/session'
import { ClientLogin } from './features/client-portal/components/ClientLogin'
import { ClientPortalDashboard } from './features/client-portal/components/ClientPortalDashboard'
import { ErrorState, PageLoader } from './shared/ui/Feedback'
import type { Abilities } from './shared/api/types'

/** Code-split each page; the shell and auth screens load eagerly. */
function page<K extends string>(loader: () => Promise<Record<K, ComponentType>>, name: K) {
  const Component = lazy(async () => ({ default: (await loader())[name] as ComponentType }))
  return (
    <Suspense fallback={<PageLoader />}>
      <Component />
    </Suspense>
  )
}

const gated = (ability: keyof Abilities, element: ReactNode) => <RequireAbility ability={ability}>{element}</RequireAbility>

function Root() {
  useUnauthorizedListener()
  return <Outlet />
}

function RouteError() {
  const error = useRouteError()
  return (
    <div className="mx-auto max-w-lg p-8">
      <ErrorState error={error} onRetry={() => window.location.reload()} />
    </div>
  )
}

function NotFound() {
  return (
    <div className="flex flex-col items-center gap-3 py-24 text-center">
      <p className="text-5xl font-semibold text-primary">404</p>
      <p className="text-on-surface-variant">This page doesn’t exist.</p>
      <Link to="/" className="font-medium text-primary hover:underline">Go home</Link>
    </div>
  )
}

export const router = createBrowserRouter([
  {
    element: <Root />,
    errorElement: <RouteError />,
    children: [
      { path: '/login', element: <LoginForm /> },
      { path: '/forgot-password', element: page(() => import('./features/auth/components/PasswordReset'), 'ForgotPasswordPage') },
      { path: '/reset-password', element: page(() => import('./features/auth/components/PasswordReset'), 'ResetPasswordPage') },
      { path: '/portal/login', element: <ClientLogin /> },
      { path: '/consult/:slug', element: page(() => import('./features/intake/components/PublicIntakePage'), 'PublicIntakePage') },
      { path: '/portal/forgot-password', element: page(() => import('./features/auth/components/PasswordReset'), 'PortalForgotPasswordPage') },
      { path: '/portal/reset-password', element: page(() => import('./features/auth/components/PasswordReset'), 'PortalResetPasswordPage') },
      {
        path: '/portal',
        element: <ClientPortalDashboard />,
        children: [
          { index: true, element: page(() => import('./features/client-portal/components/ClientDashboard'), 'ClientDashboard') },
          { path: 'matters/:id', element: page(() => import('./features/client-portal/components/CaseStatusView'), 'CaseStatusView') },
          { path: 'sign/:id', element: page(() => import('./features/client-portal/components/SignDocument'), 'SignDocument') },
          { path: 'messages', element: page(() => import('./features/client-portal/components/PortalMessages'), 'PortalMessages') },
          { path: 'messages/:id', element: page(() => import('./features/client-portal/components/PortalMessages'), 'PortalMessages') },
          { path: 'privacy', element: page(() => import('./features/client-portal/components/PortalPrivacy'), 'PortalPrivacyPage') },
          { path: 'requests/:id', element: page(() => import('./features/client-portal/components/PortalDocumentRequests'), 'PortalDocumentRequest') },
        ],
      },
      {
        element: (
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        ),
        children: [
          { index: true, element: page(() => import('./features/dashboard/components/HomePage'), 'HomePage') },
          { path: 'matters', element: page(() => import('./features/matters/components/MattersList'), 'MattersList') },
          { path: 'matters/:id', element: page(() => import('./features/matters/components/MatterDetail'), 'MatterDetail') },
          { path: 'clients', element: page(() => import('./features/clients/components/ClientsList'), 'ClientsList') },
          { path: 'clients/:id', element: page(() => import('./features/clients/components/ClientDetail'), 'ClientDetail') },
          { path: 'calendar', element: page(() => import('./features/deadlines/components/DeadlineCalendar'), 'DeadlineCalendar') },
          { path: 'court-day', element: gated('work_matters', page(() => import('./features/deadlines/components/CourtDay'), 'CourtDay')) },
          { path: 'tasks', element: page(() => import('./features/deadlines/components/TaskBoard'), 'TaskBoard') },
          { path: 'intake', element: gated('work_matters', page(() => import('./features/intake/components/IntakeInbox'), 'IntakeInbox')) },
          { path: 'intake/:id', element: gated('work_matters', page(() => import('./features/intake/components/IntakeInbox'), 'IntakeReview')) },
          { path: 'messages', element: page(() => import('./features/messages/components/MessagesInbox'), 'MessagesInbox') },
          { path: 'messages/:id', element: page(() => import('./features/messages/components/MessagesInbox'), 'MessagesInbox') },
          { path: 'documents', element: page(() => import('./features/documents/components/DocumentsDashboard'), 'DocumentsDashboard') },
          { path: 'documents/:id', element: page(() => import('./features/documents/components/DocumentEditor'), 'DocumentEditor') },
          { path: 'billing', element: page(() => import('./features/billing/components/BillingDashboard'), 'BillingDashboard') },
          { path: 'billing/invoices/:id', element: gated('practice_law', page(() => import('./features/billing/components/InvoiceDetail'), 'InvoiceDetail')) },
          { path: 'trust', element: gated('work_matters', page(() => import('./features/trust/components/TrustLedgerDashboard'), 'TrustLedgerDashboard')) },
          { path: 'trust/:id', element: gated('work_matters', page(() => import('./features/trust/components/TrustAccountDetail'), 'TrustAccountDetail')) },
          { path: 'compliance', element: page(() => import('./features/compliance/components/ComplianceDashboard'), 'ComplianceDashboard') },
          { path: 'reports', element: gated('manage_finances', page(() => import('./features/reports/components/ReportsPage'), 'ReportsPage')) },
          { path: 'tax', element: gated('manage_finances', page(() => import('./features/tax/components/TaxPage'), 'TaxPage')) },
          { path: 'privacy', element: gated('manage_firm', page(() => import('./features/privacy/components/PrivacyPage'), 'PrivacyPage')) },
          { path: 'settings', element: gated('manage_firm', page(() => import('./features/settings/components/SettingsPage'), 'SettingsPage')) },
          { path: 'profile', element: page(() => import('./features/users/components/ProfilePage'), 'ProfilePage') },
          { path: '*', element: <NotFound /> },
        ],
      },
    ],
  },
])
