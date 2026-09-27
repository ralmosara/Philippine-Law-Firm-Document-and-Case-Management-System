import { ShieldCheck, Smartphone } from 'lucide-react'
import { useState } from 'react'
import { AuthLayout } from '@/features/auth/components/AuthLayout'
import { useCurrentSession, useLogout } from '@/features/auth/session'
import { Button } from '@/shared/ui/Button'
import { EnableDialog } from './TwoFactorSettings'

/**
 * Shown instead of the app when the firm requires two-step verification and
 * this user has not set it up. The API refuses everything else until then.
 */
export function TwoFactorRequired() {
  const { firm, user } = useCurrentSession()
  const logout = useLogout()
  const [open, setOpen] = useState(false)

  return (
    <AuthLayout
      title="Set up two-step verification"
      subtitle={`${firm.name} requires it for every account.`}
      footer={
        <button type="button" className="font-medium text-primary hover:underline" onClick={() => logout.mutate()}>
          Sign out ({user.email})
        </button>
      }
    >
      <div className="flex flex-col gap-5 text-sm text-on-surface-variant">
        <p className="flex gap-3">
          <Smartphone className="size-5 shrink-0 text-primary" aria-hidden="true" />
          <span>You’ll need a phone with an authenticator app, such as Google Authenticator or Microsoft Authenticator. It takes about a minute.</span>
        </p>
        <p className="flex gap-3">
          <ShieldCheck className="size-5 shrink-0 text-primary" aria-hidden="true" />
          <span>From then on, signing in asks for a 6-digit code from the app as well as your password, so a stolen password alone can’t open client files.</span>
        </p>
        <Button className="w-full" onClick={() => setOpen(true)}>Set up now</Button>
      </div>
      {open && <EnableDialog onClose={() => setOpen(false)} />}
    </AuthLayout>
  )
}
