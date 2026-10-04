import { useState, type FormEvent } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { money } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader, DescriptionList, PageHeader } from '@/shared/ui/Layout'
import { useChangePassword } from '../api'
import { CalendarSubscription } from './CalendarSubscription'
import { CounselCredentials } from './CounselCredentials'
import { PhoneNotifications } from './PhoneNotifications'
import { TwoFactorSettings } from './TwoFactorSettings'

export function ProfilePage() {
  const { user, firm } = useCurrentSession()
  const change = useChangePassword()
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [error, setError] = useState<ApiError | null>(null)
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await change.mutateAsync(form)
      setForm({ current_password: '', password: '', password_confirmation: '' })
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <>
      <PageHeader title="Profile" description={`${user.role_label} · ${firm.name}`} />
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Your details" description="Ask your managing partner to change your name, e-mail or rate." />
          <div className="p-5">
            <DescriptionList
              items={[
                { label: 'Name', value: user.name },
                { label: 'Email', value: user.email },
                { label: 'Roll No.', value: user.roll_number },
                { label: 'IBP No.', value: user.ibp_number },
                { label: 'Mobile', value: user.mobile_number },
                { label: 'Hourly rate', value: user.hourly_rate_cents ? money(user.hourly_rate_cents) : null },
              ]}
            />
          </div>
        </Card>
        <Card>
          <CardHeader title="Change password" />
          <form onSubmit={submit} className="flex flex-col gap-4 p-5">
            <FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} />
            <Field label="Current password" required error={error?.field('current_password')}>
              {(a) => <Input {...a} type="password" autoComplete="current-password" required value={form.current_password} onChange={set('current_password')} />}
            </Field>
            <Field label="New password" required error={error?.field('password')}>
              {(a) => <Input {...a} type="password" autoComplete="new-password" required minLength={8} value={form.password} onChange={set('password')} />}
            </Field>
            <Field label="Confirm new password" required>
              {(a) => <Input {...a} type="password" autoComplete="new-password" required value={form.password_confirmation} onChange={set('password_confirmation')} />}
            </Field>
            <Button type="submit" loading={change.isPending} className="self-start">Update password</Button>
          </form>
        </Card>
        {user.is_lawyer && (
          <div className="lg:col-span-2">
            <CounselCredentials />
          </div>
        )}
        <div className="lg:col-span-2">
          <PhoneNotifications />
        </div>
        <div className="lg:col-span-2">
          <TwoFactorSettings />
        </div>
        <div className="lg:col-span-2">
          <CalendarSubscription />
        </div>
      </div>
    </>
  )
}
