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

  if (session.isPending) return <PageLoader />
  if (session.data) return <Navigate to="/portal" replace />

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await login.mutateAsync({ email, password })
      navigate('/portal', { replace: true })
    } catch (err) {
      const apiError = ApiError.from(err)
      setError(apiError.field('email') ?? apiError.message)
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
            <Field label={t('Email')}>{(a) => <Input {...a} type="email" autoComplete="username" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />}</Field>
            <Field label={t('Password')}>{(a) => <Input {...a} type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />}</Field>
            <Link to="/portal/forgot-password" className="self-end text-sm font-medium text-primary hover:underline">{t('Forgot password?')}</Link>
            <Button type="submit" loading={login.isPending} className="w-full">{t('Sign in')}</Button>
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
