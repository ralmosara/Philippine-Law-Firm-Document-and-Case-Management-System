import { FileSearch, Paperclip } from 'lucide-react'
import { Link } from 'react-router-dom'
import { dateTime, fileSize } from '@/shared/lib/format'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Highlight } from '@/shared/ui/Highlight'
import { Pagination } from '@/shared/ui/Layout'
import { fileDownloadUrl, useFileSearch } from '../api'

/** Files whose name, description or text match the search, with the matching passage. */
export function FileSearchResults({ search, matterId, page, onPage }: { search: string; matterId?: number; page: number; onPage: (page: number) => void }) {
  const results = useFileSearch({ search, matter_id: matterId, page })

  if (search.trim().length < 2) {
    return <EmptyState icon={<FileSearch className="size-6" />} title="Search inside files" description="Type at least two characters. Searches PDF, Word, Excel, PowerPoint, text and email files." />
  }
  if (results.isPending) return <PageLoader label="Searching…" />
  if (results.isError) return <div className="p-4"><ErrorState error={results.error} onRetry={() => results.refetch()} /></div>
  if (results.data.data.length === 0) {
    return <EmptyState icon={<FileSearch className="size-6" />} title="No files match" description="Every word must appear. Scanned images and old .doc/.xls files can only be found by name or description." />
  }

  return (
    <>
      <p className="px-5 pt-4 text-sm text-on-surface-variant" role="status">
        {results.data.meta.total} {results.data.meta.total === 1 ? 'file' : 'files'} found
      </p>
      <ul className="divide-y divide-outline-variant">
        {results.data.data.map((f) => (
          <li key={f.id} className="flex gap-3 px-5 py-4">
            <Paperclip className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
            <div className="min-w-0 flex-1">
              <a href={fileDownloadUrl(f.id)} download className="font-medium break-words text-primary hover:underline">{f.name}</a>
              <p className="text-xs text-on-surface-variant">
                {!matterId && f.matter && (
                  <>
                    <Link to={`/matters/${f.matter.id}?tab=files`} className="hover:underline">{f.matter.reference} · {f.matter.title}</Link>
                    {' · '}
                  </>
                )}
                {fileSize(f.size_bytes)} · {dateTime(f.created_at)}{f.uploader && ` · ${f.uploader.name}`}
              </p>
              {f.description && <p className="mt-1 text-sm text-on-surface-variant">{f.description}</p>}
              {f.snippet && <Highlight text={f.snippet} className="mt-2 block text-sm leading-6 break-words text-on-surface" />}
            </div>
          </li>
        ))}
      </ul>
      <Pagination page={results.data} onPage={onPage} />
    </>
  )
}
