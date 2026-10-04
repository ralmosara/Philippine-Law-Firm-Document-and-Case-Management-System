import { ArrowLeftRight, FileDown } from 'lucide-react'
import { useState } from 'react'
import ReactDiffViewer, { DiffMethod } from 'react-diff-viewer-continued'
import { DownloadButton, IconButton } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, Select } from '@/shared/ui/Form'
import { Tabs } from '@/shared/ui/Layout'
import { comparePdfUrl, useCompareSources, useComparison, type CompareSources, type Comparison } from '../api'

const prefersDark = () => typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches

type View = 'redline' | 'side'

/**
 * Compare any two versions of a document, or a version with a file in the
 * matter (the other side's marked-up draft, as Word or a text PDF), and
 * download the redline as a PDF to send or keep.
 */
export function CompareDialog({ documentId, initial, onClose }: { documentId: number; initial: [string, string] | null; onClose: () => void }) {
  const open = initial !== null
  const [sides, setSides] = useState<[string, string] | null>(initial)
  const [view, setView] = useState<View>('redline')
  // A new starting pair (another "Compare" button) replaces the current one.
  const [shown, setShown] = useState(initial)
  if (initial !== shown) {
    setShown(initial)
    setSides(initial)
  }

  const sources = useCompareSources(documentId, open)
  const [base, other] = sides ?? [null, null]
  const comparison = useComparison(documentId, base, other)
  const same = !!base && base === other

  return (
    <Dialog open={open} onClose={onClose} title="Compare" size="xl">
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="min-w-0 flex-1">
          <SideSelect label="Original" value={base} sources={sources.data} onChange={(v) => setSides([v, other ?? v])} />
        </div>
        <IconButton label="Swap" className="self-center sm:mb-1" onClick={() => base && other && setSides([other, base])}>
          <ArrowLeftRight className="size-4" />
        </IconButton>
        <div className="min-w-0 flex-1">
          <SideSelect label="Compared with" value={other} sources={sources.data} onChange={(v) => setSides([base ?? v, v])} />
        </div>
      </div>

      {same ? (
        <p className="rounded-[3px] bg-surface-container p-4 text-sm">Choose two different versions or files.</p>
      ) : comparison.isPending ? (
        <PageLoader />
      ) : comparison.isError ? (
        <ErrorState error={comparison.error} />
      ) : (
        <Result comparison={comparison.data} view={view} onView={setView} pdfHref={base && other ? comparePdfUrl(documentId, base, other) : null} />
      )}
    </Dialog>
  )
}

function SideSelect({ label, value, sources, onChange }: { label: string; value: string | null; sources: CompareSources | undefined; onChange: (value: string) => void }) {
  return (
    <Field label={label}>
      {(a) => (
        <Select {...a} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          {!sources && value && <option value={value}>{value.startsWith('v:') ? `Version ${value.slice(2)}` : 'File'}</option>}
          {sources && (
            <>
              <optgroup label="Versions of this document">
                {sources.versions.map((v) => <option key={v.value} value={v.value}>{v.label} · {v.detail}{v.summary ? ` · ${v.summary}` : ''}</option>)}
              </optgroup>
              {sources.files.length > 0 && (
                <optgroup label="Files in this matter">
                  {sources.files.map((f) => <option key={f.value} value={f.value}>{f.label} · {f.detail}</option>)}
                </optgroup>
              )}
            </>
          )}
        </Select>
      )}
    </Field>
  )
}

function Result({ comparison: c, view, onView, pdfHref }: { comparison: Comparison; view: View; onView: (v: View) => void; pdfHref: string | null }) {
  const unchanged = c.inserted_words === 0 && c.deleted_words === 0
  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm" role="status">
          {unchanged ? 'No differences in wording.' : (
            <>
              <span className="font-medium text-primary">{c.inserted_words.toLocaleString()} {c.inserted_words === 1 ? 'word' : 'words'} added</span>
              {' · '}
              <span className="font-medium text-danger">{c.deleted_words.toLocaleString()} removed</span>
            </>
          )}
        </p>
        {pdfHref && <DownloadButton href={pdfHref} variant="tonal" size="sm" icon={<FileDown className="size-4" />}>Download redline PDF</DownloadButton>}
      </div>
      <Tabs<View> label="Comparison view" value={view} onChange={onView} tabs={[{ value: 'redline', label: 'Redline' }, { value: 'side', label: 'Side by side' }]} />
      {view === 'redline' ? (
        <div className="max-h-[60vh] overflow-y-auto rounded-[3px] border border-outline-variant p-5 sm:px-8">
          <p className="mb-4 text-xs text-on-surface-variant">
            <span className="font-medium text-primary underline">Added</span> · <span className="font-medium text-danger line-through">Removed</span> · comparing {c.base.label} with {c.other.label}
          </p>
          <div className="font-serif text-[15px] leading-7 break-words whitespace-pre-wrap">
            {c.segments.map((s, i) =>
              s.type === 'insert' ? <ins key={i} className="text-primary underline decoration-1 underline-offset-2 [del+&]:ml-1">{s.text}</ins>
                : s.type === 'delete' ? <del key={i} className="text-danger line-through">{s.text}</del>
                  : <span key={i}>{s.text}</span>,
            )}
          </div>
        </div>
      ) : (
        <div className="max-h-[60vh] overflow-auto rounded-[3px] border border-outline-variant text-sm">
          <ReactDiffViewer
            oldValue={c.base.content}
            newValue={c.other.content}
            splitView
            compareMethod={DiffMethod.WORDS}
            useDarkTheme={prefersDark()}
            leftTitle={`${c.base.label} · ${c.base.detail}`}
            rightTitle={`${c.other.label} · ${c.other.detail}`}
          />
        </div>
      )}
    </>
  )
}
