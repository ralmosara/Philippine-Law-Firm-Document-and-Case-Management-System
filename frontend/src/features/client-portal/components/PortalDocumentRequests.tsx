import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, CheckCircle2, ClipboardList, Upload } from 'lucide-react'
import { useId, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import type { DocumentRequestData, DocumentRequestItem } from '@/features/documents/components/DocumentRequestsCard'
import { ApiError, apiClient, get } from '@/shared/api/axios'
import { date, today } from '@/shared/lib/format'
import { Button, ButtonLink } from '@/shared/ui/Button'
import { Badge, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Card, CardHeader, PageHeader } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'

type PortalRequest = DocumentRequestData & { matter: string | null }

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.heic,.doc,.docx,.xls,.xlsx,.tif,.tiff'

const CLIENT_STATUS: Record<DocumentRequestItem['status'], { label: string; tone: 'neutral' | 'warning' | 'success' | 'danger' | 'primary' }> = {
  pending: { label: 'Needed', tone: 'warning' },
  uploaded: { label: 'Received, being checked', tone: 'primary' },
  accepted: { label: 'Done', tone: 'success' },
  rejected: { label: 'Please upload again', tone: 'danger' },
}

/** On the portal home: open requests, with how much is still needed. */
export function DocumentRequestsBanner() {
  const query = useQuery({ queryKey: ['portal', 'document-requests'], queryFn: () => get<PortalRequest[]>('/portal/document-requests') })
  const open = query.data?.filter((r) => r.status === 'open') ?? []
  if (open.length === 0) return null

  return (
    <Card className="mb-6">
      <CardHeader title={<span className="flex items-center gap-2"><ClipboardList className="size-5 text-primary" aria-hidden /> Documents we need from you</span>} />
      <ul className="divide-y divide-outline-variant">
        {open.map((r) => {
          const needed = r.items.filter((i) => i.required && (i.status === 'pending' || i.status === 'rejected')).length
          return (
            <li key={r.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
              <div className="min-w-0 flex-1">
                <p className="font-medium">{r.title}</p>
                <p className="text-sm text-on-surface-variant">{r.matter}{r.due_on && ` · needed by ${date(r.due_on)}`}{needed > 0 ? ` · ${needed} still needed` : ' · all uploaded'}</p>
              </div>
              <ButtonLink to={`/portal/requests/${r.id}`} size="sm" icon={<Upload className="size-4" />}>{needed > 0 ? 'Upload' : 'View'}</ButtonLink>
            </li>
          )
        })}
      </ul>
    </Card>
  )
}

/** One request: each document with its status and an upload button. */
export function PortalDocumentRequest() {
  const id = Number(useParams().id)
  const query = useQuery({ queryKey: ['portal', 'document-requests', id], queryFn: () => get<PortalRequest>(`/portal/document-requests/${id}`) })

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const r = query.data

  return (
    <>
      <PageHeader
        back={<Link to="/portal" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> Home</Link>}
        title={r.title}
        description={`${r.matter ?? ''}${r.due_on ? ` · needed by ${date(r.due_on)}` : ''}`}
      />
      {r.message && <p className="mb-4 rounded-lg bg-surface-container p-4 text-sm whitespace-pre-line">{r.message}</p>}
      <div className="mb-4 max-w-md"><ProgressBar value={r.progress.total ? (r.progress.done / r.progress.total) * 100 : 0} label={`${r.progress.done} of ${r.progress.total} done`} tone={r.status === 'completed' ? 'success' : 'primary'} /></div>
      {r.status === 'completed' && <p className="mb-4 flex items-center gap-2 rounded-lg bg-success-container p-4 text-sm text-on-success-container"><CheckCircle2 className="size-5" aria-hidden /> Thank you, we have everything we asked for.</p>}
      <Card>
        <ul className="divide-y divide-outline-variant">
          {r.items.map((item) => <ItemRow key={item.id} item={item} open={r.status === 'open'} overdue={!!r.due_on && r.due_on < today()} />)}
        </ul>
      </Card>
      <p className="mt-4 text-xs text-on-surface-variant">Files are checked for viruses and kept confidential with your matter. Clear phone photos are fine unless we asked for an original.</p>
    </>
  )
}

function ItemRow({ item, open, overdue }: { item: DocumentRequestItem; open: boolean; overdue: boolean }) {
  const input = useRef<HTMLInputElement>(null)
  const inputId = useId()
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const queryClient = useQueryClient()
  const toast = useToast()
  const canUpload = open && (item.status === 'pending' || item.status === 'rejected')

  const upload = async (file: File | undefined) => {
    if (!file) return
    setUploading(true)
    setError(null)
    try {
      const body = new FormData()
      body.append('file', file)
      await apiClient.post(`/portal/document-request-items/${item.id}/upload`, body)
      await queryClient.invalidateQueries({ queryKey: ['portal', 'document-requests'] })
      toast.success(`${item.label}: received. Thank you.`)
    } catch (err) {
      setError(ApiError.from(err).message)
    } finally {
      setUploading(false)
      if (input.current) input.current.value = ''
    }
  }

  const status = CLIENT_STATUS[item.status]
  return (
    <li className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center">
      <div className="min-w-0 flex-1">
        <p className="flex flex-wrap items-center gap-2 font-medium">
          {item.label}{!item.required && <span className="text-sm font-normal text-on-surface-variant">(if available)</span>}
          <Badge tone={item.status === 'pending' && overdue ? 'danger' : status.tone}>{status.label}</Badge>
        </p>
        {item.description && <p className="text-sm text-on-surface-variant">{item.description}</p>}
        {item.status === 'rejected' && item.review_note && <p className="mt-1 text-sm text-danger">{item.review_note}</p>}
        {item.file && item.status !== 'rejected' && <p className="text-xs text-on-surface-variant">Sent: {item.file.name}</p>}
        {error && <p role="alert" className="mt-1 text-sm text-danger">{error}</p>}
      </div>
      {canUpload && (
        <>
          <input ref={input} id={inputId} type="file" accept={ACCEPT} className="sr-only" onChange={(e) => void upload(e.target.files?.[0])} />
          <Button size="sm" icon={<Upload className="size-4" />} loading={uploading} onClick={() => input.current?.click()} aria-label={`Upload ${item.label}`} className="h-11 sm:h-auto">
            {item.status === 'rejected' ? 'Upload again' : 'Upload'}
          </Button>
        </>
      )}
    </li>
  )
}
