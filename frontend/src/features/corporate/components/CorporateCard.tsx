import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useAbilities } from '@/features/auth/session'
import { date } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader, DescriptionList } from '@/shared/ui/Layout'
import { useClientCorporate, useDeleteObligation, type CorporateObligation, monthDay } from '../api'
import { AddObligationDialog, ObligationDialog, ObligationStatus, ProfileDialog } from './CorporateDialogs'

/** A corporate client's registration details and yearly obligations, on the client page. */
export function CorporateCard({ clientId, clientName }: { clientId: number; clientName: string }) {
  const abilities = useAbilities()
  const query = useClientCorporate(clientId)
  const remove = useDeleteObligation()
  const [dialog, setDialog] = useState<'profile' | 'add' | null>(null)
  const [editing, setEditing] = useState<CorporateObligation | null>(null)
  const profile = query.data?.profile ?? null

  return (
    <Card>
      <CardHeader
        title="Corporate secretarial"
        actions={
          abilities.work_matters &&
          (profile ? (
            <>
              <Button size="sm" variant="text" icon={<Plus className="size-4" />} onClick={() => setDialog('add')}>
                Obligation
              </Button>
              <Button size="sm" variant="text" icon={<Pencil className="size-4" />} onClick={() => setDialog('profile')}>
                Edit
              </Button>
            </>
          ) : null)
        }
      />
      {query.isPending ? (
        <PageLoader />
      ) : query.isError ? (
        <div className="p-3">
          <ErrorState error={query.error} />
        </div>
      ) : !profile ? (
        <EmptyState
          title="Not tracked yet"
          description="Add the SEC details, fiscal year and meeting date to track the GIS, AFS, annual ITR and meetings."
          action={
            abilities.work_matters ? (
              <Button size="sm" onClick={() => setDialog('profile')}>
                Add corporate profile
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <div className="p-4">
            <DescriptionList
              items={[
                { label: 'SEC reg. no.', value: profile.sec_registration_no },
                {
                  label: 'Incorporated',
                  value: profile.incorporated_on ? date(profile.incorporated_on) : null,
                },
                {
                  label: 'Fiscal year end',
                  value: monthDay(profile.fiscal_year_end),
                },
                {
                  label: 'Annual meeting',
                  value: profile.annual_meeting_date ? monthDay(profile.annual_meeting_date) : null,
                },
                {
                  label: 'Corporate secretary',
                  value: profile.corporate_secretary,
                },
                { label: 'Responsible', value: profile.responsible_lawyer },
              ]}
            />
          </div>
          <ul className="divide-y divide-outline-variant border-t border-outline-variant">
            {query.data.obligations
              .filter((o) => o.status === 'pending' || o.year >= new Date().getFullYear())
              .map((o) => (
                <li key={o.id} className="flex items-center gap-2 px-4 py-2 text-sm">
                  <button type="button" disabled={!abilities.work_matters} onClick={() => setEditing({ ...o, client_name: clientName })} className="min-w-0 flex-1 text-left enabled:hover:text-primary">
                    <div className="truncate">{o.title}</div>
                    <div className="text-xs text-on-surface-variant">
                      Due {date(o.due_on)}
                      {o.reference && ` · Ref. ${o.reference}`}
                    </div>
                  </button>
                  <ObligationStatus o={o} />
                  {abilities.work_matters && o.kind === 'custom' && (
                    <IconButton label={`Remove ${o.title}`} onClick={() => remove.mutate(o.id)}>
                      <Trash2 className="size-4" />
                    </IconButton>
                  )}
                </li>
              ))}
          </ul>
        </>
      )}
      {dialog === 'profile' && <ProfileDialog clientId={clientId} clientName={clientName} profile={profile} onClose={() => setDialog(null)} />}
      {dialog === 'add' && <AddObligationDialog clientId={clientId} onClose={() => setDialog(null)} />}
      {editing && <ObligationDialog obligation={editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}
