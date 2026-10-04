import clsx from 'clsx'
import { ArrowLeft, BookmarkPlus, FileDown, GitCompare, Printer, Save, Share2, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useBlocker, useNavigate, useParams } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { SaveToKnowledgeDialog } from '@/features/knowledge/components/KnowledgeDialogs'
import { DocumentStatusBadge } from '@/features/matters/components/StatusBadge'
import type { DocumentStatus } from '@/shared/api/types'
import { dateTime } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, Input } from '@/shared/ui/Form'
import { Card, CardHeader, PageHeader } from '@/shared/ui/Layout'
import { useDeleteDocument, useDocument, useDocumentStatus, useDocumentVersions, useSaveVersion, useUpdateDocument } from '../api'
import { CompareDialog } from './DocumentDiffViewer'
import { SignaturePanel } from './SignaturePanel'

const NEXT_STATUS: Record<DocumentStatus, { status: DocumentStatus; label: string }[]> = {
  draft: [{ status: 'final', label: 'Mark as final' }],
  final: [
    { status: 'signed', label: 'Mark signed' },
    { status: 'notarized', label: 'Mark notarized' },
    { status: 'draft', label: 'Reopen as draft' },
  ],
  // Answered by the client in the portal, or cancelled from the E-signature card.
  pending_signature: [],
  signed: [{ status: 'notarized', label: 'Mark notarized' }],
  notarized: [],
}

/**
 * Versioned plain-text editor. Every save appends an immutable version, so
 * any two versions can be compared line by line.
 */
export function DocumentEditor() {
  const id = Number(useParams().id)
  const document = useDocument(id)
  const versions = useDocumentVersions(id)
  const abilities = useAbilities()
  const navigate = useNavigate()

  const saveVersion = useSaveVersion(id)
  const update = useUpdateDocument(id)
  const setStatus = useDocumentStatus(id)
  const remove = useDeleteDocument()

  const [content, setContent] = useState('')
  const [summary, setSummary] = useState('')
  const [compare, setCompare] = useState<[string, string] | null>(null)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [keeping, setKeeping] = useState(false)

  const latest = document.data?.latest_version
  useEffect(() => {
    if (latest) setContent(latest.content)
  }, [latest])

  const dirty = latest !== undefined && latest !== null && content !== latest.content
  const editable = !!document.data?.is_editable && abilities.work_matters

  // Guard against losing unsaved edits on navigation or tab close.
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname)
  useEffect(() => {
    if (!dirty) return
    const onBeforeUnload = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  if (document.isPending) return <PageLoader />
  if (document.isError) return <ErrorState error={document.error} onRetry={() => document.refetch()} />
  const doc = document.data

  const save = () => saveVersion.mutate({ content, change_summary: summary || undefined }, { onSuccess: () => setSummary('') })

  return (
    <>
      <PageHeader
        back={
          doc.matter && (
            <Link to={`/matters/${doc.matter.id}?tab=documents`} className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary">
              <ArrowLeft className="size-4" /> {doc.matter.reference} · {doc.matter.title}
            </Link>
          )
        }
        title={doc.title}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <DocumentStatusBadge status={doc.status} />
            <span>Version {doc.current_version}</span>
            {doc.template && <span>From “{doc.template.name}”</span>}
          </span>
        }
        actions={
          <>
            <DownloadButton href={`/api/v1/documents/${doc.id}/pdf`} icon={<FileDown className="size-4" />}>PDF</DownloadButton>
            <DownloadButton href={`/api/v1/documents/${doc.id}/docx`} icon={<FileDown className="size-4" />}>Word</DownloadButton>
            <Button variant="text" icon={<Printer className="size-4" />} onClick={() => window.print()}>Print</Button>
            {abilities.work_matters && <Button variant="text" icon={<BookmarkPlus className="size-4" />} onClick={() => setKeeping(true)}>Keep as model</Button>}
            {keeping && <SaveToKnowledgeDialog documentId={doc.id} title={doc.title} onClose={() => setKeeping(false)} />}
            {abilities.practice_law && NEXT_STATUS[doc.status].map((next) => (
              <Button key={next.status} variant="outlined" loading={setStatus.isPending} disabled={dirty} onClick={() => setStatus.mutate(next.status)} title={dirty ? 'Save your changes first' : undefined}>
                {next.label}
              </Button>
            ))}
            {editable && doc.status === 'draft' && <Button variant="text" icon={<Trash2 className="size-4" />} onClick={() => setConfirmDelete(true)}>Delete</Button>}
          </>
        }
      />

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_20rem]">
        <Card className="flex flex-col print:border-0">
          {editable && (
            <div className="flex flex-col gap-2 border-b border-outline-variant p-3 sm:flex-row sm:items-center print:hidden">
              <Input aria-label="Change summary" placeholder="Describe your changes (optional)" value={summary} onChange={(e) => setSummary(e.target.value)} className="flex-1" maxLength={255} />
              <Button icon={<Save className="size-4" />} onClick={save} disabled={!dirty} loading={saveVersion.isPending}>
                Save version {doc.current_version + 1}
              </Button>
            </div>
          )}
          <textarea
            aria-label="Document content"
            value={content}
            onChange={(e) => setContent(e.target.value)}
            readOnly={!editable}
            spellCheck
            className={clsx(
              'min-h-[70vh] w-full flex-1 resize-y rounded-b-(--radius-card) bg-surface p-8 font-serif text-[15px] leading-7 text-on-surface focus:outline-none sm:px-14',
              !editable && 'cursor-default',
            )}
          />
          {!doc.is_editable && (
            <p className="border-t border-outline-variant px-5 py-3 text-sm text-on-surface-variant print:hidden">
              {doc.status === 'pending_signature'
                ? 'This document is waiting for the client’s e-signature, so its content is locked.'
                : `This document is ${doc.status.replace('_', ' ')} and its content is locked.${doc.status === 'final' ? ' Reopen it as a draft to make changes.' : ''}`}
            </p>
          )}
        </Card>

        <div className="flex flex-col gap-6 print:hidden">
          <SignaturePanel documentId={doc.id} canRequest={abilities.practice_law && doc.status === 'final' && !dirty} canManage={abilities.practice_law} />

          <Card>
            <CardHeader title="Sharing" />
            <div className="p-5">
              <Checkbox
                label="Visible in the client portal"
                checked={doc.shared_with_client}
                disabled={!abilities.work_matters || update.isPending}
                onChange={(e) => update.mutate({ shared_with_client: e.target.checked })}
              />
              <p className="mt-2 flex items-start gap-2 text-xs text-on-surface-variant"><Share2 className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> The client sees the latest saved version only.</p>
            </div>
          </Card>

          <Card>
            <CardHeader
              title="Version history"
              actions={versions.data?.[0] ? (
                <Button variant="text" size="sm" icon={<GitCompare className="size-4" />} onClick={() => {
                  const [latest, previous] = versions.data ?? []
                  if (latest) setCompare([`v:${(previous ?? latest).version_number}`, `v:${latest.version_number}`])
                }}>
                  Compare…
                </Button>
              ) : undefined}
            />
            {versions.isPending ? (
              <PageLoader />
            ) : (
              <ol className="max-h-[50vh] divide-y divide-outline-variant overflow-y-auto">
                {versions.data?.map((v, i) => {
                  const previous = versions.data[i + 1]
                  return (
                    <li key={v.id} className="px-5 py-3">
                      <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-medium">Version {v.version_number}</p>
                        {previous && (
                          <Button variant="text" size="sm" icon={<GitCompare className="size-4" />} onClick={() => setCompare([`v:${previous.version_number}`, `v:${v.version_number}`])}>
                            Compare
                          </Button>
                        )}
                      </div>
                      <p className="text-xs text-on-surface-variant">{dateTime(v.created_at)}{v.creator && ` · ${v.creator.name}`}</p>
                      {v.change_summary && <p className="mt-1 text-sm text-on-surface-variant">{v.change_summary}</p>}
                    </li>
                  )
                })}
              </ol>
            )}
          </Card>
        </div>
      </div>

      <CompareDialog documentId={id} initial={compare} onClose={() => setCompare(null)} />

      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        title="Delete this draft?"
        description={<>“{doc.title}” and its {doc.current_version} version(s) will be removed.</>}
        destructive
        confirmLabel="Delete"
        loading={remove.isPending}
        onConfirm={() => remove.mutate(doc.id, { onSuccess: () => navigate(doc.matter ? `/matters/${doc.matter.id}?tab=documents` : '/documents') })}
      />

      <ConfirmDialog
        open={blocker.state === 'blocked'}
        onClose={() => blocker.reset?.()}
        title="Discard unsaved changes?"
        description="Your edits since the last saved version will be lost."
        destructive
        confirmLabel="Discard"
        onConfirm={() => blocker.proceed?.()}
      />
    </>
  )
}
