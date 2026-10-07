import { AlertTriangle } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { Button } from '@/shared/ui/Button'
import { Checkbox, Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'

/**
 * The details printed under a lawyer's signature on pleadings (Rule 7,
 * Sec. 3). PTR and IBP dues are renewed every January; with the dates
 * recorded, the firm can tell whether they are current and remind you.
 */
export function CounselCredentials() {
  const { user } = useCurrentSession()
  const save = useApiMutation((input: Record<string, string | boolean | null>) => put('/v1/auth/credentials', input), {
    invalidate: [['session']],
    success: 'Saved. New pleadings will use these details.',
    toastErrors: false,
  })
  const [form, setForm] = useState({
    roll_number: user.roll_number ?? '',
    ibp_number: user.ibp_number ?? '',
    ibp_date: user.ibp_date ?? '',
    ibp_chapter: user.ibp_chapter ?? '',
    ptr_number: user.ptr_number ?? '',
    ptr_date: user.ptr_date ?? '',
    ptr_place: user.ptr_place ?? '',
    mcle_compliance_number: user.mcle_compliance_number ?? '',
    notarial_commission_number: user.notarial_commission_number ?? '',
    notarial_commission_place: user.notarial_commission_place ?? '',
    notarial_commission_expires_on: user.notarial_commission_expires_on ?? '',
  })
  const [lifetime, setLifetime] = useState(user.ibp_lifetime ?? false)
  const error = save.error ? ApiError.from(save.error) : null
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))
  const problems = user.credential_problems ?? []

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate({ ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() || null])), ibp_lifetime: lifetime })
  }

  return (
    <Card>
      <CardHeader title="Signature details for pleadings" description="Printed under your name on pleadings you sign. PTR and IBP dues are renewed each January; record the dates so you are reminded." />
      {problems.length > 0 && (
        <div role="status" className="mx-5 mt-5 flex gap-2 rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          <ul>{problems.map((p) => <li key={p}>{p}</li>)}</ul>
        </div>
      )}
      <form onSubmit={submit} className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-3">
        <Field label="Roll of Attorneys No." error={error?.field('roll_number')}>{(a) => <Input {...a} value={form.roll_number} onChange={set('roll_number')} />}</Field>
        <Field label="MCLE Compliance No." error={error?.field('mcle_compliance_number')} className="sm:col-span-2">{(a) => <Input {...a} value={form.mcle_compliance_number} onChange={set('mcle_compliance_number')} placeholder="VIII-0012345, valid until 04/14/2028" />}</Field>

        <Field label="PTR No." error={error?.field('ptr_number')}>{(a) => <Input {...a} value={form.ptr_number} onChange={set('ptr_number')} placeholder="7654321" />}</Field>
        <Field label="PTR date" error={error?.field('ptr_date')}>{(a) => <Input {...a} type="date" value={form.ptr_date} onChange={set('ptr_date')} />}</Field>
        <Field label="PTR place" error={error?.field('ptr_place')}>{(a) => <Input {...a} value={form.ptr_place} onChange={set('ptr_place')} placeholder="Makati City" />}</Field>

        <Field label={lifetime ? 'IBP Lifetime Member No.' : 'IBP O.R. No.'} error={error?.field('ibp_number')}>{(a) => <Input {...a} value={form.ibp_number} onChange={set('ibp_number')} placeholder="123456" />}</Field>
        {!lifetime && <Field label="IBP date paid" error={error?.field('ibp_date')}>{(a) => <Input {...a} type="date" value={form.ibp_date} onChange={set('ibp_date')} />}</Field>}
        <Field label="IBP chapter" error={error?.field('ibp_chapter')}>{(a) => <Input {...a} value={form.ibp_chapter} onChange={set('ibp_chapter')} placeholder="Makati" />}</Field>
        <Field label="Notarial commission No." hint="If you are a notary public: printed on the monthly report." error={error?.field('notarial_commission_number')}>{(a) => <Input {...a} value={form.notarial_commission_number} onChange={set('notarial_commission_number')} />}</Field>
        <Field label="Commissioned for" error={error?.field('notarial_commission_place')}>{(a) => <Input {...a} value={form.notarial_commission_place} onChange={set('notarial_commission_place')} placeholder="Makati City" />}</Field>
        <Field label="Commission valid until" error={error?.field('notarial_commission_expires_on')}>{(a) => <Input {...a} type="date" value={form.notarial_commission_expires_on} onChange={set('notarial_commission_expires_on')} />}</Field>
        <Checkbox label="IBP lifetime member (no annual dues)" checked={lifetime} onChange={(e) => setLifetime(e.target.checked)} className="sm:col-span-3" />

        <div className="sm:col-span-3"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
        <div className="sm:col-span-3"><Button type="submit" loading={save.isPending}>Save</Button></div>
      </form>
    </Card>
  )
}
