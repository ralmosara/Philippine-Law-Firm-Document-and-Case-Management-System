import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useLookups } from '@/features/auth/session'
import { applyServerErrors } from '@/shared/api/hooks'
import type { RoleValue, User } from '@/shared/api/types'
import { toCents } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Checkbox, Field, FormError, Input, Select } from '@/shared/ui/Form'
import { useSaveUser } from '../api'

const schema = (creating: boolean) =>
  z.object({
    name: z.string().trim().min(1, 'Enter a name.').max(255),
    email: z.email('Enter a valid email address.'),
    role: z.enum(['managing_partner', 'partner', 'associate', 'paralegal', 'staff']),
    password: creating ? z.string().min(8, 'At least 8 characters.') : z.union([z.literal(''), z.string().min(8, 'At least 8 characters.')]),
    roll_number: z.string().max(32),
    ibp_number: z.string().max(32),
    mobile_number: z.union([z.literal(''), z.string().regex(/^(\+63|0)9\d{9}$/, 'Use 09XXXXXXXXX or +639XXXXXXXXX.')]),
    hourly_rate: z.string().refine((v) => v === '' || Number.isFinite(toCents(v)), 'Enter an amount.'),
    daily_target: z.string().refine((v) => v === '' || (Number.isFinite(Number(v)) && Number(v) >= 0 && Number(v) <= 12), 'Enter hours from 0 to 12.'),
    is_active: z.boolean(),
  })

type Values = z.infer<ReturnType<typeof schema>>
const FIELDS = ['name', 'email', 'role', 'password', 'roll_number', 'ibp_number', 'mobile_number', 'hourly_rate', 'daily_target', 'is_active'] as const

export function UserForm({ open, onClose, user }: { open: boolean; onClose: () => void; user?: User }) {
  const lookups = useLookups()
  const save = useSaveUser(user?.id)

  const { register, handleSubmit, formState, setError, reset } = useForm<Values>({
    resolver: zodResolver(schema(!user)),
    values: {
      name: user?.name ?? '',
      email: user?.email ?? '',
      role: user?.role ?? 'associate',
      password: '',
      roll_number: user?.roll_number ?? '',
      ibp_number: user?.ibp_number ?? '',
      mobile_number: user?.mobile_number ?? '',
      hourly_rate: user ? String(user.hourly_rate_cents / 100) : '',
      daily_target: user?.daily_target_minutes == null ? '' : String(user.daily_target_minutes / 60),
      is_active: user?.is_active ?? true,
    },
  })

  const close = () => {
    reset()
    onClose()
  }

  const onSubmit = handleSubmit(async ({ hourly_rate, daily_target, password, ...values }) => {
    try {
      await save.mutateAsync({
        ...values,
        role: values.role as RoleValue,
        ...(password ? { password } : {}),
        roll_number: values.roll_number || null,
        ibp_number: values.ibp_number || null,
        mobile_number: values.mobile_number || null,
        hourly_rate_cents: hourly_rate ? toCents(hourly_rate) : 0,
        daily_target_minutes: daily_target === '' ? null : Math.round(Number(daily_target) * 60),
      })
      close()
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  return (
    <Dialog open={open} onClose={close} title={user ? 'Edit user' : 'Add user'} size="lg" footer={<><Button variant="text" onClick={close}>Cancel</Button><Button type="submit" form="user-form" loading={formState.isSubmitting}>Save</Button></>}>
      <form id="user-form" onSubmit={onSubmit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={formState.errors.root?.message} /></div>
        <Field label="Full name" required error={formState.errors.name?.message}>
          {(a) => <Input {...a} placeholder="Atty. Juan Dela Cruz" {...register('name')} />}
        </Field>
        <Field label="Email" required error={formState.errors.email?.message}>
          {(a) => <Input {...a} type="email" {...register('email')} />}
        </Field>
        <Field label="Role" required error={formState.errors.role?.message}>
          {(a) => <Select {...a} {...register('role')}>{lookups.data?.roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}</Select>}
        </Field>
        <Field label={user ? 'New password' : 'Temporary password'} required={!user} error={formState.errors.password?.message} hint={user ? 'Leave blank to keep the current password.' : 'Ask them to change it after first sign-in.'}>
          {(a) => <Input {...a} type="password" autoComplete="new-password" {...register('password')} />}
        </Field>
        <Field label="Roll of Attorneys No." error={formState.errors.roll_number?.message}>
          {(a) => <Input {...a} {...register('roll_number')} />}
        </Field>
        <Field label="IBP No." error={formState.errors.ibp_number?.message}>
          {(a) => <Input {...a} {...register('ibp_number')} />}
        </Field>
        <Field label="Mobile (for SMS alerts)" error={formState.errors.mobile_number?.message}>
          {(a) => <Input {...a} type="tel" placeholder="09171234567" {...register('mobile_number')} />}
        </Field>
        <Field label="Standard hourly rate (₱)" error={formState.errors.hourly_rate?.message}>
          {(a) => <Input {...a} inputMode="decimal" placeholder="3,500.00" {...register('hourly_rate')} />}
        </Field>
        <Field label="Daily time target (hours)" error={formState.errors.daily_target?.message} hint="Blank follows the firm's target; 0 leaves them out of time reminders.">
          {(a) => <Input {...a} inputMode="decimal" placeholder="Firm's target" {...register('daily_target')} />}
        </Field>
        {user && <Checkbox label="Active (can sign in)" className="sm:col-span-2" {...register('is_active')} />}
      </form>
    </Dialog>
  )
}
