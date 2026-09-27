import { zodResolver } from '@hookform/resolvers/zod'
import { useState, type FormEvent } from 'react'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { z } from 'zod'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { useLogin, useSession, useTwoFactorChallenge } from '../session'
import { AuthLayout } from './AuthLayout'

const schema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(1, 'Enter your password.'),
  remember: z.boolean(),
})

type Values = z.infer<typeof schema>

/** Only allow redirects to in-app paths, never to another origin. */
function safeNext(next: string | null): string {
  return next && next.startsWith('/') && !next.startsWith('//') ? next : '/'
}

export function LoginForm() {
  const session = useSession()
  const login = useLogin()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const next = safeNext(params.get('next'))
  const [challenge, setChallenge] = useState<string | null>(null)

  const { register, handleSubmit, formState, setError } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '', remember: false },
  })

  if (session.isPending) return <PageLoader />
  if (session.data) return <Navigate to={next} replace />

  if (challenge) {
    return <TwoFactorStep challenge={challenge} onDone={() => navigate(next, { replace: true })} onRestart={() => setChallenge(null)} />
  }

  const onSubmit = handleSubmit(async (values) => {
    try {
      const result = await login.mutateAsync(values)
      if ('two_factor' in result) setChallenge(result.challenge)
      else navigate(next, { replace: true })
    } catch (error) {
      const apiError = ApiError.from(error)
      setError('root', { message: apiError.field('email') ?? apiError.message })
    }
  })

  return (
    <AuthLayout
      title="Sign in"
      subtitle="to continue to Lex PH"
      footer={
        <>
          Are you a client?{' '}
          <Link to="/portal/login" className="font-medium text-primary hover:underline">Sign in to the client portal</Link>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
        <FormError message={formState.errors.root?.message} />

        <Field label="Email" error={formState.errors.email?.message}>
          {(a) => <Input {...a} type="email" autoComplete="username" autoFocus {...register('email')} />}
        </Field>

        <Field label="Password" error={formState.errors.password?.message}>
          {(a) => <Input {...a} type="password" autoComplete="current-password" {...register('password')} />}
        </Field>

        <div className="flex flex-wrap items-center justify-between gap-2">
          <Checkbox label="Keep me signed in on this device" {...register('remember')} />
          <Link to="/forgot-password" className="text-sm font-medium text-primary hover:underline">Forgot password?</Link>
        </div>

        <Button type="submit" loading={formState.isSubmitting} className="mt-2 w-full">
          Sign in
        </Button>
      </form>
    </AuthLayout>
  )
}

function TwoFactorStep({ challenge, onDone, onRestart }: { challenge: string; onDone: () => void; onRestart: () => void }) {
  const verify = useTwoFactorChallenge()
  const [useRecovery, setUseRecovery] = useState(false)
  const [value, setValue] = useState('')
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await verify.mutateAsync(useRecovery ? { challenge, recovery_code: value.trim() } : { challenge, code: value.replace(/\s/g, '') })
      onDone()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  const message = error ? (error.field('code') ?? error.field('recovery_code') ?? error.message) : undefined
  const expired = !!message && /sign in again/i.test(message)

  return (
    <AuthLayout
      title="Two-step verification"
      subtitle={useRecovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
    >
      <form onSubmit={submit} noValidate className="flex flex-col gap-5">
        <FormError message={message} />
        {expired ? (
          <Button onClick={onRestart} className="w-full">Back to sign in</Button>
        ) : (
          <>
            <Field label={useRecovery ? 'Recovery code' : 'Authentication code'}>
              {(a) => (
                <Input
                  {...a}
                  key={useRecovery ? 'recovery' : 'code'}
                  value={value}
                  onChange={(e) => setValue(e.target.value)}
                  autoFocus
                  autoComplete="one-time-code"
                  inputMode={useRecovery ? 'text' : 'numeric'}
                  placeholder={useRecovery ? 'abcde-fghij' : '123 456'}
                  maxLength={useRecovery ? 32 : 7}
                  className="text-center font-mono text-lg tracking-widest"
                />
              )}
            </Field>
            <Button type="submit" loading={verify.isPending} disabled={!value.trim()} className="w-full">Verify</Button>
            <div className="flex flex-wrap justify-between gap-2 text-sm">
              <button type="button" className="font-medium text-primary hover:underline" onClick={() => { setUseRecovery(!useRecovery); setValue(''); setError(null) }}>
                {useRecovery ? 'Use authenticator code' : 'Lost your phone? Use a recovery code'}
              </button>
              <button type="button" className="text-on-surface-variant hover:underline" onClick={onRestart}>Cancel</button>
            </div>
          </>
        )}
      </form>
    </AuthLayout>
  )
}
