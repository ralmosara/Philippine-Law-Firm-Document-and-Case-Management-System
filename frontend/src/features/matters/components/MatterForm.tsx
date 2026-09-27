import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Trash2 } from 'lucide-react'
import { useFieldArray, useForm, useWatch } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { useLookups } from '@/features/auth/session'
import { useClientOptions } from '@/features/clients/api'
import { useLawyerOptions } from '@/features/users/api'
import { applyServerErrors } from '@/shared/api/hooks'
import type { Matter } from '@/shared/api/types'
import { toCents } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { useSaveMatter } from '../api'

const optional = z.string().trim().max(255).optional().or(z.literal(''))
const pesos = z.string().trim().refine((v) => v === '' || (toCents(v) >= 0 && toCents(v) <= 2_000_000_000), 'Enter an amount in pesos, up to ₱20,000,000.')

const schema = z.object({
  client_id: z.coerce.number<string>().int().positive('Choose a client.'),
  title: z.string().trim().min(1, 'Enter a title, e.g. "Santos v. Reyes".').max(255),
  case_type: z.string().min(1, 'Choose a case type.'),
  case_number: optional,
  court: optional,
  court_branch: optional,
  judge: optional,
  responsible_lawyer_id: z.string().optional(),
  description: z.string().max(10000).optional(),
  parties: z.array(z.object({ role: z.string(), name: z.string().trim().min(1, 'Enter a name.'), counsel_name: optional })).max(50),
  fee_arrangement: z.enum(['hourly', 'flat', 'retainer', 'contingency', 'pro_bono']),
  fixed_fee: pesos,
  acceptance_fee: pesos,
  appearance_fee: pesos,
  contingency_percent: z.string().trim().refine((v) => v === '' || (Number(v) > 0 && Number(v) <= 100), 'Enter a percentage from 0 to 100.'),
  client_role: z.enum(['plaintiff', 'defendant', 'petitioner', 'respondent', 'complainant', 'accused', 'appellant', 'appellee']),
  nature_of_action: optional,
  retainer_auto_bill: z.boolean(),
  retainer_billing_day: z.coerce.number<string>().int().min(1).max(28),
  retainer_auto_issue: z.boolean(),
})

type Values = z.input<typeof schema>
const FIELDS = ['client_id', 'title', 'case_type', 'case_number', 'court', 'court_branch', 'judge', 'responsible_lawyer_id', 'description', 'parties', 'fee_arrangement'] as const

const pesoText = (cents: number | null | undefined) => (cents ? (cents / 100).toFixed(2) : '')
const centsOrNull = (value: string) => (value.trim() === '' ? null : toCents(value))

interface Props {
  open: boolean
  onClose: () => void
  matter?: Matter
  defaultClientId?: number
}

/** Open a new matter (with its opposing parties) or edit an existing one. */
export function MatterForm({ open, onClose, matter, defaultClientId }: Props) {
  const lookups = useLookups()
  const clients = useClientOptions(open)
  const lawyers = useLawyerOptions(open)
  const save = useSaveMatter(matter?.id)
  const navigate = useNavigate()

  const { register, control, handleSubmit, formState, setError, reset } = useForm<Values, unknown, z.output<typeof schema>>({
    resolver: zodResolver(schema),
    values: {
      client_id: String(matter?.client_id ?? defaultClientId ?? ''),
      title: matter?.title ?? '',
      case_type: matter?.case_type ?? '',
      case_number: matter?.case_number ?? '',
      court: matter?.court ?? '',
      court_branch: matter?.court_branch ?? '',
      judge: matter?.judge ?? '',
      responsible_lawyer_id: matter?.responsible_lawyer?.id ? String(matter.responsible_lawyer.id) : '',
      description: matter?.description ?? '',
      parties: [],
      fee_arrangement: matter?.fee_arrangement ?? 'hourly',
      fixed_fee: pesoText(matter?.fixed_fee_cents),
      acceptance_fee: pesoText(matter?.acceptance_fee_cents),
      appearance_fee: pesoText(matter?.appearance_fee_cents),
      contingency_percent: matter?.contingency_basis_points ? String(matter.contingency_basis_points / 100) : '',
      client_role: (matter?.client_role ?? 'plaintiff') as Values['client_role'],
      nature_of_action: matter?.nature_of_action ?? '',
      retainer_auto_bill: matter?.retainer_auto_bill ?? false,
      retainer_billing_day: String(matter?.retainer_billing_day ?? 1),
      retainer_auto_issue: matter?.retainer_auto_issue ?? false,
    },
  })
  const parties = useFieldArray({ control, name: 'parties' })
  const arrangement = useWatch({ control, name: 'fee_arrangement' })

  const close = () => {
    reset()
    onClose()
  }

  const onSubmit = handleSubmit(async ({ responsible_lawyer_id, parties: newParties, fixed_fee, acceptance_fee, appearance_fee, contingency_percent, ...values }) => {
    try {
      const saved = await save.mutateAsync({
        ...values,
        fixed_fee_cents: values.fee_arrangement === 'flat' || values.fee_arrangement === 'retainer' ? centsOrNull(fixed_fee) : null,
        acceptance_fee_cents: values.fee_arrangement === 'pro_bono' ? null : centsOrNull(acceptance_fee),
        appearance_fee_cents: values.fee_arrangement === 'pro_bono' ? null : centsOrNull(appearance_fee),
        contingency_basis_points: values.fee_arrangement === 'contingency' && contingency_percent ? Math.round(Number(contingency_percent) * 100) : null,
        // On create, a blank lawyer means "me" (decided by the server); on edit it means unassigned.
        ...(responsible_lawyer_id ? { responsible_lawyer_id: Number(responsible_lawyer_id) } : matter ? { responsible_lawyer_id: null } : {}),
        ...(matter ? {} : { parties: newParties }),
      })
      close()
      if (!matter) navigate(`/matters/${saved.id}`)
    } catch (error) {
      applyServerErrors(error, setError, FIELDS)
    }
  })

  return (
    <Dialog open={open} onClose={close} title={matter ? 'Edit matter' : 'Open a new matter'} size="lg"
      description={matter ? matter.reference : 'Run a conflict check before accepting a new engagement.'}
      footer={
        <>
          <Button variant="text" onClick={close}>Cancel</Button>
          <Button type="submit" form="matter-form" loading={formState.isSubmitting}>{matter ? 'Save changes' : 'Open matter'}</Button>
        </>
      }
    >
      <form id="matter-form" onSubmit={onSubmit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={formState.errors.root?.message} /></div>

        {matter ? (
          <div className="sm:col-span-2">
            <input type="hidden" {...register('client_id')} />
            <p className="text-sm font-medium">Client</p>
            <p className="text-sm text-on-surface-variant">{matter.client?.name}</p>
          </div>
        ) : (
          <Field label="Client" required error={formState.errors.client_id?.message} className="sm:col-span-2">
            {(a) => (
              <Select {...a} {...register('client_id')}>
                <option value="">Select a client…</option>
                {clients.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </Select>
            )}
          </Field>
        )}

        <Field label="Title" required error={formState.errors.title?.message} className="sm:col-span-2">
          {(a) => <Input {...a} placeholder="Santos v. Reyes" {...register('title')} />}
        </Field>

        <Field label="Case type" required error={formState.errors.case_type?.message}>
          {(a) => (
            <Select {...a} {...register('case_type')}>
              <option value="">Select…</option>
              {lookups.data?.case_types.map((t) => <option key={t} value={t}>{t}</option>)}
            </Select>
          )}
        </Field>

        <Field label="Responsible lawyer" error={formState.errors.responsible_lawyer_id?.message} hint={matter ? undefined : 'Defaults to you if you are a lawyer.'}>
          {(a) => (
            <Select {...a} {...register('responsible_lawyer_id')}>
              <option value="">{matter ? 'Unassigned' : 'Me'}</option>
              {lawyers.data?.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          )}
        </Field>

        <Field label="Court" error={formState.errors.court?.message}>
          {(a) => <Input {...a} placeholder="Regional Trial Court" {...register('court')} />}
        </Field>
        <Field label="Branch" error={formState.errors.court_branch?.message}>
          {(a) => <Input {...a} placeholder="Branch 58, Makati City" {...register('court_branch')} />}
        </Field>
        <Field label="Case / docket number" error={formState.errors.case_number?.message} hint="Once filed.">
          {(a) => <Input {...a} placeholder="R-MKT-CV-26-00123" {...register('case_number')} />}
        </Field>
        <Field label="Presiding judge" error={formState.errors.judge?.message}>
          {(a) => <Input {...a} {...register('judge')} />}
        </Field>
        <Field label="Our client is the" hint="Sets the caption of pleadings.">
          {(a) => (
            <Select {...a} {...register('client_role')}>
              {['plaintiff', 'defendant', 'petitioner', 'respondent', 'complainant', 'accused', 'appellant', 'appellee'].map((r) => <option key={r} value={r}>{r.charAt(0).toUpperCase() + r.slice(1)}</option>)}
            </Select>
          )}
        </Field>
        <Field label="Nature of the action" hint='Shown as "For: …" in the caption.' error={formState.errors.nature_of_action?.message}>
          {(a) => <Input {...a} placeholder="Sum of Money and Damages" {...register('nature_of_action')} />}
        </Field>

        <Field label="Description" error={formState.errors.description?.message} className="sm:col-span-2">
          {(a) => <Textarea {...a} rows={3} {...register('description')} />}
        </Field>

        <fieldset className="grid grid-cols-1 gap-4 border-t border-outline-variant pt-4 sm:col-span-2 sm:grid-cols-2">
          <legend className="sr-only">Fees</legend>
          <Field label="Fee arrangement" error={formState.errors.fee_arrangement?.message} hint="From the engagement letter; suggests invoice lines.">
            {(a) => (
              <Select {...a} {...register('fee_arrangement')}>
                {lookups.data?.fee_arrangements.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            )}
          </Field>
          {(arrangement === 'flat' || arrangement === 'retainer') && (
            <Field label={arrangement === 'flat' ? 'Flat fee (total, ₱)' : 'Monthly retainer (₱)'} error={formState.errors.fixed_fee?.message}>
              {(a) => <Input {...a} inputMode="decimal" placeholder="0.00" {...register('fixed_fee')} />}
            </Field>
          )}
          {arrangement === 'retainer' && (
            <div className="flex flex-col gap-3 rounded-[3px] bg-surface-container p-3 sm:col-span-2">
              <Checkbox label="Bill this retainer automatically every month" {...register('retainer_auto_bill')} />
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Field label="Billing day" hint="1 to 28" error={formState.errors.retainer_billing_day?.message}>
                  {(a) => <Input {...a} type="number" min={1} max={28} {...register('retainer_billing_day')} />}
                </Field>
                <div className="sm:pt-7">
                  <Checkbox label="Issue and e-mail it to the client (otherwise left as a draft to review)" {...register('retainer_auto_issue')} />
                </div>
              </div>
            </div>
          )}
          {arrangement === 'contingency' && (
            <Field label="Contingency (% of recovery)" error={formState.errors.contingency_percent?.message} hint="Must be reasonable (Code of Professional Responsibility and Accountability).">
              {(a) => <Input {...a} inputMode="decimal" placeholder="25" {...register('contingency_percent')} />}
            </Field>
          )}
          {arrangement !== 'pro_bono' && (
            <>
              <Field label="Acceptance fee (₱)" error={formState.errors.acceptance_fee?.message}>
                {(a) => <Input {...a} inputMode="decimal" placeholder="0.00" {...register('acceptance_fee')} />}
              </Field>
              <Field label="Appearance fee per hearing (₱)" error={formState.errors.appearance_fee?.message}>
                {(a) => <Input {...a} inputMode="decimal" placeholder="0.00" {...register('appearance_fee')} />}
              </Field>
            </>
          )}
        </fieldset>

        {!matter && (
          <fieldset className="sm:col-span-2">
            <legend className="mb-2 text-sm font-medium">Opposing & other parties</legend>
            <p className="mb-3 text-xs text-on-surface-variant">Recorded parties are included in future conflict-of-interest searches.</p>
            <div className="flex flex-col gap-2">
              {parties.fields.map((field, index) => (
                <div key={field.id} className="grid grid-cols-[1fr_1.5fr_auto] items-start gap-2">
                  <Select aria-label="Party role" {...register(`parties.${index}.role`)}>
                    {lookups.data?.party_roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </Select>
                  <div>
                    <Input aria-label="Party name" placeholder="Full name or entity" {...register(`parties.${index}.name`)} aria-invalid={!!formState.errors.parties?.[index]?.name} />
                    {formState.errors.parties?.[index]?.name && <p className="mt-1 text-xs text-danger">{formState.errors.parties[index]?.name?.message}</p>}
                  </div>
                  <IconButton label="Remove party" onClick={() => parties.remove(index)}><Trash2 className="size-4" /></IconButton>
                </div>
              ))}
            </div>
            <Button variant="text" size="sm" className="mt-2" icon={<Plus className="size-4" />} onClick={() => parties.append({ role: 'adverse_party', name: '', counsel_name: '' })}>
              Add party
            </Button>
          </fieldset>
        )}
      </form>
    </Dialog>
  )
}
