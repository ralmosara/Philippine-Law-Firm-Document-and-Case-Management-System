import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, FolderArchive, Undo2, Upload } from 'lucide-react'
import { useId, useRef, useState } from 'react'
import { useMatterOptions } from '@/features/trust/api'
import { ApiError, apiClient, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { dateTime, fileSize } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { FormError, Select } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

type Status = 'uploading' | 'previewed' | 'importing' | 'done' | 'failed' | 'undone'
type FileStatus = 'ready' | 'imported' | 'skipped' | 'failed' | 'undone'

interface ImportSummary {
  id: number
  filename: string
  size_bytes: number
  status: Status
  summary: { imported: number; skipped: number; failed: number; remaining: number; undone?: number } | null
  error: string | null
  can_undo: boolean
  created_by: string | null
  created_at: string
  finished_at: string | null
}

interface ImportDetail extends ImportSummary {
  folders: {
    folder: string
    matter: { id: number; reference: string; title: string } | null
    matched_by: 'name' | 'hand' | null
    counts: Partial<Record<FileStatus, number>>
    files: { name: string; path: string | null; size: number; status: FileStatus; reason: string | null; file_id: number | null }[]
  }[]
}

const STATUS_LABEL: Record<Status, { label: string; tone: 'neutral' | 'primary' | 'success' | 'warning' | 'danger' }> = {
  uploading: { label: 'Upload not finished', tone: 'warning' },
  previewed: { label: 'Preview, not imported', tone: 'warning' },
  importing: { label: 'Importing…', tone: 'primary' },
  done: { label: 'Imported', tone: 'success' },
  failed: { label: 'Could not be read', tone: 'danger' },
  undone: { label: 'Undone', tone: 'neutral' },
}

/** Upload a ZIP in pieces (with retries), so neither the size limit nor a weak connection stops it. */
async function uploadInPieces(file: File, onProgress: (sent: number) => void): Promise<number> {
  const start = await post<{ id: number; chunk_bytes: number }>('/v1/document-imports', { filename: file.name, size: file.size })
  const pieces = Math.ceil(file.size / start.chunk_bytes)
  for (let i = 0; i < pieces; i++) {
    const body = new FormData()
    body.append('chunk', file.slice(i * start.chunk_bytes, (i + 1) * start.chunk_bytes), `part-${i}`)
    for (let attempt = 1; ; attempt++) {
      try {
        await apiClient.post(`/v1/document-imports/${start.id}/chunks/${i}`, body)
        break
      } catch (error) {
        if (attempt >= 4 || (error instanceof ApiError ? error.status === 422 : ApiError.from(error).status === 422)) throw error
        await new Promise((r) => setTimeout(r, 1500 * attempt))
      }
    }
    onProgress(Math.min(file.size, (i + 1) * start.chunk_bytes))
  }
  await post(`/v1/document-imports/${start.id}/finish`)

  return start.id
}

/** Firm Settings > Import data: the firm's old files, from a ZIP with one folder per matter. */
export function DocumentImportCard() {
  const [openId, setOpenId] = useState<number | null>(null)
  const [sent, setSent] = useState<{ done: number; total: number } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const input = useRef<HTMLInputElement>(null)
  const inputId = useId()
  const queryClient = useQueryClient()
  const history = useQuery({ queryKey: ['document-imports'], queryFn: () => get<{ data: ImportSummary[] }>('/v1/document-imports') })

  const choose = async (file: File | undefined) => {
    if (!file) return
    setError(null)
    setSent({ done: 0, total: file.size })
    try {
      const id = await uploadInPieces(file, (done) => setSent({ done, total: file.size }))
      void queryClient.invalidateQueries({ queryKey: ['document-imports'] })
      setOpenId(id)
    } catch (err) {
      setError(ApiError.from(err).field('file') ?? ApiError.from(err).field('size') ?? ApiError.from(err).field('filename') ?? ApiError.from(err).message)
      void queryClient.invalidateQueries({ queryKey: ['document-imports'] })
    } finally {
      setSent(null)
      if (input.current) input.current.value = ''
    }
  }

  if (openId !== null) return <DocumentImportPreview id={openId} onBack={() => setOpenId(null)} />

  return (
    <Card>
      <CardHeader
        title="Documents"
        description="Your old files, from a ZIP with one folder per matter named by its reference or case number (e.g. “M-2026-0019 Aquino v. Kalayaan”). Subfolders are kept as each file’s description. Every file is virus-scanned and made searchable, like an upload. Up to 20 MB a file; a large archive uploads in pieces and can take a while to file."
        actions={
          <>
            <input ref={input} id={inputId} type="file" accept=".zip,application/zip" className="sr-only" onChange={(e) => void choose(e.target.files?.[0])} />
            <Button size="sm" variant="tonal" icon={<Upload className="size-4" />} loading={sent !== null} onClick={() => input.current?.click()} aria-label="Upload a ZIP of documents">Upload ZIP</Button>
          </>
        }
      />
      {(sent || error) && (
        <div className="flex flex-col gap-2 border-b border-outline-variant p-4 text-sm">
          {sent && <><ProgressBar value={sent.total ? (sent.done / sent.total) * 100 : 0} label="Upload progress" /><p className="text-on-surface-variant">Uploading {fileSize(sent.done)} of {fileSize(sent.total)}…</p></>}
          <FormError message={error} />
        </div>
      )}
      {history.isPending ? <PageLoader /> : history.isError ? <div className="p-4"><ErrorState error={history.error} /></div> : history.data.data.length === 0 ? (
        <EmptyState icon={<FolderArchive className="size-6" />} title="No document imports yet" />
      ) : (
        <Table caption="Document imports" compact>
          <thead><tr><Th>File</Th><Th>Status</Th><Th>When</Th><Th><span className="sr-only">Open</span></Th></tr></thead>
          <tbody>
            {history.data.data.map((i) => (
              <tr key={i.id}>
                <Td className="max-w-64 truncate font-medium">{i.filename}<div className="text-xs font-normal text-on-surface-variant">{fileSize(i.size_bytes)}</div></Td>
                <Td><Badge tone={STATUS_LABEL[i.status].tone}>{i.status === 'done' && i.summary ? `${i.summary.imported} imported` : STATUS_LABEL[i.status].label}</Badge></Td>
                <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(i.finished_at ?? i.created_at)}{i.created_by && <div className="text-xs">{i.created_by}</div>}</Td>
                <Td align="right">{i.status !== 'uploading' && <Button size="sm" variant="text" onClick={() => setOpenId(i.id)}>{i.status === 'previewed' ? 'Review' : 'View'}</Button>}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  )
}

function DocumentImportPreview({ id, onBack }: { id: number; onBack: () => void }) {
  const query = useQuery({
    queryKey: ['document-imports', id],
    queryFn: () => get<ImportDetail>(`/v1/document-imports/${id}`),
    // While filing in the background, check progress every few seconds.
    refetchInterval: (q) => (q.state.data?.status === 'importing' ? 3000 : false),
  })
  const matters = useMatterOptions()
  const invalidate = [['document-imports']]
  const assign = useApiMutation((input: { folder: string; matter_id: number | null }) => post<ImportDetail>(`/v1/document-imports/${id}/assign`, input), { invalidate, toastErrors: false })
  const commit = useApiMutation(() => post<ImportDetail>(`/v1/document-imports/${id}/commit`), { invalidate, success: 'Filing the documents in the background', toastErrors: false })
  const undo = useApiMutation(() => post<{ undone: number; kept: string[] }>(`/v1/document-imports/${id}/undo`), { invalidate: [...invalidate, ['files']], success: (r) => `${r.undone} file${r.undone === 1 ? '' : 's'} removed${r.kept.length ? `; ${r.kept.length} kept` : ''}` })
  const [confirmUndo, setConfirmUndo] = useState(false)
  const [kept, setKept] = useState<string[]>([])

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const imp = query.data
  const all = imp.folders.flatMap((f) => f.files)
  const ready = imp.folders.filter((f) => f.matter).flatMap((f) => f.files).filter((f) => f.status === 'ready').length
  const handled = all.filter((f) => f.status !== 'ready').length

  return (
    <Card>
      <CardHeader
        title={<span className="flex items-center gap-2"><FolderArchive className="size-5 text-primary" aria-hidden="true" /> Documents: {imp.filename}</span>}
        description={imp.status === 'previewed' ? 'Preview only: nothing has been filed yet. Match any folder that was not recognised, then import.' : imp.status === 'importing' ? 'Filing in the background; you can leave this page.' : STATUS_LABEL[imp.status].label}
        actions={<Button variant="text" size="sm" icon={<ArrowLeft className="size-4" />} onClick={onBack}>All imports</Button>}
      />
      {imp.status === 'importing' && (
        <div className="border-b border-outline-variant p-4">
          <ProgressBar value={all.length ? (handled / all.length) * 100 : 0} label="Import progress" />
          <p className="mt-2 text-sm text-on-surface-variant">{handled} of {all.length} files done</p>
        </div>
      )}
      {imp.summary && imp.status !== 'importing' && (
        <p className="border-b border-outline-variant p-4 text-sm">
          {imp.summary.imported} imported · {imp.summary.skipped} skipped · {imp.summary.failed} could not be filed{imp.summary.undone ? ` · ${imp.summary.undone} undone` : ''}
        </p>
      )}
      {imp.error && <div className="p-4"><FormError message={imp.error} /></div>}
      <FormError message={assign.error ? ApiError.from(assign.error).message : commit.error ? ApiError.from(commit.error).message : null} />

      <Table caption="Folders in the ZIP" compact>
        <thead><tr><Th>Folder</Th><Th>Matter</Th><Th align="right">Files</Th><Th>Notes</Th></tr></thead>
        <tbody>
          {imp.folders.map((f) => {
            const problems = f.files.filter((x) => x.status === 'skipped' || x.status === 'failed')
            return (
              <tr key={f.folder}>
                <Td className="font-medium">{f.folder || <span className="text-on-surface-variant italic">Files outside any folder</span>}</Td>
                <Td>
                  {imp.status === 'previewed' && f.folder !== '' ? (
                    <div className="w-72">
                      <Select aria-label={`Matter for ${f.folder}`} value={f.matter?.id ?? ''} onChange={(e) => assign.mutate({ folder: f.folder, matter_id: e.target.value ? Number(e.target.value) : null })}>
                        <option value="">Leave out</option>
                        {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
                      </Select>
                    </div>
                  ) : f.matter ? `${f.matter.reference} · ${f.matter.title}` : <span className="text-on-surface-variant">Not filed</span>}
                  {f.matched_by === 'name' && imp.status === 'previewed' && <div className="mt-1 text-xs text-success">Recognised from the folder name</div>}
                </Td>
                <Td align="right" className="whitespace-nowrap">
                  {f.files.length}
                  {f.counts.imported ? <div className="text-xs text-success">{f.counts.imported} imported</div> : null}
                </Td>
                <Td className="max-w-md text-xs text-on-surface-variant">
                  {f.folder === '' && 'Put these in a folder named after the matter.'}
                  {problems.slice(0, 4).map((x) => <div key={`${x.path}/${x.name}`}>{x.name}: {x.reason}</div>)}
                  {problems.length > 4 && <div>…and {problems.length - 4} more</div>}
                </Td>
              </tr>
            )
          })}
        </tbody>
      </Table>

      <div className="flex flex-col gap-3 border-t border-outline-variant p-4 sm:flex-row sm:items-center sm:justify-end">
        {kept.length > 0 && <div className="text-sm text-on-surface-variant sm:mr-auto">Kept: {kept.join('; ')}</div>}
        {imp.status === 'previewed' && (
          <>
            <Button variant="text" onClick={onBack}>Not now</Button>
            <Button loading={commit.isPending} disabled={ready === 0} onClick={() => commit.mutate()}>Import {ready} {ready === 1 ? 'file' : 'files'}</Button>
          </>
        )}
        {imp.can_undo && <Button variant="outlined" icon={<Undo2 className="size-4" />} onClick={() => setConfirmUndo(true)}>Undo this import</Button>}
      </div>

      <ConfirmDialog
        open={confirmUndo}
        onClose={() => setConfirmUndo(false)}
        title="Undo this import?"
        description="The files it filed are removed from their matters (kept for retention, like any removed file). Files used since (marked as an exhibit, in an e-filing, shared with the client, sent in a message) are kept and listed."
        destructive
        confirmLabel="Undo import"
        loading={undo.isPending}
        onConfirm={() => undo.mutate(undefined, { onSuccess: (r) => { setKept(r.kept); setConfirmUndo(false) } })}
      />
    </Card>
  )
}
