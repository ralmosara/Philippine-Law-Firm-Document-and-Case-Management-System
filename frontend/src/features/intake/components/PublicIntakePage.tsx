import { CheckCircle2, Plus, Scale, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useParams } from 'react-router-dom'
import { ApiError } from '@/shared/api/axios'
import { Button, IconButton } from '@/shared/ui/Button'
import { EmptyState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { usePublicIntake, useSubmitIntake, type IntakeForm } from '../api'

const EMPTY: IntakeForm = {
  name: '', email: '', phone: '', client_type: 'individual', case_type: '', description: '',
  opposing_parties: [''], preferred_times: [''], consent: false, website: '',
}

/** A firm's public "request a consultation" page: /consult/:slug. */
export function PublicIntakePage() {
  const slug = useParams().slug ?? ''
  const page = usePublicIntake(slug)
  const submit = useSubmitIntake(slug)
  const [form, setForm] = useState<IntakeForm>(EMPTY)
  const [error, setError] = useState<ApiError | null>(null)
  const set = <K extends keyof IntakeForm>(key: K, value: IntakeForm[K]) => setForm((f) => ({ ...f, [key]: value }))
  const setListItem = (key: 'opposing_parties' | 'preferred_times', index: number, value: string) =>
    setForm((f) => ({ ...f, [key]: f[key].map((v, i) => (i === index ? value : v)) }))

  if (page.isPending) return <PageLoader />
  if (page.isError) {
    return <main className="mx-auto max-w-lg px-4 py-24"><EmptyState title="This page isn’t available" description="The firm may not be accepting online requests. Please contact them directly." /></main>
  }
  const { firm, message, case_types } = page.data

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await submit.mutateAsync({
        ...form,
        opposing_parties: form.opposing_parties.map((p) => p.trim()).filter(Boolean),
        // datetime-local values are Philippine time as typed; send them as-is.
        preferred_times: form.preferred_times.filter(Boolean),
      })
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  const firstError = (prefix: string) => Object.entries(error?.errors ?? {}).find(([k]) => k.startsWith(prefix))?.[1][0]

  return (
    <main className="min-h-dvh bg-surface-dim px-4 py-10">
      <div className="mx-auto max-w-2xl">
        <header className="mb-6 flex items-center gap-3">
          <span className="flex size-11 items-center justify-center rounded-2xl bg-primary text-on-primary"><Scale className="size-6" aria-hidden="true" /></span>
          <div>
            <h1 className="text-xl font-semibold tracking-tight">{firm.name}</h1>
            <p className="text-sm text-on-surface-variant">{[firm.address, firm.phone, firm.email].filter(Boolean).join(' · ')}</p>
          </div>
        </header>

        <div className="rounded-3xl border border-outline-variant bg-surface p-6 sm:p-8">
          {submit.isSuccess ? (
            <div role="status" className="flex flex-col items-center gap-3 py-8 text-center">
              <CheckCircle2 className="size-12 text-success" aria-hidden="true" />
              <h2 className="text-lg font-semibold">Request sent</h2>
              <p className="max-w-md text-sm text-on-surface-variant">{submit.data.message} A confirmation was sent to {form.email}.</p>
            </div>
          ) : (
            <>
              <h2 className="text-lg font-semibold">Request a consultation</h2>
              <p className="mt-1 mb-6 text-sm text-on-surface-variant">{message || 'Tell us about your concern and when you are available. We will email you to confirm a schedule.'}</p>

              <form onSubmit={onSubmit} noValidate className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>

                {/* Honeypot: invisible to people, tempting to bots. */}
                <div aria-hidden="true" className="absolute -left-[10000px] h-px w-px overflow-hidden">
                  <label>Website <input tabIndex={-1} autoComplete="off" value={form.website} onChange={(e) => set('website', e.target.value)} /></label>
                </div>

                <Field label="Full name" required error={error?.field('name')}>
                  {(a) => <Input {...a} autoComplete="name" required value={form.name} onChange={(e) => set('name', e.target.value)} />}
                </Field>
                <Field label="I am" required>
                  {(a) => (
                    <Select {...a} value={form.client_type} onChange={(e) => set('client_type', e.target.value as IntakeForm['client_type'])}>
                      <option value="individual">An individual</option>
                      <option value="corporate">Representing a company</option>
                    </Select>
                  )}
                </Field>
                <Field label="Email" required error={error?.field('email')}>
                  {(a) => <Input {...a} type="email" autoComplete="email" required value={form.email} onChange={(e) => set('email', e.target.value)} />}
                </Field>
                <Field label="Mobile number" error={error?.field('phone')}>
                  {(a) => <Input {...a} type="tel" autoComplete="tel" placeholder="0917 123 4567" value={form.phone} onChange={(e) => set('phone', e.target.value)} />}
                </Field>
                <Field label="Type of concern" required error={error?.field('case_type')} className="sm:col-span-2">
                  {(a) => (
                    <Select {...a} required value={form.case_type} onChange={(e) => set('case_type', e.target.value)}>
                      <option value="">Choose…</option>
                      {case_types.map((t) => <option key={t} value={t}>{t}</option>)}
                    </Select>
                  )}
                </Field>
                <Field label="Briefly, what happened?" required error={error?.field('description')} className="sm:col-span-2" hint="A short summary is enough. Please don’t include confidential documents yet.">
                  {(a) => <Textarea {...a} rows={5} required maxLength={5000} value={form.description} onChange={(e) => set('description', e.target.value)} />}
                </Field>

                <fieldset className="sm:col-span-2">
                  <legend className="text-sm font-medium">Other people or companies involved</legend>
                  <p className="mb-2 text-xs text-on-surface-variant">For example the other party in a dispute. We check these names so we can tell you if we are able to help.</p>
                  <div className="flex flex-col gap-2">
                    {form.opposing_parties.map((p, i) => (
                      <div key={i} className="flex gap-2">
                        <Input aria-label={`Other party ${i + 1}`} value={p} onChange={(e) => setListItem('opposing_parties', i, e.target.value)} className="flex-1" />
                        {form.opposing_parties.length > 1 && <IconButton label="Remove" onClick={() => set('opposing_parties', form.opposing_parties.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>}
                      </div>
                    ))}
                  </div>
                  {form.opposing_parties.length < 10 && <Button variant="text" size="sm" className="mt-1" icon={<Plus className="size-4" />} onClick={() => set('opposing_parties', [...form.opposing_parties, ''])}>Add another</Button>}
                  {firstError('opposing_parties') && <p className="mt-1 text-xs text-danger">{firstError('opposing_parties')}</p>}
                </fieldset>

                <fieldset className="sm:col-span-2">
                  <legend className="text-sm font-medium">When are you available? (Philippine time)</legend>
                  <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                    {form.preferred_times.map((t, i) => (
                      <Input key={i} type="datetime-local" aria-label={`Preferred time ${i + 1}`} value={t} onChange={(e) => setListItem('preferred_times', i, e.target.value)} />
                    ))}
                  </div>
                  {form.preferred_times.length < 3 && <Button variant="text" size="sm" className="mt-1" icon={<Plus className="size-4" />} onClick={() => set('preferred_times', [...form.preferred_times, ''])}>Add another time</Button>}
                  {firstError('preferred_times') && <p className="mt-1 text-xs text-danger">{firstError('preferred_times')}</p>}
                </fieldset>

                <div className="sm:col-span-2">
                  <Checkbox
                    label={`I agree that ${firm.name} may process the information above to respond to my request, as provided by the Data Privacy Act of 2012. I understand this does not yet create a lawyer-client relationship.`}
                    checked={form.consent}
                    onChange={(e) => set('consent', e.target.checked)}
                  />
                  {error?.field('consent') && <p className="mt-1 text-xs text-danger">{error.field('consent')}</p>}
                </div>

                <Button type="submit" loading={submit.isPending} className="sm:col-span-2">Send request</Button>
              </form>
            </>
          )}
        </div>
      </div>
    </main>
  )
}
