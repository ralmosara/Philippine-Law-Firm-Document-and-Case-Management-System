import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { applyServerErrors } from '@/shared/api/hooks'
import type { Client } from '@/shared/api/types'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { useSaveClient } from '../api'

const schema = z.object({
  type: z.enum(['individual', 'corporate']),
  name: z.string().trim().min(1, 'Enter the client’s name.').max(255),
  email: z.union([z.literal(''), z.email('Enter a valid email address.')]),
  phone: z.string().trim().max(30),
  tin: z.union([z.literal(''), z.string().regex(/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/, 'Use the format 123-456-789-000.')]),
  address: z.string().trim().max(255),
  notes: z.string().max(5000),
})

type Values = z.infer<typeof schema>
const FIELDS = Object.keys(schema.shape)

export function ClientForm({ open, onClose, client }: { open: boolean; onClose: () => void; client?: Client }) {
  const save = useSaveClient(client?.id)
  const navigate = useNavigate()

  const { register, handleSubmit, formState, setError, reset, watch } = useForm<Values>({
    resolver: zodResolver(schema),
    values: {
      type: client?.type ?? 'individual',
      name: client?.name ?? '',
      email: client?.email ?? '',
      phone: client?.phone ?? '',
      tin: client?.tin ?? '',
      address: client?.address ?? '',
      notes: client?.notes ?? '',
    },
  })

  const close = () => {
    reset()
    onClose()
  }

  const onSubmit = handleSubmit(async (values) => {
    try {
      const nullable = (v: string) => (v.trim() === '' ? null : v)
      const saved = await save.mutateAsync({
        ...values,
        email: nullable(values.email),
        phone: nullable(values.phone),
        tin: nullable(values.tin),
        address: nullable(values.address),
        notes: nullable(values.notes),
      })
      close()
      if (!client) navigate(`/clients/${saved.id}`)
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  return (
    <Dialog
      open={open}
      onClose={close}
      title={client ? 'Edit client' : 'New client'}
      description={client ? undefined : 'Tip: run a conflict check on the name first (Compliance → Conflict checks).'}
      footer={
        <>
          <Button variant="text" onClick={close}>Cancel</Button>
          <Button type="submit" form="client-form" loading={formState.isSubmitting}>Save client</Button>
        </>
      }
    >
      <form id="client-form" onSubmit={onSubmit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={formState.errors.root?.message} /></div>
        <Field label="Type" required>
          {(a) => (
            <Select {...a} {...register('type')}>
              <option value="individual">Individual</option>
              <option value="corporate">Corporation / juridical entity</option>
            </Select>
          )}
        </Field>
        <Field label={watch('type') === 'corporate' ? 'Registered name' : 'Full name'} required error={formState.errors.name?.message}>
          {(a) => <Input {...a} autoComplete="off" {...register('name')} />}
        </Field>
        <Field label="Email" error={formState.errors.email?.message} hint="Needed for client portal access.">
          {(a) => <Input {...a} type="email" {...register('email')} />}
        </Field>
        <Field label="Phone" error={formState.errors.phone?.message}>
          {(a) => <Input {...a} type="tel" placeholder="0917 123 4567" {...register('phone')} />}
        </Field>
        <Field label="TIN" error={formState.errors.tin?.message}>
          {(a) => <Input {...a} placeholder="123-456-789-000" {...register('tin')} />}
        </Field>
        <Field label="Address" error={formState.errors.address?.message}>
          {(a) => <Input {...a} {...register('address')} />}
        </Field>
        <Field label="Notes" className="sm:col-span-2" error={formState.errors.notes?.message}>
          {(a) => <Textarea {...a} rows={3} {...register('notes')} />}
        </Field>
      </form>
    </Dialog>
  )
}
