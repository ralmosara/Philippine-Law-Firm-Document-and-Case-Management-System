import { Scale } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { usePortalLogin, usePortalSession } from '../api'
import { t } from '@/shared/lib/i18n'
import { LanguageSwitcher } from '../i18n'

export function ClientLogin() {
  const session = usePortalSession()
  const login = usePortalLogin()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState(false)

  if (session.isPending) return <PageLoader />
  if (session.data) return <Navigate to="/portal" replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const result = challenge
        ? await login.mutateAsync(recovery ? { challenge, recovery_code: code } : { challenge, code })
        : await login.mutateAsync({ email, password })
      if ('two_factor' in result) {
        setChallenge(result.challenge)
        return
      }
      navigate('/portal', { replace: true })
    } catch (err) {
      const apiError = ApiError.from(err)
      setError(apiError.field('email') ?? apiError.field('code') ?? apiError.field('recovery_code') ?? apiError.message)
      // An expired or used challenge needs the password again.
      if (challenge && /sign in again/i.test(apiError.message + (apiError.field('code') ?? ''))) setChallenge(null)
    }
  }

  return (
    <main className="flex min-h-dvh items-center justify-center bg-surface-dim px-4 py-12">
      <div className="w-full max-w-md">
        <div className="rounded-[3px] border border-outline-variant bg-surface p-8 sm:p-10">
          <div className="mb-8 flex flex-col items-center text-center">
            <span className="mb-4 flex size-12 items-center justify-center rounded-[3px] bg-primary-container text-on-primary-container">
              <Scale className="size-6" aria-hidden="true" />
            </span>
            <h1 className="text-2xl font-semibold tracking-tight">{t('Client portal')}</h1>
            <p className="mt-1 text-sm text-on-surface-variant">{t('Follow your cases, documents and account with your law firm.')}</p>
          </div>
          <form onSubmit={submit} className="flex flex-col gap-5">
            <FormError message={error} />
            {challenge ? (
              <>
                <Field label={recovery ? t('Recovery code') : t('Code from your authenticator app')}>
                  {(a) => <Input {...a} inputMode={recovery ? 'text' : 'numeric'} autoComplete="one-time-code" required autoFocus value={code} onChange={(e) => setCode(e.target.value)} />}
                </Field>
                <button type="button" className="self-end text-sm font-medium text-primary hover:underline" onClick={() => { setRecovery(!recovery); setCode('') }}>
                  {recovery ? t('Use the app code instead') : t('Lost your phone? Use a recovery code')}
                </button>
                <Button type="submit" loading={login.isPending} className="w-full">{t('Verify')}</Button>
              </>
            ) : (
              <>
                <Field label={t('Email')}>{(a) => <Input {...a} type="email" autoComplete="username" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />}</Field>
                <Field label={t('Password')}>{(a) => <Input {...a} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />}</Field>
                <Link to="/portal/forgot-password" className="self-end text-sm font-medium text-primary hover:underline">{t('Forgot password?')}</Link>
                <Button type="submit" loading={login.isPending} className="w-full">{t('Sign in')}</Button>
              </>
            )}
          </form>
          <p className="mt-6 text-center text-xs text-on-surface-variant">{t('No account? Your lawyer can give you portal access.')}</p>
        </div>
        <div className="mt-6 flex justify-center text-on-surface-variant"><LanguageSwitcher signedIn={false} /></div>
        <p className="mt-4 text-center text-sm text-on-surface-variant">
          {t('Firm staff?')} <Link to="/login" className="font-medium text-primary hover:underline">{t('Sign in here')}</Link>
        </p>
      </div>
    </main>
  )
}
