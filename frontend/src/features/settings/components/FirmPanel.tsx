import { Copy, ShieldCheck } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { useCurrentSession } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { useToast } from '@/shared/ui/Toast'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useFirmSettings, useSaveFirmSettings, type FirmSettings } from '../api'

/** The firm's details (printed on invoices) and firm-wide security rules. */
export function FirmPanel() {
  const firm = useFirmSettings()

  if (firm.isPending) return <PageLoader />
  if (firm.isError) return <ErrorState error={firm.error} onRetry={() => firm.refetch()} />

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <ProfileCard firm={firm.data} />
      <SecurityCard firm={firm.data} />
      <IntakeCard firm={firm.data} />
      <AssistantCard firm={firm.data} />
    </div>
  )
}

function ProfileCard({ firm }: { firm: FirmSettings }) {
  const save = useSaveFirmSettings()
  const [form, setForm] = useState({ name: firm.name, tin: firm.tin ?? '', address: firm.address ?? '', email: firm.email ?? '', phone: firm.phone ?? '', vat_registered: firm.vat_registered, default_withholding_bps: firm.default_withholding_bps })
  const [error, setError] = useState<ApiError | null>(null)
  const set = (key: 'name' | 'tin' | 'address' | 'email' | 'phone') => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await save.mutateAsync({ ...form, tin: form.tin || null, address: form.address || null, email: form.email || null, phone: form.phone || null })
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Card>
      <CardHeader title="Firm details" description="Shown on billing statements and in the client portal." />
      <form onSubmit={submit} className="flex flex-col gap-4 p-5">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} />
        <Field label="Firm name" required error={error?.field('name')}>{(a) => <Input {...a} required value={form.name} onChange={set('name')} />}</Field>
        <Field label="TIN" error={error?.field('tin')} hint="000-000-000-00000">{(a) => <Input {...a} value={form.tin} onChange={set('tin')} />}</Field>
        <Field label="Address" error={error?.field('address')}>{(a) => <Input {...a} value={form.address} onChange={set('address')} />}</Field>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Email" error={error?.field('email')}>{(a) => <Input {...a} type="email" value={form.email} onChange={set('email')} />}</Field>
          <Field label="Phone" error={error?.field('phone')}>{(a) => <Input {...a} type="tel" value={form.phone} onChange={set('phone')} />}</Field>
        </div>
        <Checkbox label="VAT-registered (bills 12% VAT on professional fees)" checked={form.vat_registered} onChange={(e) => setForm((f) => ({ ...f, vat_registered: e.target.checked }))} />
        <Field label="Usual withholding tax on fees" error={error?.field('default_withholding_bps')} hint="Suggested when recording a payment a client made net of tax. The rate depends on the firm's income bracket; confirm it with your accountant. Always record what the client actually withheld.">
          {(a) => (
            <Select {...a} value={form.default_withholding_bps} onChange={(e) => setForm((f) => ({ ...f, default_withholding_bps: Number(e.target.value) }))} className="sm:w-72">
              <option value={1000}>10%</option>
              <option value={1500}>15%</option>
              <option value={500}>5%</option>
              <option value={0}>None</option>
            </Select>
          )}
        </Field>
        <Button type="submit" loading={save.isPending} className="self-start">Save details</Button>
      </form>
    </Card>
  )
}

/**
 * Opt-in for the matter assistant. Turning it on sends case files to a
 * third-party processor, so the decision and its reasons belong to the firm.
 */
function AssistantCard({ firm }: { firm: FirmSettings }) {
  const save = useSaveFirmSettings()
  const [confirming, setConfirming] = useState(false)

  return (
    <Card className="lg:col-span-2">
      <CardHeader title="AI assistant" description="Lets lawyers and paralegals ask Claude (Anthropic) about a matter’s documents and files, and draft from them." actions={firm.ai_enabled ? <Badge tone="success">On</Badge> : <Badge>Off</Badge>} />
      <div className="flex flex-col gap-3 p-5 text-sm">
        <p className="text-on-surface-variant">
          When someone asks a question, that matter’s details, drafted documents and uploaded files’ text are sent to Anthropic, a third-party processor outside the Philippines, to produce the answer. Before turning this on, confirm your data processing agreement with Anthropic and that your engagement terms and privacy notice cover it (Data Privacy Act of 2012). Every question is recorded in the audit log.
        </p>
        {!firm.ai_configured && <p className="rounded-lg bg-warning-container p-3 text-on-warning-container">The server has no Anthropic API key yet (ANTHROPIC_API_KEY), so the assistant stays unavailable until one is added.</p>}
        <div>
          {firm.ai_enabled
            ? <Button variant="outlined" loading={save.isPending} onClick={() => save.mutate({ ai_enabled: false })}>Turn off</Button>
            : <Button onClick={() => setConfirming(true)}>Turn on for the firm</Button>}
        </div>
      </div>
      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        title="Turn on the AI assistant?"
        description="Matter documents and file text will be sent to Anthropic whenever someone asks the assistant a question. Confirm you have the necessary agreements and client notices in place."
        confirmLabel="Turn on"
        loading={save.isPending}
        onConfirm={() => save.mutate({ ai_enabled: true }, { onSuccess: () => setConfirming(false) })}
      />
    </Card>
  )
}

/** The public "request a consultation" page and its web address. */
function IntakeCard({ firm }: { firm: FirmSettings }) {
  const save = useSaveFirmSettings()
  const toast = useToast()
  const [slug, setSlug] = useState(firm.slug ?? '')
  const [message, setMessage] = useState(firm.intake_message ?? '')
  const [enabled, setEnabled] = useState(firm.intake_enabled)
  const [error, setError] = useState<ApiError | null>(null)
  const publicUrl = firm.slug ? `${window.location.origin}/consult/${firm.slug}` : null

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await save.mutateAsync({ slug: slug || null, intake_message: message || null, intake_enabled: enabled })
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  const copy = async () => {
    if (!publicUrl) return
    try {
      await navigator.clipboard.writeText(publicUrl)
      toast.success('Link copied')
    } catch {
      toast.error('Copy failed; select the link and copy it manually.')
    }
  }

  return (
    <Card className="lg:col-span-2">
      <CardHeader title="Online intake" description="A public page where prospective clients request a consultation. Every request is conflict-checked automatically and lands in Intake." />
      <form onSubmit={submit} className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>
        <Field label="Web address" error={error?.field('slug')} hint={`${window.location.origin}/consult/${slug || 'your-firm'}`}>
          {(a) => <Input {...a} value={slug} maxLength={64} placeholder="santos-reyes-law" onChange={(e) => setSlug(e.target.value.toLowerCase())} />}
        </Field>
        <div className="flex items-end pb-2">
          <Checkbox label="Accept requests" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
        </div>
        <Field label="Message above the form" error={error?.field('intake_message')} className="sm:col-span-2" hint="Optional, e.g. office hours or how quickly you reply.">
          {(a) => <Textarea {...a} rows={2} maxLength={2000} value={message} onChange={(e) => setMessage(e.target.value)} />}
        </Field>
        <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
          <Button type="submit" loading={save.isPending}>Save</Button>
          {firm.intake_enabled && publicUrl && (
            <>
              <a href={publicUrl} target="_blank" rel="noopener" className="text-sm font-medium text-primary hover:underline">Open the page</a>
              <Button variant="text" size="sm" icon={<Copy className="size-4" />} onClick={copy}>Copy link</Button>
            </>
          )}
        </div>
      </form>
    </Card>
  )
}

function SecurityCard({ firm }: { firm: FirmSettings }) {
  const { user } = useCurrentSession()
  const save = useSaveFirmSettings()
  const [confirming, setConfirming] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => setError(null), [firm.require_two_factor])

  const change = (require_two_factor: boolean) =>
    save.mutate({ require_two_factor }, {
      onSuccess: () => setConfirming(false),
      onError: (err) => {
        setConfirming(false)
        setError(err.field('require_two_factor') ?? err.message)
      },
    })

  return (
    <Card>
      <CardHeader title="Sign-in security" />
      <div className="flex flex-col gap-4 p-5">
        <div className="flex items-start gap-3">
          <ShieldCheck className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
          <div className="text-sm">
            <p className="font-medium">Require two-step verification for everyone</p>
            <p className="text-on-surface-variant">Staff must use an authenticator app code as well as their password. Anyone who hasn’t set it up is asked to do so before they can continue.</p>
          </div>
        </div>
        <FormError message={error} />
        <p className="rounded-lg bg-surface-container p-3 text-sm" role="status">
          {firm.require_two_factor ? 'Required. ' : 'Optional. '}
          {firm.users_without_two_factor === 0
            ? 'Every active user has it on.'
            : `${firm.users_without_two_factor} active ${firm.users_without_two_factor === 1 ? 'user has' : 'users have'} not set it up yet${firm.require_two_factor ? ' and will be asked to at their next action' : ''}.`}
        </p>
        {!user.two_factor_enabled && !firm.require_two_factor && (
          <p className="text-sm text-on-surface-variant">Turn it on for your own account first, under Profile.</p>
        )}
        {firm.require_two_factor ? (
          <Button variant="outlined" className="self-start" loading={save.isPending} onClick={() => change(false)}>Make optional</Button>
        ) : (
          <Button className="self-start" disabled={!user.two_factor_enabled} onClick={() => setConfirming(true)}>Require for everyone</Button>
        )}
      </div>
      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        title="Require two-step verification?"
        description={`${firm.users_without_two_factor} ${firm.users_without_two_factor === 1 ? 'person' : 'people'} will have to set it up before they can use the system. Tell them first; they’ll need a phone with an authenticator app.`}
        confirmLabel="Require it"
        loading={save.isPending}
        onConfirm={() => change(true)}
      />
    </Card>
  )
}
