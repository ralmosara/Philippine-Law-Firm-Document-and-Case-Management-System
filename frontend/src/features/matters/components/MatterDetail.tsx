import { ArrowLeft, CalendarPlus, Clock, FilePlus2, KanbanSquare, MessageSquarePlus, Pencil, Gavel } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { useTimeEntries } from '@/features/billing/api'
import { TimeEntriesTable } from '@/features/billing/components/TimeEntriesTable'
import { TimeTrackingForm } from '@/features/billing/components/TimeTrackingForm'
import { ExpensesPanel } from '@/features/billing/components/ExpensesPanel'
import { DeadlineForm } from '@/features/deadlines/components/DeadlineForm'
import { DeadlineList } from '@/features/deadlines/components/DeadlineList'
import { useDocuments } from '@/features/documents/api'
import { EFilingCard } from '@/features/efiling/components/EFilingCard'
import { EvidencePanel } from '@/features/evidence/components/EvidencePanel'
import { MatterEmailsCard } from '@/features/correspondence/components/MatterEmailsCard'
import { MatterDisbursementsCard } from '@/features/disbursements/components/MatterDisbursementsCard'
import { DocumentsTable } from '@/features/documents/components/DocumentsTable'
import { MatterFilesPanel } from '@/features/documents/components/MatterFilesPanel'
import { NewThreadDialog, ThreadList } from '@/features/messages/components/MessagesInbox'
import { MatterAssistant } from '@/features/assistant/components/MatterAssistant'
import { DocumentRequestsCard } from '@/features/documents/components/DocumentRequestsCard'
import { PleadingBuilder } from '@/features/documents/components/PleadingBuilder'
import { TemplatePicker } from '@/features/documents/components/TemplatePicker'
import { money } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { Button, ButtonLink } from '@/shared/ui/Button'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader, PageHeader, Tabs } from '@/shared/ui/Layout'
import { useMatter, useMatterDeadlines } from '../api'
import { MatterBillingPanel } from './MatterBillingPanel'
import { MatterForm } from './MatterForm'
import { MatterHistory, MatterOverview, StatusMenu } from './MatterOverview'
import { MatterStatusBadge } from './StatusBadge'

type Tab = 'overview' | 'deadlines' | 'documents' | 'files' | 'evidence' | 'messages' | 'assistant' | 'time' | 'billing' | 'history'

export function MatterDetail() {
  const id = Number(useParams().id)
  const abilities = useAbilities()
  const matter = useMatter(id)
  const deadlines = useMatterDeadlines(id)
  const [tab, setTab] = useUrlState('tab', 'overview')
  const [dialog, setDialog] = useState<'edit' | 'deadline' | 'document' | 'pleading' | 'time' | null>(null)

  if (matter.isPending) return <PageLoader />
  if (matter.isError) return <ErrorState error={matter.error} onRetry={() => matter.refetch()} />
  const m = matter.data
  const pendingDeadlines = deadlines.data?.filter((d) => d.status === 'pending') ?? []

  return (
    <>
      <PageHeader
        back={<Link to="/matters" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> Matters</Link>}
        title={m.title}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <MatterStatusBadge status={m.status} label={m.status_label} />
            <span>{m.reference}</span>
            {m.case_number && <span>{m.case_number}</span>}
            {m.client && <Link to={`/clients/${m.client.id}`} className="text-primary hover:underline">{m.client.name}</Link>}
            {m.unbilled_cents ? <span>{money(m.unbilled_cents)} unbilled</span> : null}
          </span>
        }
        actions={
          abilities.work_matters && (
            <>
              {abilities.practice_law && <StatusMenu matter={m} />}
              <Button variant="outlined" icon={<Pencil className="size-4" />} onClick={() => setDialog('edit')}>Edit</Button>
            </>
          )
        }
      />

      <Tabs<Tab>
        label="Matter sections"
        value={tab as Tab}
        onChange={setTab}
        tabs={[
          { value: 'overview', label: 'Overview' },
          { value: 'deadlines', label: 'Deadlines', count: pendingDeadlines.length },
          { value: 'documents', label: 'Documents' },
          { value: 'files', label: 'Files' },
          { value: 'evidence', label: 'Evidence' },
          { value: 'messages', label: 'Messages' },
          ...(abilities.work_matters ? [{ value: 'assistant' as const, label: 'AI assistant' }] : []),
          { value: 'time', label: 'Time & expenses' },
          { value: 'billing', label: 'Billing & trust' },
          { value: 'history', label: 'History' },
        ]}
      />

      {tab === 'overview' && <MatterOverview matter={m} upcoming={pendingDeadlines.slice(0, 3)} onShowDeadlines={() => setTab('deadlines')} />}

      {tab === 'deadlines' && (
        <Card>
          <CardHeader
            title="Deadlines & hearings"
            description="Reminders go out 7 days, 3 days, 1 day and on the day."
            actions={
              <>
                <ButtonLink to={`/tasks?matter=${id}&assignee=all`} variant="text" size="sm" icon={<KanbanSquare className="size-4" />}>Task board</ButtonLink>
                {abilities.work_matters && <Button variant="tonal" size="sm" icon={<CalendarPlus className="size-4" />} onClick={() => setDialog('deadline')}>Schedule</Button>}
              </>
            }
          />
          {deadlines.isPending ? <PageLoader /> : <DeadlineList deadlines={deadlines.data ?? []} emptyText="No deadlines on this matter yet." />}
        </Card>
      )}

      {tab === 'documents' && <MatterDocuments matterId={id} onNew={() => setDialog('document')} onPleading={() => setDialog('pleading')} canCreate={abilities.work_matters} />}
      {tab === 'files' && (
        <div className="flex flex-col gap-6">
          {m.client?.portal_enabled && <DocumentRequestsCard matterId={id} canEdit={abilities.work_matters} />}
          <MatterEmailsCard matterId={id} canEdit={abilities.work_matters} />
          <MatterFilesPanel matterId={id} canEdit={abilities.work_matters} />
        </div>
      )}
      {tab === 'evidence' && <EvidencePanel matter={m} />}
      {tab === 'assistant' &&abilities.work_matters && <MatterAssistant matterId={id} />}
      {tab === 'messages' && <MatterMessages matterId={id} canWrite={abilities.work_matters && !!m.client?.portal_enabled} />}
      {tab === 'time' && (
        <div className="flex flex-col gap-6">
          <MatterTime matterId={id} onLog={() => setDialog('time')} canLog={abilities.work_matters} />
          <MatterDisbursementsCard matter={{ id, client_id: m.client_id }} canRequest={abilities.work_matters} />
          <ExpensesPanel matterId={id} />
        </div>
      )}
      {tab === 'billing' && <MatterBillingPanel matter={m} />}
      {tab === 'history' && <MatterHistory matterId={id} />}

      <MatterForm open={dialog === 'edit'} onClose={() => setDialog(null)} matter={m} />
      <DeadlineForm open={dialog === 'deadline'} onClose={() => setDialog(null)} matterId={id} />
      <TemplatePicker open={dialog === 'document'} onClose={() => setDialog(null)} matterId={id} />
      <PleadingBuilder open={dialog === 'pleading'} onClose={() => setDialog(null)} matterId={id} />
      <TimeTrackingForm open={dialog === 'time'} onClose={() => setDialog(null)} matterId={id} />
    </>
  )
}

function MatterDocuments({ matterId, onNew, onPleading, canCreate }: { matterId: number; onNew: () => void; onPleading: () => void; canCreate: boolean }) {
  const documents = useDocuments({ matter_id: matterId })
  return (
    <div className="flex flex-col gap-6">
    <Card>
      <CardHeader
        title="Documents"
        actions={canCreate && (
          <div className="flex flex-wrap gap-2">
            <Button variant="tonal" size="sm" icon={<Gavel className="size-4" />} onClick={onPleading}>Draft a pleading</Button>
            <Button variant="tonal" size="sm" icon={<FilePlus2 className="size-4" />} onClick={onNew}>New document</Button>
          </div>
        )}
      />
      {documents.isPending ? <PageLoader /> : documents.isError ? <ErrorState error={documents.error} /> : <DocumentsTable documents={documents.data.data} showMatter={false} />}
    </Card>
    <EFilingCard matterId={matterId} canEdit={canCreate} />
    </div>
  )
}

function MatterTime({ matterId, onLog, canLog }: { matterId: number; onLog: () => void; canLog: boolean }) {
  const entries = useTimeEntries({ matter_id: matterId })
  return (
    <Card>
      <CardHeader title="Time entries" actions={canLog && <Button variant="tonal" size="sm" icon={<Clock className="size-4" />} onClick={onLog}>Log time</Button>} />
      {entries.isPending ? <PageLoader /> : entries.isError ? <ErrorState error={entries.error} /> : <TimeEntriesTable entries={entries.data.data} showMatter={false} totals={entries.data.totals} />}
    </Card>
  )
}

function MatterMessages({ matterId, canWrite }: { matterId: number; canWrite: boolean }) {
  const [composing, setComposing] = useState(false)
  return (
    <Card>
      <CardHeader
        title="Messages with the client"
        description={canWrite ? 'Private conversations the client reads and answers in the portal.' : 'Give the client portal access to message them securely.'}
        actions={canWrite && <Button variant="tonal" size="sm" icon={<MessageSquarePlus className="size-4" />} onClick={() => setComposing(true)}>New message</Button>}
      />
      <ThreadList matterId={matterId} linkTo={(t) => `/messages/${t.id}`} />
      {composing && <NewThreadDialog matterId={matterId} onClose={() => setComposing(false)} />}
    </Card>
  )
}
