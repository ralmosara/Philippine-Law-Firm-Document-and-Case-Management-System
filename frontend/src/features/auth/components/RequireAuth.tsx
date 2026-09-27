import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import type { Abilities } from '@/shared/api/types'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { TwoFactorRequired } from '@/features/users/components/TwoFactorRequired'
import { useSession } from '../session'

/** Gate a route tree behind a staff session. */
export function RequireAuth({ children }: { children: ReactNode }) {
  const session = useSession()
  const location = useLocation()

  if (session.isPending) return <PageLoader label="Checking your session…" />
  if (session.isError) return <div className="p-8"><ErrorState error={session.error} onRetry={() => session.refetch()} /></div>
  if (!session.data) {
    return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />
  }

  if (session.data.firm.require_two_factor && !session.data.user.two_factor_enabled) {
    return <TwoFactorRequired />
  }

  return <>{children}</>
}

/** Gate a page behind an ability; the API enforces the same rule. */
export function RequireAbility({ ability, children }: { ability: keyof Abilities; children: ReactNode }) {
  const session = useSession()

  if (!session.data?.abilities[ability]) {
    return <EmptyState title="You don't have access to this page" description="Ask your managing partner if you need this permission." />
  }

  return <>{children}</>
}
