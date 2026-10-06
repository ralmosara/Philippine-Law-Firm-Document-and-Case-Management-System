import { useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { ApiError, get, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, money, toCents } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Badge, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useSaveFirmSettings, type FirmSettings } from '../api'

/** Two-step sign-in for portal clients, and the amount above which trust deposits are reviewed for AML. */
export function ClientSafeguardsCard({ firm }: { firm: FirmSettings }) {
  const save = useSaveFirmSettings()
  const [threshold, setThreshold] = useState((firm.aml_threshold_cents / 100).toFixed(2))
  const [error, setError] = useState<string | null>(null)

  const saveThreshold = (e: FormEvent) => {
    e.preventDefault()
    const cents = toCents(threshold)
    if (!Number.isFinite(cents) || cents < 100_000) return setError('Enter an amount of at least ₱1,000.')
    setError(null)
    save.mutate({ aml_threshold_cents: cents }, { onError: (err) => setError(ApiError.from(err).message) })
  }

  return (
    <Card className="lg:col-span-2">
      <CardHeader title="Client safeguards" description="How clients sign in to the portal, and which trust deposits are flagged for an anti-money-laundering review." />
      <div className="grid grid-cols-1 gap-6 p-5 text-sm sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Field label="Two-step sign-in for the client portal" hint="Required: clients set it up at their next sign-in before they can see anything. Optional: each client chooses under My data.">
            {(a) => (
              <Select {...a} value={firm.portal_two_factor} onChange={(e) => save.mutate({ portal_two_factor: e.target.value as 'optional' | 'required' })} className="sm:w-56">
                <option value="optional">Optional</option>
                <option value="required">Required</option>
              </Select>
            )}
          </Field>
          <p className="text-xs text-on-surface-variant">A client who loses their phone is reset from their page (Portal access).</p>
        </div>
        <form onSubmit={saveThreshold} className="flex flex-col gap-2">
          <Field label="Flag a client's trust deposits in one day of (₱)" hint="Managing partners are asked to decide whether a covered or suspicious transaction report is due (Compliance → AML reviews).">
            {(a) => <Input {...a} inputMode="decimal" value={threshold} onChange={(e) => setThreshold(e.target.value)} className="sm:w-56" />}
          </Field>
          <p className="text-xs">
            {firm.aml_threshold_confirmed_at
              ? <Badge tone="success">Set {date(firm.aml_threshold_confirmed_at)}: {money(firm.aml_threshold_cents)}</Badge>
              : <Badge tone="warning">Default amount: confirm it with your compliance counsel</Badge>}
          </p>
          <FormError message={error} />
          <div><Button type="submit" variant="outlined" loading={save.isPending && save.variables?.aml_threshold_cents !== undefined}>Save amount</Button></div>
        </form>
      </div>
    </Card>
  )
}

interface Clause { key: string; title: string; standard: string; text: string; customised: boolean }

/** The firm's own wording for the standard clauses of new engagement letters. */
export function EngagementClausesCard() {
  const query = useQuery({ queryKey: ['engagement-clauses'], queryFn: () => get<{ clauses: Clause[] }>('/v1/engagement-clauses') })
  if (query.isPending || !query.data) return <Card className="lg:col-span-2"><PageLoader /></Card>
  return <ClausesForm key={JSON.stringify(query.data.clauses.map((c) => c.text))} clauses={query.data.clauses} />
}

function ClausesForm({ clauses }: { clauses: Clause[] }) {
  const [texts, setTexts] = useState(Object.fromEntries(clauses.map((c) => [c.key, c.text])))
  const save = useApiMutation((input: object) => put('/v1/engagement-clauses', input), { invalidate: [['engagement-clauses']], success: 'Clauses saved; new letters use them', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null

  return (
    <Card className="lg:col-span-2">
      <CardHeader title="Engagement letter clauses" description="The standard terms after the scope and fees in every new engagement letter. Edit them once here; each letter can still be adjusted before it is sent. {dpo_email} is replaced by your Data Protection Officer's address." />
      <form onSubmit={(e) => { e.preventDefault(); save.mutate({ clauses: texts }) }} className="flex flex-col gap-4 p-5">
        {clauses.map((c, i) => (
          <Field key={c.key} label={`${i + 3}. ${c.title}`} hint={texts[c.key] !== c.standard ? 'Your wording. Clear it to go back to the standard text.' : 'Standard text.'}>
            {(a) => <Textarea {...a} rows={3} value={texts[c.key]} onChange={(e) => setTexts({ ...texts, [c.key]: e.target.value })} />}
          </Field>
        ))}
        <FormError message={error?.message ?? null} />
        <div className="flex gap-2">
          <Button type="submit" loading={save.isPending}>Save clauses</Button>
          <Button type="button" variant="text" onClick={() => setTexts(Object.fromEntries(clauses.map((c) => [c.key, c.standard])))}>Back to the standard text</Button>
        </div>
      </form>
    </Card>
  )
}
