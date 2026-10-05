import { ArrowLeft, Download, FileDown, FileText, Paperclip } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { date, duration, fileSize, money } from '@/shared/lib/format'
import { DownloadButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Card, CardHeader, DescriptionList, PageHeader } from '@/shared/ui/Layout'
import { portalFileUrl, usePortalDocument, usePortalMatter } from '../api'
import { t } from '@/shared/lib/i18n'
import { FeedbackCard } from './PortalExperience'

/** A client's view of one matter: stage, hearings, history and shared documents. */
export function CaseStatusView() {
  const id = Number(useParams().id)
  const matter = usePortalMatter(id)
  const [openDoc, setOpenDoc] = useState<number | null>(null)

  if (matter.isPending) return <PageLoader />
  if (matter.isError) return <ErrorState error={matter.error} onRetry={() => matter.refetch()} />
  const m = matter.data

  return (
    <>
      <PageHeader
        back={<Link to="/portal" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> {t('All matters')}</Link>}
        title={m.title}
        description={`${m.case_type}${m.case_number ? ` · ${m.case_number}` : ''}`}
      />

      <Card className="mb-6 p-5">
        <div className="mb-2 flex justify-between text-sm"><span>{t('Current stage:')} <strong>{m.status_label}</strong></span><span className="text-on-surface-variant">{m.progress}%</span></div>
        <ProgressBar value={m.progress} label={t('Case progress')} tone={m.status === 'closed' ? 'success' : 'primary'} />
      </Card>

      {m.feedback && <FeedbackCard matterId={m.id} feedback={m.feedback} />}

      {m.budget && (
        <Card className="mb-6 p-5">
          <div className="mb-2 flex flex-wrap justify-between gap-2 text-sm">
            <span>
              {t('Budget:')} <strong>{t('{used} of {total} used', { used: m.budget.basis === 'hours' ? duration(m.budget.used) : money(m.budget.used), total: m.budget.basis === 'hours' ? duration(m.budget.total) : money(m.budget.total) })}</strong>
            </span>
            <span className="text-on-surface-variant">{m.budget.percent}%</span>
          </div>
          <ProgressBar value={m.budget.percent} label={t('Budget used')} tone={m.budget.percent >= 100 ? 'danger' : m.budget.percent >= 80 ? 'warning' : 'primary'} />
          <p className="mt-2 text-xs text-on-surface-variant">
            {m.budget.basis === 'hours' ? t('Hours of work on your matter, as agreed with your lawyer.') : m.budget.includes_expenses ? t('Professional fees and expenses, as agreed with your lawyer.') : t('Professional fees, as agreed with your lawyer.')}
          </p>
        </Card>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title={t('Details')} />
          <div className="p-5">
            <DescriptionList
              items={[
                { label: t('Court'), value: [m.court, m.court_branch].filter(Boolean).join(', ') || null },
                { label: t('Your lawyer'), value: m.lawyer ? <a href={`mailto:${m.lawyer.email}`} className="text-primary hover:underline">{m.lawyer.name}</a> : null },
                { label: t('Opened'), value: date(m.opened_at) },
                { label: t('Next hearing'), value: m.next_hearing ? `${date(m.next_hearing.date)}${m.next_hearing.time ? `, ${m.next_hearing.time}` : ''}${m.next_hearing.location ? ` — ${m.next_hearing.location}` : ''}` : t('None scheduled') },
              ]}
            />
            {m.description && <p className="mt-4 text-sm whitespace-pre-line text-on-surface-variant">{m.description}</p>}
          </div>
        </Card>

        <Card>
          <CardHeader title={t('Progress so far')} />
          <ol className="px-5 py-4">
            {m.timeline.map((t, i) => (
              <li key={i} className="relative flex gap-4 pb-5 last:pb-0">
                {i < m.timeline.length - 1 && <span aria-hidden="true" className="absolute top-3 left-[5px] h-full w-px bg-outline-variant" />}
                <span aria-hidden="true" className="relative mt-1.5 size-3 shrink-0 rounded-full bg-primary ring-4 ring-surface" />
                <span className="text-sm"><strong>{t.status}</strong> <span className="text-on-surface-variant">· {date(t.date)}</span></span>
              </li>
            ))}
          </ol>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title={t('Shared documents')} />
          {m.documents.length === 0 ? <EmptyState icon={<FileText className="size-6" />} title={t('No documents shared yet')} /> : (
            <ul className="divide-y divide-outline-variant">
              {m.documents.map((d) => (
                <li key={d.id} className="flex items-center hover:bg-surface-container">
                  <button type="button" onClick={() => setOpenDoc(d.id)} className="flex min-w-0 flex-1 items-center gap-3 px-5 py-3 text-left">
                    <FileText className="size-5 shrink-0 text-primary" aria-hidden="true" />
                    <span className="flex-1 truncate font-medium">{d.title}</span>
                    <span className="text-sm text-on-surface-variant">{date(d.updated_at)}</span>
                  </button>
                  <DownloadButton href={`/api/portal/documents/${d.id}/pdf`} size="sm" icon={<FileDown className="size-4" />} className="mr-3">PDF</DownloadButton>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {m.files.length > 0 && (
          <Card className="lg:col-span-2">
            <CardHeader title={t('Files')} description={t('Copies the firm has shared with you.')} />
            <ul className="divide-y divide-outline-variant">
              {m.files.map((f) => (
                <li key={f.id}>
                  <a href={portalFileUrl(f.id)} download className="flex items-center gap-3 px-5 py-3 hover:bg-surface-container">
                    <Paperclip className="size-5 shrink-0 text-primary" aria-hidden="true" />
                    <span className="min-w-0 flex-1">
                      <span className="block font-medium break-words">{f.name}</span>
                      {f.description && <span className="block text-sm text-on-surface-variant">{f.description}</span>}
                    </span>
                    <span className="shrink-0 text-sm text-on-surface-variant tabular-nums">{fileSize(f.size_bytes)}</span>
                    <Download className="size-4 shrink-0 text-on-surface-variant" aria-hidden="true" />
                  </a>
                </li>
              ))}
            </ul>
          </Card>
        )}
      </div>

      <DocumentDialog id={openDoc} onClose={() => setOpenDoc(null)} />
    </>
  )
}

function DocumentDialog({ id, onClose }: { id: number | null; onClose: () => void }) {
  const doc = usePortalDocument(id)
  return (
    <Dialog open={id !== null} onClose={onClose} title={doc.data?.title ?? t('Document')} size="xl">
      {doc.isPending ? <PageLoader /> : doc.isError ? <ErrorState error={doc.error} /> : (
        <pre className="font-serif text-[15px] leading-7 whitespace-pre-wrap">{doc.data?.content}</pre>
      )}
    </Dialog>
  )
}
