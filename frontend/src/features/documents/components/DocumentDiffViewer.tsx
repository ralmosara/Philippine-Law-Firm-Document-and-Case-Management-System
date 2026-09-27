import ReactDiffViewer, { DiffMethod } from 'react-diff-viewer-continued'
import type { DocumentVersion } from '@/shared/api/types'
import { dateTime } from '@/shared/lib/format'

const prefersDark = () => typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches

/** Side-by-side redline between two document versions. */
export function DocumentDiffViewer({ before, after }: { before: DocumentVersion; after: DocumentVersion }) {
  return (
    <div className="overflow-hidden rounded-xl border border-outline-variant text-sm">
      <ReactDiffViewer
        oldValue={before.content}
        newValue={after.content}
        splitView
        compareMethod={DiffMethod.WORDS}
        useDarkTheme={prefersDark()}
        leftTitle={`Version ${before.version_number} · ${dateTime(before.created_at)}`}
        rightTitle={`Version ${after.version_number} · ${dateTime(after.created_at)}${after.creator ? ` · ${after.creator.name}` : ''}`}
      />
    </div>
  )
}
