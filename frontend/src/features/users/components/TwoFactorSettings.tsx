import { useQueryClient } from '@tanstack/react-query'
import { Copy, Download, ShieldCheck, ShieldOff } from 'lucide-react'
import QRCode from 'qrcode'
import { useEffect, useState, type FormEvent } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Badge } from '@/shared/ui/Feedback'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import { useConfirmTwoFactor, useDisableTwoFactor, useRegenerateRecoveryCodes, useStartTwoFactor } from '../api'

type Flow = 'enable' | 'disable' | 'codes' | null

/** Profile card: turn authenticator-app sign-in on or off, and manage recovery codes. */
export function TwoFactorSettings() {
  const { user, firm } = useCurrentSession()
  const [flow, setFlow] = useState<Flow>(null)

  return (
    <Card>
      <CardHeader
        title="Two-step verification"
        description="Ask for a code from an authenticator app when you sign in, so a stolen password alone is not enough."
        actions={user.two_factor_enabled ? <Badge tone="success">On</Badge> : <Badge tone="warning">Off</Badge>}
      />
      <div className="flex flex-wrap gap-2 p-5">
        {user.two_factor_enabled ? (
          <>
            <Button variant="tonal" onClick={() => setFlow('codes')}>New recovery codes</Button>
            {firm.require_two_factor ? (
              <p className="self-center text-sm text-on-surface-variant">Required by your firm.</p>
            ) : (
              <Button variant="text" icon={<ShieldOff className="size-4" />} onClick={() => setFlow('disable')}>Turn off</Button>
            )}
          </>
        ) : (
          <Button icon={<ShieldCheck className="size-4" />} onClick={() => setFlow('enable')}>Turn on</Button>
        )}
      </div>

      {flow === 'enable' && <EnableDialog onClose={() => setFlow(null)} />}
      {flow === 'disable' && <PasswordDialog title="Turn off two-step verification?" confirmLabel="Turn off" destructive onClose={() => setFlow(null)} mode="disable" />}
      {flow === 'codes' && <PasswordDialog title="Generate new recovery codes" confirmLabel="Generate" onClose={() => setFlow(null)} mode="codes" />}
    </Card>
  )
}

export function EnableDialog({ onClose }: { onClose: () => void }) {
  const start = useStartTwoFactor()
  const confirm = useConfirmTwoFactor()
  const queryClient = useQueryClient()
  const [password, setPassword] = useState('')
  const [code, setCode] = useState('')
  const [qr, setQr] = useState<string | null>(null)
  const [error, setError] = useState<ApiError | null>(null)

  const setup = start.data
  useEffect(() => {
    if (!setup) return
    let cancelled = false
    QRCode.toDataURL(setup.otpauth_url, { margin: 1, width: 200 }).then((url) => !cancelled && setQr(url))
    return () => {
      cancelled = true
    }
  }, [setup])

  if (confirm.data) {
    const done = () => {
      onClose()
      void queryClient.invalidateQueries({ queryKey: ['session'] })
    }
    return <RecoveryCodesDialog codes={confirm.data.recovery_codes} onClose={done} />
  }

  const submitPassword = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await start.mutateAsync(password)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  const submitCode = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await confirm.mutateAsync(code.replace(/\s/g, ''))
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  if (!setup) {
    return (
      <Dialog open onClose={onClose} title="Turn on two-step verification" description="Confirm it’s you first.">
        <form onSubmit={submitPassword} className="flex flex-col gap-4">
          <Field label="Your password" error={error?.field('password') ?? (error && !Object.keys(error.errors).length ? error.message : undefined)}>
            {(a) => <Input {...a} type="password" autoComplete="current-password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />}
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="text" onClick={onClose}>Cancel</Button>
            <Button type="submit" loading={start.isPending} disabled={!password}>Continue</Button>
          </div>
        </form>
      </Dialog>
    )
  }

  return (
    <Dialog open onClose={onClose} title="Scan with your authenticator app" description="Google Authenticator, Microsoft Authenticator, 1Password and similar apps work.">
      <form onSubmit={submitCode} className="flex flex-col gap-4">
        <div className="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
          <div className="flex size-[200px] shrink-0 items-center justify-center rounded-[3px] bg-white p-2">
            {qr ? <img src={qr} alt="QR code for your authenticator app" width={184} height={184} /> : null}
          </div>
          <div className="min-w-0 text-sm text-on-surface-variant">
            <p>Can’t scan? Enter this key instead:</p>
            <p className="mt-1 font-mono text-sm break-all text-on-surface select-all">{setup.secret.match(/.{1,4}/g)?.join(' ')}</p>
          </div>
        </div>
        <FormError message={error ? (error.field('code') ?? error.message) : undefined} />
        <Field label="6-digit code from the app">
          {(a) => <Input {...a} autoFocus inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} className="font-mono tracking-widest" />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={confirm.isPending} disabled={code.replace(/\s/g, '').length !== 6}>Turn on</Button>
        </div>
      </form>
    </Dialog>
  )
}

function PasswordDialog({ title, confirmLabel, destructive, mode, onClose }: { title: string; confirmLabel: string; destructive?: boolean; mode: 'disable' | 'codes'; onClose: () => void }) {
  const disable = useDisableTwoFactor()
  const regenerate = useRegenerateRecoveryCodes()
  const [password, setPassword] = useState('')
  const [error, setError] = useState<ApiError | null>(null)
  const pending = disable.isPending || regenerate.isPending

  if (regenerate.data) return <RecoveryCodesDialog codes={regenerate.data.recovery_codes} onClose={onClose} />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      if (mode === 'disable') {
        await disable.mutateAsync(password)
        onClose()
      } else {
        await regenerate.mutateAsync(password)
      }
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={title}
      description={mode === 'disable' ? 'Sign-in will only need your password.' : 'Your old recovery codes will stop working.'}
    >
      <form onSubmit={submit} className="flex flex-col gap-4">
        <Field label="Your password" error={error?.field('password') ?? (error && !Object.keys(error.errors).length ? error.message : undefined)}>
          {(a) => <Input {...a} type="password" autoComplete="current-password" autoFocus value={password} onChange={(e) => setPassword(e.target.value)} />}
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant={destructive ? 'danger' : 'filled'} loading={pending} disabled={!password}>{confirmLabel}</Button>
        </div>
      </form>
    </Dialog>
  )
}

function RecoveryCodesDialog({ codes, onClose }: { codes: string[]; onClose: () => void }) {
  const toast = useToast()
  const text = codes.join('\n')

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text)
      toast.success('Recovery codes copied')
    } catch {
      toast.error('Copy failed; select the codes and copy them manually.')
    }
  }

  const download = () => {
    const url = URL.createObjectURL(new Blob([`Lex PH recovery codes\n\n${text}\n`], { type: 'text/plain' }))
    const link = Object.assign(document.createElement('a'), { href: url, download: 'lex-ph-recovery-codes.txt' })
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="Save your recovery codes"
      description="Each code signs you in once if you lose your phone. Keep them somewhere safe; they won’t be shown again."
      footer={<Button onClick={onClose}>I’ve saved them</Button>}
    >
      <ul className="grid grid-cols-2 gap-2 rounded-[3px] bg-surface-container p-4 font-mono text-sm">
        {codes.map((c) => <li key={c}>{c}</li>)}
      </ul>
      <div className="mt-4 flex gap-2">
        <Button variant="tonal" size="sm" icon={<Copy className="size-4" />} onClick={copy}>Copy</Button>
        <Button variant="tonal" size="sm" icon={<Download className="size-4" />} onClick={download}>Download</Button>
      </div>
    </Dialog>
  )
}
