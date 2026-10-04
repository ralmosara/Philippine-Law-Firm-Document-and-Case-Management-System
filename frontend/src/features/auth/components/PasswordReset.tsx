import { useState, type FormEvent } from 'react'
import { MailCheck } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import { Button, ButtonLink } from '@/shared/ui/Button'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { useForgotPassword, useResetPassword } from '../session'
import { AuthLayout } from './AuthLayout'
import { t } from '@/shared/lib/i18n'

/** The same screens serve staff (`/login`) and the client portal (`/portal/login`). */
interface Props {
  portal?: boolean
}

const paths = (portal: boolean) => (portal ? { login: '/portal/login', forgot: '/portal/forgot-password' } : { login: '/login', forgot: '/forgot-password' })

function BackToSignIn({ portal }: { portal: boolean }) {
  return <Link to={paths(portal).login} className="font-medium text-primary hover:underline">{t('Back to sign in')}</Link>
}

export function ForgotPasswordPage({ portal = false }: Props) {
  const request = useForgotPassword(portal ? 'portal' : 'staff')
  const [email, setEmail] = useState('')
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await request.mutateAsync(email)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  if (request.isSuccess) {
    return (
      <AuthLayout title={t('Check your email')} footer={<BackToSignIn portal={portal} />}>
        <div className="flex flex-col items-center gap-4 text-center text-sm text-on-surface-variant">
          <MailCheck className="size-10 text-primary" aria-hidden="true" />
          <p role="status">{request.data.message}</p>
          <p>
            {t('The link expires in 60 minutes. Check your spam folder if it doesn’t arrive.')}
            {portal && ` ${t('If you are a client of more than one firm, you get a separate email for each.')}`}
          </p>
        </div>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title={portal ? t('Reset your portal password') : 'Reset your password'}
      subtitle={t('We’ll email you a link to choose a new one.')}
      footer={<BackToSignIn portal={portal} />}
    >
      <form onSubmit={submit} noValidate className="flex flex-col gap-5">
        <FormError message={error ? (error.field('email') ?? error.message) : undefined} />
        <Field label={portal ? t('Email') : 'Work email'}>
          {(a) => <Input {...a} type="email" autoComplete="username" autoFocus required value={email} onChange={(e) => setEmail(e.target.value)} />}
        </Field>
        <Button type="submit" loading={request.isPending} disabled={!email} className="w-full">{t('Send reset link')}</Button>
      </form>
    </AuthLayout>
  )
}

export function ResetPasswordPage({ portal = false }: Props) {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const client = Number(params.get('client')) || 0
  const invite = portal && params.get('invite') === '1'
  const reset = useResetPassword(portal ? 'portal' : 'staff')
  const [form, setForm] = useState({ password: '', password_confirmation: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const { login, forgot } = paths(portal)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (form.password !== form.password_confirmation) {
      setError(new ApiError(422, t('The passwords do not match.'), { password_confirmation: [t('The passwords do not match.')] }))
      return
    }
    try {
      await reset.mutateAsync({ token, ...(portal ? { client } : { email }), ...form })
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  if (!token || (portal ? !client : !email)) {
    return (
      <AuthLayout title={t('Link incomplete')} footer={<BackToSignIn portal={portal} />}>
        <p className="text-center text-sm text-on-surface-variant">{t('Open the link from your email again, or request a new one.')}</p>
        <ButtonLink to={forgot} className="mt-6 w-full">{t('Request a new link')}</ButtonLink>
      </AuthLayout>
    )
  }

  if (reset.isSuccess) {
    return (
      <AuthLayout title={invite ? t('You’re all set') : t('Password changed')}>
        <p role="status" className="text-center text-sm text-on-surface-variant">{reset.data.message}{!portal && ' You were signed out everywhere else.'}</p>
        <ButtonLink to={login} className="mt-6 w-full">{t('Sign in')}</ButtonLink>
      </AuthLayout>
    )
  }

  const linkProblem = error?.field('email') ?? error?.field('token')

  return (
    <AuthLayout
      title={invite ? t('Welcome! Choose your password') : t('Choose a new password')}
      subtitle={portal ? (invite ? t('You’ll use it to sign in to your client portal.') : undefined) : email}
      footer={<BackToSignIn portal={portal} />}
    >
      <form onSubmit={submit} noValidate className="flex flex-col gap-5">
        <FormError message={linkProblem ?? (error && !Object.keys(error.errors).length ? error.message : undefined)} />
        {linkProblem && <ButtonLink to={forgot} variant="tonal" className="w-full">{t('Request a new link')}</ButtonLink>}
        <Field label={t('New password')} required error={error?.field('password')} hint={t('At least 12 characters, with upper- and lower-case letters and a number.')}>
          {(a) => <Input {...a} type="password" autoComplete="new-password" autoFocus required value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} />}
        </Field>
        <Field label={t('Confirm new password')} required error={error?.field('password_confirmation')}>
          {(a) => <Input {...a} type="password" autoComplete="new-password" required value={form.password_confirmation} onChange={(e) => setForm((f) => ({ ...f, password_confirmation: e.target.value }))} />}
        </Field>
        <Button type="submit" loading={reset.isPending} disabled={!form.password} className="w-full">{invite ? t('Set password') : t('Change password')}</Button>
      </form>
    </AuthLayout>
  )
}

/** Route elements for the portal (lazy routes take a named, prop-less export). */
export const PortalForgotPasswordPage = () => <ForgotPasswordPage portal />
export const PortalResetPasswordPage = () => <ResetPasswordPage portal />
