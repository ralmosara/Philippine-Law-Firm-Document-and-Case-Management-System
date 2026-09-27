import { ArrowLeft, CheckCircle2, FileSpreadsheet, FileDown, Undo2, Upload } from 'lucide-react'
import { useId, useRef, useState } from 'react'
import { ApiError } from '@/shared/api/axios'
import { dateTime } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { FormError, Select } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { useCommitImport, useImport, useImports, useImportTypes, usePreviewImport, useUndoImport, type DataImport, type ImportTypeInfo, type RowStatus } from '../api'

const STATUS: Record<RowStatus, { label: string; tone: 'success' | 'warning' | 'danger' }> = {
  ready: { label: 'Ready', tone: 'success' },
  duplicate: { label: 'Already there', tone: 'warning' },
  error: { label: 'Needs fixing', tone: 'danger' },
}

/** Bring existing records in from spreadsheets: template, preview, import, undo. */
export function ImportPanel() {
  const [openId, setOpenId] = useState<number | null>(null)

  return openId !== null ? <ImportPreview id={openId} onBack={() => setOpenId(null)} /> : (
    <div className="flex flex-col gap-6">
      <ImportSteps onPreviewed={setOpenId} />
      <ImportHistory onOpen={setOpenId} />
    </div>
  )
}

function ImportSteps({ onPreviewed }: { onPreviewed: (id: number) => void }) {
  const types = useImportTypes()

  return (
    <Card>
      <CardHeader
        title="Import from spreadsheets"
        description="Bring your existing records over from Excel or CSV. Import them in this order: matters refer to clients, deadlines and trust balances refer to matters. You see a preview before anything is saved, and can undo an import for 7 days."
      />
      {types.isPending ? <PageLoader /> : types.isError ? <div className="p-4"><ErrorState error={types.error} /></div> : (
        <ol className="divide-y divide-outline-variant">
          {types.data.map((t, i) => <ImportStep key={t.type} step={i + 1} info={t} onPreviewed={onPreviewed} />)}
        </ol>
      )}
    </Card>
  )
}

function ImportStep({ step, info, onPreviewed }: { step: number; info: ImportTypeInfo; onPreviewed: (id: number) => void }) {
  const preview = usePreviewImport()
  const input = useRef<HTMLInputElement>(null)
  const inputId = useId()
  const required = info.columns.filter((c) => c.required).map((c) => c.label)

  const upload = (file: File | undefined) => {
    if (!file) return
    preview.mutate({ type: info.type, file }, { onSuccess: (i) => onPreviewed(i.id) })
    if (input.current) input.current.value = ''
  }

  return (
    <li className="flex flex-col gap-3 p-5 sm:flex-row sm:items-start">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-container text-sm font-semibold text-on-primary-container" aria-hidden>{step}</span>
      <div className="min-w-0 flex-1">
        <h3 className="font-medium">{info.label}</h3>
        <p className="text-sm text-on-surface-variant">Required: {required.join(', ')}.</p>
        <details className="mt-2 text-sm">
          <summary className="cursor-pointer text-primary">All columns</summary>
          <dl className="mt-2 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-[max-content_1fr]">
            {info.columns.map((c) => (
              <div key={c.key} className="contents">
                <dt className="font-medium">{c.label}{c.required && <span className="text-danger"> *</span>}</dt>
                <dd className="text-on-surface-variant">{c.hint ?? `e.g. ${c.example}`}</dd>
              </div>
            ))}
          </dl>
        </details>
        {preview.isError && <div className="mt-2"><FormError message={ApiError.from(preview.error).message} /></div>}
      </div>
      <div className="flex shrink-0 flex-wrap gap-2 sm:justify-end">
        <DownloadButton href={`/api/v1/imports/template/${info.type}`} size="sm" icon={<FileDown className="size-4" />}>Template</DownloadButton>
        <input ref={input} id={inputId} type="file" accept=".csv,.xlsx" className="sr-only" onChange={(e) => upload(e.target.files?.[0])} disabled={!info.allowed || preview.isPending} />
        <Button size="sm" variant="tonal" icon={<Upload className="size-4" />} loading={preview.isPending} disabled={!info.allowed} onClick={() => input.current?.click()} aria-label={`Upload ${info.label.toLowerCase()} file`}>
          Upload file
        </Button>
        {!info.allowed && <p className="basis-full text-xs text-on-surface-variant sm:text-right">Needs a partner who manages finances.</p>}
      </div>
    </li>
  )
}

function ImportPreview({ id, onBack }: { id: number; onBack: () => void }) {
  const query = useImport(id)
  const commit = useCommitImport()
  const undo = useUndoImport()
  const [filter, setFilter] = useState<RowStatus | ''>('')
  const [confirmUndo, setConfirmUndo] = useState(false)

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  const imp = query.data
  const s = imp.summary
  const rows = filter ? imp.rows.filter((r) => r.status === filter) : imp.rows
  const shown = imp.columns.filter((c) => imp.rows.some((r) => (r.raw[c.key] ?? '') !== '')).slice(0, 5)

  return (
    <Card>
      <CardHeader
        title={<span className="flex items-center gap-2"><FileSpreadsheet className="size-5 text-on-surface-variant" aria-hidden /> {imp.label}: {imp.filename}</span>}
        description={
          imp.status === 'previewed' ? 'Preview only: nothing has been saved yet.'
            : imp.status === 'committed' ? `Imported ${s.created ?? 0} on ${dateTime(imp.committed_at)}.`
              : `Undone on ${dateTime(imp.undone_at)}: ${s.undone ?? 0} removed.`
        }
        actions={<Button variant="text" size="sm" icon={<ArrowLeft className="size-4" />} onClick={onBack}>All imports</Button>}
      />

      <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-center">
        <div className="flex flex-wrap gap-2 text-sm" aria-live="polite">
          <Badge tone="success">{s.ready} ready</Badge>
          <Badge tone="warning">{s.duplicate} already there</Badge>
          <Badge tone="danger">{s.error} need fixing</Badge>
          <span className="text-on-surface-variant">of {s.total} rows</span>
        </div>
        <Select aria-label="Show rows" value={filter} onChange={(e) => setFilter(e.target.value as RowStatus | '')} className="sm:ml-auto sm:w-48">
          <option value="">All rows</option>
          <option value="ready">Ready</option>
          <option value="duplicate">Already there</option>
          <option value="error">Need fixing</option>
        </Select>
      </div>

      {!!s.ignored_columns?.length && (
        <p className="border-b border-outline-variant bg-surface-container px-4 py-2 text-sm text-on-surface-variant">
          Columns not used: {s.ignored_columns.join(', ')}. Rename a header to match the template if it should be imported.
        </p>
      )}
      {!!s.kept?.length && (
        <div className="border-b border-outline-variant bg-warning-container px-4 py-2 text-sm text-on-warning-container">
          <p className="font-medium">Kept because they are in use:</p>
          <ul className="list-disc pl-5">{s.kept.map((k) => <li key={k}>{k}</li>)}</ul>
        </div>
      )}

      {rows.length === 0 ? <EmptyState title="No rows here" /> : (
        <Table caption="Import rows">
          <thead>
            <tr><Th>Row</Th><Th>Status</Th>{shown.map((c) => <Th key={c.key}>{c.label}</Th>)}<Th>Notes</Th></tr>
          </thead>
          <tbody>
            {rows.slice(0, 500).map((r) => (
              <tr key={r.line}>
                <Td className="text-on-surface-variant tabular-nums">{r.line}</Td>
                <Td>
                  {r.created ? <Badge tone="success"><CheckCircle2 className="size-3" aria-hidden /> Imported</Badge> : <Badge tone={STATUS[r.status].tone}>{STATUS[r.status].label}</Badge>}
                </Td>
                {shown.map((c) => <Td key={c.key} className="max-w-56 truncate">{r.raw[c.key] || '—'}</Td>)}
                <Td className="min-w-64 text-sm">
                  {r.messages.map((m) => <div key={m} className={r.status === 'error' ? 'text-danger' : 'text-on-surface-variant'}>{m}</div>)}
                  {r.warnings.map((m) => <div key={m} className="text-on-surface-variant">{m}</div>)}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {rows.length > 500 && <p className="p-4 text-sm text-on-surface-variant">Showing the first 500 of {rows.length} rows.</p>}

      <div className="flex flex-col gap-3 border-t border-outline-variant p-4 sm:flex-row sm:items-center sm:justify-end">
        {commit.isError && <div className="sm:mr-auto"><FormError message={ApiError.from(commit.error).message} /></div>}
        {imp.status === 'previewed' && (
          <>
            {s.error + s.duplicate > 0 && <p className="text-sm text-on-surface-variant sm:mr-auto">Rows that need fixing or are already there are skipped. Fix the file and upload it again to include them.</p>}
            <Button variant="text" onClick={onBack}>Cancel</Button>
            <Button loading={commit.isPending} disabled={s.ready === 0} onClick={() => commit.mutate(imp.id, { onError: () => query.refetch() })}>
              Import {s.ready} {s.ready === 1 ? 'row' : 'rows'}
            </Button>
          </>
        )}
        {imp.can_undo && <Button variant="outlined" icon={<Undo2 className="size-4" />} onClick={() => setConfirmUndo(true)}>Undo this import</Button>}
      </div>

      <ConfirmDialog
        open={confirmUndo}
        onClose={() => setConfirmUndo(false)}
        title="Undo this import?"
        description="Records it created are removed. Any that have been used since (a client with a matter, a deadline already worked on) are kept and listed. Trust balances are reversed in the ledger, not erased."
        destructive
        confirmLabel="Undo import"
        loading={undo.isPending}
        onConfirm={() => undo.mutate(imp.id, { onSuccess: () => setConfirmUndo(false) })}
      />
    </Card>
  )
}

function ImportHistory({ onOpen }: { onOpen: (id: number) => void }) {
  const history = useImports()

  return (
    <Card>
      <CardHeader title="Past imports" />
      {history.isPending ? <PageLoader /> : history.isError ? <div className="p-4"><ErrorState error={history.error} /></div> : history.data.length === 0 ? (
        <EmptyState icon={<FileSpreadsheet className="size-6" />} title="No imports yet" />
      ) : (
        <Table caption="Past imports" compact>
          <thead><tr><Th>File</Th><Th>What</Th><Th>Status</Th><Th>When</Th><Th><span className="sr-only">Open</span></Th></tr></thead>
          <tbody>
            {history.data.map((i: DataImport) => (
              <tr key={i.id}>
                <Td className="max-w-64 truncate font-medium">{i.filename}</Td>
                <Td>{i.label}</Td>
                <Td>
                  {i.status === 'committed' ? <Badge tone="success">{i.summary.created ?? 0} imported</Badge>
                    : i.status === 'undone' ? <Badge>Undone</Badge>
                      : <Badge tone="warning">Preview, not imported</Badge>}
                </Td>
                <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(i.committed_at ?? i.created_at)}{i.created_by && <div className="text-xs">{i.created_by}</div>}</Td>
                <Td align="right"><Button size="sm" variant="text" onClick={() => onOpen(i.id)}>{i.status === 'previewed' ? 'Review' : 'View'}</Button></Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  )
}
