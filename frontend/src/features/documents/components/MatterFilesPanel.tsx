import clsx from 'clsx'
import { Download, FileUp, Paperclip, ShieldCheck, Trash2 } from 'lucide-react'
import { useId, useRef, useState, type DragEvent } from 'react'
import { ApiError } from '@/shared/api/axios'
import type { MatterFile } from '@/shared/api/types'
import { dateTime, fileSize } from '@/shared/lib/format'
import { useDebounced } from '@/shared/lib/hooks'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Checkbox, FormError, Input, SearchInput } from '@/shared/ui/Form'
import { IconButton } from '@/shared/ui/Button'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { fileDownloadUrl, useDeleteFile, useMatterFiles, useUpdateFile, useUploadFile } from '../api'
import { FileSearchResults } from './FileSearchResults'

/** Mirrors the server's limits so people learn about a bad file before uploading it. */
const MAX_BYTES = 20 * 1024 * 1024
const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.rtf,.txt,.csv,.jpg,.jpeg,.png,.gif,.webp,.heic,.tif,.tiff,.eml,.msg,.mp3,.m4a,.mp4'
const ACCEPTED = new Set(ACCEPT.split(',').map((ext) => ext.slice(1)))

/** Shown only when a file's contents are not (yet) searchable. */
const TEXT_STATUS: Partial<Record<MatterFile['text_status'], string>> = {
  pending: 'Indexing for search…',
  unsupported: 'Contents not searchable (no readable text)',
  failed: 'Contents could not be read for search',
}

/** Uploaded files on a matter: evidence, scans, correspondence, signed copies. */
export function MatterFilesPanel({ matterId, canEdit }: { matterId: number; canEdit: boolean }) {
  const files = useMatterFiles(matterId)
  const update = useUpdateFile()
  const [removing, setRemoving] = useState<MatterFile | null>(null)
  const remove = useDeleteFile()
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debounced = useDebounced(search)
  const searching = search.trim().length > 0

  return (
    <Card>
      <CardHeader
        title="Files"
        description="Stored privately and scanned for viruses. Share a file to make it downloadable in the client portal."
        actions={<SearchInput value={search} onChange={(v) => { setSearch(v); setPage(1) }} placeholder="Search in these files" label="Search in this matter's files" className="w-64 max-w-full" />}
      />
      {canEdit && !searching && <Uploader matterId={matterId} />}
      {searching ? (
        <FileSearchResults search={debounced} matterId={matterId} page={page} onPage={setPage} />
      ) : files.isPending ? (
        <PageLoader />
      ) : files.isError ? (
        <div className="p-4"><ErrorState error={files.error} onRetry={() => files.refetch()} /></div>
      ) : files.data.length === 0 ? (
        <EmptyState icon={<Paperclip className="size-6" />} title="No files yet" description={canEdit ? 'Upload pleadings, evidence, scans or correspondence.' : undefined} />
      ) : (
        <Table caption="Matter files">
          <thead>
            <tr><Th>Name</Th><Th align="right">Size</Th><Th>Uploaded</Th><Th>Client can see</Th><Th><span className="sr-only">Actions</span></Th></tr>
          </thead>
          <tbody>
            {files.data.map((f) => (
              <tr key={f.id}>
                <Td>
                  <a href={fileDownloadUrl(f.id)} download className="font-medium text-primary hover:underline">{f.name}</a>
                  {f.description && <div className="text-xs text-on-surface-variant">{f.description}</div>}
                  <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-on-surface-variant">
                    <span className="font-mono" title={`SHA-256 ${f.sha256}`}>SHA-256 {f.sha256.slice(0, 12)}…</span>
                    {f.scan_status === 'clean' ? <span className="inline-flex items-center gap-1 text-success"><ShieldCheck className="size-3" aria-hidden="true" /> Virus-scanned</span> : <span>Not virus-scanned</span>}
                    {TEXT_STATUS[f.text_status] && <span>{TEXT_STATUS[f.text_status]}</span>}
                    {f.text_source === 'ocr' && <span title="The text was recognised from page images, so it may contain reading errors.">Text read by OCR</span>}
                  </div>
                </Td>
                <Td align="right" className="whitespace-nowrap tabular-nums">{fileSize(f.size_bytes)}</Td>
                <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(f.created_at)}{f.uploader && <div className="text-xs">{f.uploader.name}</div>}</Td>
                <Td>
                  <Checkbox
                    label={f.shared_with_client ? 'Shared' : 'Internal'}
                    checked={f.shared_with_client}
                    disabled={!canEdit || update.isPending}
                    onChange={(e) => update.mutate({ id: f.id, shared_with_client: e.target.checked })}
                  />
                </Td>
                <Td align="right" className="whitespace-nowrap">
                  <a href={fileDownloadUrl(f.id)} download aria-label={`Download ${f.name}`} className="inline-flex size-9 items-center justify-center rounded-full text-on-surface-variant hover:bg-on-surface/8">
                    <Download className="size-4" />
                  </a>
                  {canEdit && <IconButton label={`Remove ${f.name}`} onClick={() => setRemoving(f)}><Trash2 className="size-4" /></IconButton>}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}

      <ConfirmDialog
        open={removing !== null}
        onClose={() => setRemoving(null)}
        title={`Remove ${removing?.name}?`}
        description="It disappears from the matter and the client portal. The firm keeps the stored copy for its records."
        destructive
        confirmLabel="Remove"
        loading={remove.isPending}
        onConfirm={() => removing && remove.mutate(removing.id, { onSuccess: () => setRemoving(null) })}
      />
    </Card>
  )
}

function Uploader({ matterId }: { matterId: number }) {
  const upload = useUploadFile(matterId)
  const input = useRef<HTMLInputElement>(null)
  const inputId = useId()
  const [dragging, setDragging] = useState(false)
  const [description, setDescription] = useState('')
  const [shared, setShared] = useState(false)
  const [progress, setProgress] = useState<{ name: string; percent: number } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const send = async (list: FileList | null) => {
    const selected = Array.from(list ?? [])
    setError(null)
    for (const file of selected) {
      const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
      if (!ACCEPTED.has(extension)) {
        setError(`${file.name}: this file type isn’t accepted.`)
        continue
      }
      if (file.size > MAX_BYTES) {
        setError(`${file.name} is larger than 20 MB.`)
        continue
      }
      try {
        setProgress({ name: file.name, percent: 0 })
        await upload.mutateAsync({ file, description: description || undefined, shared, onProgress: (percent) => setProgress({ name: file.name, percent }) })
      } catch (err) {
        const apiError = ApiError.from(err)
        setError(`${file.name}: ${apiError.field('file') ?? apiError.message}`)
      }
    }
    setProgress(null)
    setDescription('')
    if (input.current) input.current.value = ''
  }

  const onDrop = (e: DragEvent) => {
    e.preventDefault()
    setDragging(false)
    void send(e.dataTransfer.files)
  }

  return (
    <div className="border-b border-outline-variant p-4">
      <label
        htmlFor={inputId}
        onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
        onDragLeave={() => setDragging(false)}
        onDrop={onDrop}
        className={clsx(
          'flex cursor-pointer flex-col items-center gap-2 rounded-[3px] border-2 border-dashed px-4 py-6 text-center text-sm transition-colors',
          dragging ? 'border-primary bg-primary/5' : 'border-outline-variant hover:border-primary',
        )}
      >
        <FileUp className="size-6 text-primary" aria-hidden="true" />
        <span><span className="font-medium text-primary">Choose files</span> or drag them here</span>
        <span className="text-xs text-on-surface-variant">PDF, Word, Excel, images, email or audio · up to 20 MB each</span>
        <input ref={input} id={inputId} type="file" multiple accept={ACCEPT} className="sr-only" onChange={(e) => void send(e.target.files)} disabled={progress !== null} />
      </label>

      <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
        <Input aria-label="Description for the next upload (optional)" placeholder="Description (optional)" value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} className="flex-1" />
        <Checkbox label="Share with client" checked={shared} onChange={(e) => setShared(e.target.checked)} />
      </div>

      {progress && (
        <div className="mt-3" role="status">
          <p className="mb-1 text-sm">Uploading {progress.name}… {progress.percent}%</p>
          <ProgressBar value={progress.percent} label={`Uploading ${progress.name}`} />
        </div>
      )}
      <div className="mt-3 empty:hidden"><FormError message={error} /></div>
    </div>
  )
}
