import { AlertTriangle, CheckCircle2, FileText, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useClientOptions } from '@/features/clients/api'
import { date } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, Select } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader, StatCard, Table, Td, Th } from '@/shared/ui/Layout'
import { useCorporateOverview, useInstallCorporateTemplates, type CorporateObligation, type ObligationKind, monthDay } from '../api'
import { ObligationDialog, ObligationStatus, ProfileDialog } from './CorporateDialogs'

const thisYear = new Date().getFullYear()
const COLUMNS: { kind: ObligationKind; label: string }[] = [
  { kind: 'annual_meeting', label: 'Annual meeting' },
  { kind: 'gis', label: 'GIS' },
  { kind: 'afs', label: 'AFS' },
  { kind: 'annual_itr', label: 'Annual ITR' },
]

/** Corporate secretarial: every client company's yearly SEC and BIR obligations at a glance. */
export function CorporatePage() {
  const abilities = useAbilities()
  const [year, setYear] = useUrlState('year', String(thisYear))
  const query = useCorporateOverview(year)
  const install = useInstallCorporateTemplates()
  const [editing, setEditing] = useState<CorporateObligation | null>(null)
  const [adding, setAdding] = useState(false)

  return (
    <>
      <PageHeader
        title="Corporate secretarial"
        description="Client companies' annual meetings, GIS, audited financial statements and annual ITRs, with reminders to the responsible lawyer 30 days, 7 days and a day before."
        actions={
          abilities.work_matters && (
            <>
              <Button variant="outlined" icon={<FileText className="size-4" />} loading={install.isPending} onClick={() => install.mutate()}>
                Install corporate templates
              </Button>
              <Button icon={<Plus className="size-4" />} onClick={() => setAdding(true)}>
                Add company
              </Button>
            </>
          )
        }
      />
      <p className="mb-4 flex items-start gap-2 rounded-[3px] border border-warning/40 bg-warning-container px-3 py-2 text-sm text-on-warning-container">
        <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
        Dates follow the general SEC and BIR rules. The SEC assigns AFS filing dates by the last digit of the registration number in some years; adjust a due date where it differs.
      </p>

      {query.isPending ? (
        <PageLoader />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : (
        (() => {
          const d = query.data
          const all = d.companies.flatMap((c) => c.obligations)
          return (
            <div className="flex flex-col gap-4">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <StatCard label="Client companies" value={String(d.companies.length)} />
                <StatCard label="Due in 30 days" value={String(d.upcoming.filter((o) => !o.is_overdue).length)} />
                <StatCard label="Overdue" value={String(all.filter((o) => o.is_overdue).length)} tone={all.some((o) => o.is_overdue) ? 'danger' : undefined} />
              </div>

              <Card>
                <CardHeader title="Coming up" description="Pending obligations of every company due in the next 30 days, and any overdue." />
                {d.upcoming.length === 0 ? (
                  <EmptyState icon={<CheckCircle2 className="size-6" />} title="Nothing due in the next 30 days" />
                ) : (
                  <Table caption="Upcoming corporate obligations">
                    <thead>
                      <tr>
                        <Th>Due</Th>
                        <Th>Company</Th>
                        <Th>Obligation</Th>
                        <Th>Status</Th>
                        <Th>
                          <span className="sr-only">Actions</span>
                        </Th>
                      </tr>
                    </thead>
                    <tbody>
                      {d.upcoming.map((o) => (
                        <tr key={o.id}>
                          <Td className="whitespace-nowrap">{date(o.due_on)}</Td>
                          <Td>
                            <Link to={`/clients/${o.client_id}`} className="hover:text-primary">
                              {o.client_name}
                            </Link>
                          </Td>
                          <Td>{o.title}</Td>
                          <Td>
                            <ObligationStatus o={o} />
                          </Td>
                          <Td align="right">
                            {abilities.work_matters && (
                              <Button size="sm" variant="text" onClick={() => setEditing(o)}>
                                Update
                              </Button>
                            )}
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                )}
              </Card>

              <Card>
                <CardHeader
                  title={`Compliance grid ${d.year}`}
                  description="Each company's standard obligations for the year. Click one to mark it done or change its date."
                  actions={
                    <Select aria-label="Year" value={year} onChange={(e) => setYear(e.target.value)} className="w-24">
                      {[thisYear + 1, thisYear, thisYear - 1, thisYear - 2].map((y) => (
                        <option key={y}>{y}</option>
                      ))}
                    </Select>
                  }
                />
                {d.companies.length === 0 ? (
                  <EmptyState title="No client companies yet" description="Add a corporate profile to a corporate client to start tracking its obligations." />
                ) : (
                  <Table caption="Corporate compliance grid">
                    <thead>
                      <tr>
                        <Th>Company</Th>
                        <Th>Meeting / FYE</Th>
                        {COLUMNS.map((c) => (
                          <Th key={c.kind}>{c.label}</Th>
                        ))}
                        <Th>Other</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {d.companies.map(({ profile, obligations }) => {
                        const custom = obligations.filter((o) => o.kind === 'custom')
                        return (
                          <tr key={profile.id}>
                            <Td>
                              <Link to={`/clients/${profile.client_id}`} className="font-medium hover:text-primary">
                                {profile.client_name}
                              </Link>
                              <div className="text-xs text-on-surface-variant">
                                {profile.sec_registration_no ? `SEC ${profile.sec_registration_no}` : 'No SEC no.'}
                                {profile.responsible_lawyer && ` · ${profile.responsible_lawyer}`}
                              </div>
                            </Td>
                            <Td className="text-xs whitespace-nowrap text-on-surface-variant">
                              {monthDay(profile.annual_meeting_date)}
                              <div>FYE {monthDay(profile.fiscal_year_end)}</div>
                            </Td>
                            {COLUMNS.map((c) => {
                              const o = obligations.find((x) => x.kind === c.kind)
                              return (
                                <Td key={c.kind} className="whitespace-nowrap">
                                  {!o ? (
                                    <span className="text-on-surface-variant">—</span>
                                  ) : (
                                    <button
                                      type="button"
                                      disabled={!abilities.work_matters}
                                      onClick={() =>
                                        setEditing({
                                          ...o,
                                          client_name: profile.client_name,
                                        })
                                      }
                                      className="flex flex-col items-start gap-0.5 text-left enabled:hover:underline"
                                      aria-label={`${o.title}: update`}
                                    >
                                      <ObligationStatus o={o} />
                                      <span className="text-xs text-on-surface-variant">{date(o.due_on)}</span>
                                    </button>
                                  )}
                                </Td>
                              )
                            })}
                            <Td className="text-xs text-on-surface-variant">{custom.length ? `${custom.filter((o) => o.status === 'pending').length} pending of ${custom.length}` : '—'}</Td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </Table>
                )}
              </Card>
            </div>
          )
        })()
      )}

      {editing && <ObligationDialog obligation={editing} onClose={() => setEditing(null)} />}
      {adding && <AddCompanyDialog existing={query.data?.companies.map((c) => c.profile.client_id) ?? []} onClose={() => setAdding(false)} />}
    </>
  )
}

/** Pick a corporate client without a profile, then fill in its profile. */
function AddCompanyDialog({ existing, onClose }: { existing: number[]; onClose: () => void }) {
  const clients = useClientOptions()
  const [clientId, setClientId] = useState<number | null>(null)
  const options = (clients.data ?? []).filter((c) => c.type === 'corporate' && !existing.includes(c.id))
  const chosen = options.find((c) => c.id === clientId)

  if (chosen) return <ProfileDialog clientId={chosen.id} clientName={chosen.name} profile={null} onClose={onClose} />

  return (
    <Dialog
      open
      onClose={onClose}
      title="Add company"
      description="Corporate clients not tracked yet."
      footer={
        <Button variant="text" onClick={onClose}>
          Cancel
        </Button>
      }
    >
      {clients.isPending ? (
        <PageLoader />
      ) : options.length === 0 ? (
        <EmptyState title="Every corporate client is already tracked" description="Add the company as a client first, with the type set to corporate." />
      ) : (
        <Field label="Client">
          {(a) => (
            <Select {...a} value="" onChange={(e) => setClientId(Number(e.target.value))}>
              <option value="" disabled>
                Choose a company…
              </option>
              {options.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
      )}
    </Dialog>
  )
}
