import { useState, type FormEvent } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { Button } from '@/shared/ui/Button'
import { Field, FormError, Input } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'

/**
 * The details printed under a lawyer's signature on pleadings (Rule 7,
 * Sec. 3). PTR and IBP numbers change every year, so lawyers update them.
 */
export function CounselCredentials() {
  const { user } = useCurrentSession()
  const save = useApiMutation((input: Record<string, string | null>) => put('/v1/auth/credentials', input), {
    invalidate: [['session']],
    success: 'Saved. New pleadings will use these details.',
    toastErrors: false,
  })
  const [form, setForm] = useState({
    roll_number: user.roll_number ?? '',
    ibp_number: user.ibp_number ?? '',
    ptr_number: user.ptr_number ?? '',
    mcle_compliance_number: user.mcle_compliance_number ?? '',
  })
  const error = save.error ? ApiError.from(save.error) : null
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate(Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v.trim() || null])))
  }

  return (
    <Card>
      <CardHeader title="Signature details for pleadings" description="Printed under your name on pleadings you sign. Renew PTR and IBP numbers each January." />
      <form onSubmit={submit} className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
        <Field label="Roll of Attorneys No." error={error?.field('roll_number')}>{(a) => <Input {...a} value={form.roll_number} onChange={set('roll_number')} />}</Field>
        <Field label="IBP No." hint="Number, date and chapter" error={error?.field('ibp_number')}>{(a) => <Input {...a} value={form.ibp_number} onChange={set('ibp_number')} placeholder="123456, 01/05/2026, Makati" />}</Field>
        <Field label="PTR No." hint="Number, date and place" error={error?.field('ptr_number')}>{(a) => <Input {...a} value={form.ptr_number} onChange={set('ptr_number')} placeholder="7654321, 01/06/2026, Makati City" />}</Field>
        <Field label="MCLE Compliance No." error={error?.field('mcle_compliance_number')}>{(a) => <Input {...a} value={form.mcle_compliance_number} onChange={set('mcle_compliance_number')} placeholder="VIII-0012345, valid until 04/14/2028" />}</Field>
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : null} /></div>
        <Button type="submit" loading={save.isPending} className="self-start">Save</Button>
      </form>
    </Card>
  )
}
