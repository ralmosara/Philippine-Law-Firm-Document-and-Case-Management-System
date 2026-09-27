import { FilePlus2 } from 'lucide-react'
import { useState } from 'react'
import { useAbilities } from '@/features/auth/session'
import { useDebounced, useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { SearchInput, Select } from '@/shared/ui/Form'
import { Card, PageHeader, Pagination, Tabs } from '@/shared/ui/Layout'
import { useDocuments } from '../api'
import { DocumentsTable } from './DocumentsTable'
import { FileSearchResults } from './FileSearchResults'
import { NotarialRegister } from './NotarialRegister'
import { TemplatePicker } from './TemplatePicker'
import { TemplatesManager } from './TemplatesManager'

type Tab = 'documents' | 'files' | 'templates' | 'notarial'

export function DocumentsDashboard() {
  const abilities = useAbilities()
  const [tab, setTab] = useUrlState('tab', 'documents')

  return (
    <>
      <PageHeader title="Documents" description="Drafts, templates and the notarial register." />
      <Tabs<Tab>
        label="Document sections"
        value={tab as Tab}
        onChange={setTab}
        tabs={[
          { value: 'documents', label: 'All documents' },
          { value: 'files', label: 'Search files' },
          { value: 'templates', label: 'Templates' },
          ...(abilities.practice_law ? [{ value: 'notarial' as const, label: 'Notarial register' }] : []),
        ]}
      />
      {tab === 'documents' && <DocumentsTab />}
      {tab === 'files' && <FilesSearchTab />}
      {tab === 'templates' && <TemplatesManager />}
      {tab === 'notarial' && abilities.practice_law && <NotarialRegister />}
    </>
  )
}

function FilesSearchTab() {
  const [search, setSearch] = useUrlState('q')
  const [page, setPage] = useUrlPage()
  const debounced = useDebounced(search)

  return (
    <Card>
      <div className="border-b border-outline-variant p-4">
        {/* Changing the query already resets the page (useUrlState). */}
        <SearchInput value={search} onChange={setSearch} placeholder="Search inside uploaded files across all matters" label="Search files" />
      </div>
      <FileSearchResults search={debounced} page={page} onPage={setPage} />
    </Card>
  )
}

function DocumentsTab() {
  const abilities = useAbilities()
  const [search, setSearch] = useUrlState('q')
  const [status, setStatus] = useUrlState('status')
  const [page, setPage] = useUrlPage()
  const [creating, setCreating] = useState(false)
  const query = useDocuments({ search: useDebounced(search), status, page })

  return (
    <Card>
      <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-center">
        <SearchInput value={search} onChange={setSearch} placeholder="Search documents" className="flex-1" />
        <Select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className="sm:w-44">
          <option value="">Any status</option>
          <option value="draft">Draft</option>
          <option value="final">Final</option>
          <option value="signed">Signed</option>
          <option value="notarized">Notarized</option>
        </Select>
        {abilities.work_matters && <Button icon={<FilePlus2 className="size-4" />} onClick={() => setCreating(true)}>New document</Button>}
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : <DocumentsTable documents={query.data.data} />}
      <Pagination page={query.data} onPage={setPage} />
      <TemplatePicker open={creating} onClose={() => setCreating(false)} />
    </Card>
  )
}
