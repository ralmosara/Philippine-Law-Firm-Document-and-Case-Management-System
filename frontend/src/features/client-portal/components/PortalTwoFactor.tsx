import { useQueryClient } from '@tanstack/react-query'
import { ShieldCheck, ShieldOff } from 'lucide-react'
import QRCode from 'qrcode'
import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, post } from '@/shared/api/axios'
import { t } from '@/shared/lib/i18n'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge } from '@/shared/ui/Feedback'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import type { PortalClient } from '../api'

/**
 * Set up two-step sign-in: password, then scan the QR code with an
 * authenticator app and enter its code, then keep the recovery codes.
 */
function SetupSteps({ onDone }: { onDone: () => void }) {
  const queryClient = useQueryClient()
  const [password, setPassword] = useState('')
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [qr, setQr] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!setup) return
    let cancelled = false
    QRCode.toDataURL(setup.otpauth_url, { margin: 1, width: 200 }).then((url) => !cancelled && setQr(url))
    return () => { cancelled = true }
  }, [setup])

  const run = async (fn: () => Promise<void>) => {
    setBusy(true)
    setError(null)
    try {
      await fn()
    } catch (err) {
      const e = ApiError.from(err)
      setError(e.field('password') ?? e.field('code') ?? e.message)
    } finally {
      setBusy(false)
    }
  }

  if (codes) {
    return (
      <div className="flex flex-col gap-4 text-sm">
        <p className="font-medium">{t('Two-step sign-in is on.')}</p>
        <p>{t('Keep these recovery codes somewhere safe. Each one lets you sign in once if you lose your phone.')}</p>
        <ul className="grid grid-cols-2 gap-2 rounded-[3px] bg-surface-container p-4 font-mono">{codes.map((c) => <li key={c}>{c}</li>)}</ul>
        <Button onClick={async () => { await queryClient.invalidateQueries({ queryKey: ['portal'] }); onDone() }}>{t('I have saved them')}</Button>
      </div>
    )
  }

  if (!setup) {
    return (
      <form className="flex flex-col gap-4" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(async () => setSetup(await post('/portal/two-factor', { password }))) }}>
        <Field label={t('Your portal password')}>{(a) => <Input {...a} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />}</Field>
        <FormError message={error} />
        <Button type="submit" loading={busy}>{t('Continue')}</Button>
      </form>
    )
  }

  return (
    <form className="flex flex-col gap-4 text-sm" onSubmit={(e: FormEvent) => { e.preventDefault(); void run(async () => setCodes((await post<{ recovery_codes: string[] }>('/portal/two-factor/confirm', { code })).recovery_codes)) }}>
      <p>{t('Scan this with an authenticator app (Google Authenticator, Microsoft Authenticator or similar), then enter the 6-digit code it shows.')}</p>
      {qr && <img src={qr} alt={t('QR code for your authenticator app')} className="size-48 self-center" />}
      <p className="text-xs text-on-surface-variant">{t('Cannot scan? Enter this key in the app:')} <span className="font-mono break-all">{setup.secret}</span></p>
      <Field label={t('Code from the app')}>{(a) => <Input {...a} inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)} />}</Field>
      <FormError message={error} />
      <Button type="submit" loading={busy}>{t('Turn on')}</Button>
    </form>
  )
}

/** Shown instead of the portal when the firm requires two-step sign-in and it is not set up yet. */
export function TwoFactorRequired({ firmName }: { firmName: string }) {
  return (
    <Card className="mx-auto max-w-lg">
      <CardHeader title={t('Set up two-step sign-in')} description={t('{firm} asks every client to use two-step sign-in, to protect your case documents and account. It takes a minute.', { firm: firmName })} />
      <div className="p-5"><SetupSteps onDone={() => {}} /></div>
    </Card>
  )
}

/** On the "My data" page: turn two-step sign-in on or off. */
export function TwoFactorCard({ client }: { client: PortalClient }) {
  const toast = useToast()
  const queryClient = useQueryClient()
  const [enabling, setEnabling] = useState(false)
  const [disabling, setDisabling] = useState(false)
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const disable = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await post('/portal/two-factor/disable', { password })
      await queryClient.invalidateQueries({ queryKey: ['portal'] })
      toast.success(t('Two-step sign-in is off.'))
      setDisabling(false)
    } catch (err) {
      setError(ApiError.from(err).field('password') ?? ApiError.from(err).message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader
        title={t('Two-step sign-in')}
        description={t('Ask for a code from an authenticator app after your password, so a stolen password alone is not enough.')}
        actions={client.two_factor_enabled ? <Badge tone="success">{t('On')}</Badge> : <Badge>{t('Off')}</Badge>}
      />
      <div className="p-5">
        {client.two_factor_enabled
          ? client.two_factor_required
            ? <p className="text-sm text-on-surface-variant">{t('Required by your lawyers.')}</p>
            : <Button variant="text" icon={<ShieldOff className="size-4" />} onClick={() => setDisabling(true)}>{t('Turn off')}</Button>
          : <Button icon={<ShieldCheck className="size-4" />} onClick={() => setEnabling(true)}>{t('Turn on')}</Button>}
      </div>
      {enabling && <Dialog open onClose={() => setEnabling(false)} title={t('Set up two-step sign-in')}><SetupSteps onDone={() => setEnabling(false)} /></Dialog>}
      {disabling && (
        <Dialog open onClose={() => setDisabling(false)} title={t('Turn off two-step sign-in?')}>
          <form className="flex flex-col gap-4" onSubmit={disable}>
            <Field label={t('Your portal password')}>{(a) => <Input {...a} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />}</Field>
            <FormError message={error} />
            <Button type="submit" variant="danger" loading={busy}>{t('Turn off')}</Button>
          </form>
        </Dialog>
      )}
    </Card>
  )
}
