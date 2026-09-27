import { Briefcase, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAbilities, useLookups } from '@/features/auth/session'
import type { MatterStatus } from '@/shared/api/types'
import { date, relativeDays } from '@/shared/lib/format'
import { useDebounced, useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, SearchInput, Select } from '@/shared/ui/Form'
import { Card, PageHeader, Pagination, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useMatters } from '../api'
import { MatterForm } from './MatterForm'
import { MatterStatusBadge } from './StatusBadge'

export function MattersList() {
  const abilities = useAbilities()
  const lookups = useLookups()
  const navigate = useNavigate()
  const [search, setSearch] = useUrlState('q')
  const [status, setStatus] = useUrlState('status')
  const [mine, setMine] = useUrlState('mine')
  const [page, setPage] = useUrlPage()
  const [creating, setCreating] = useState(false)
  const debouncedSearch = useDebounced(search)

  const query = useMatters({
    search: debouncedSearch,
    status: status as MatterStatus | '',
    mine: mine === '1',
    active_only: status === '',
    page,
  })

  return (
    <>
      <PageHeader
        title="Matters"
        description="Every case and engagement handled by the firm."
        actions={abilities.work_matters && <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>New matter</Button>}
      />

      <Card>
        <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row sm:items-center">
          <SearchInput value={search} onChange={setSearch} placeholder="Search title, reference, case no. or client" className="flex-1" />
          <Select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className="sm:w-48">
            <option value="">Active matters</option>
            {lookups.data?.matter_statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </Select>
          <Checkbox label="Only mine" checked={mine === '1'} onChange={(e) => setMine(e.target.checked ? '1' : '')} />
        </div>

        {query.isPending ? (
          <PageLoader />
        ) : query.isError ? (
          <div className="p-4"><ErrorState error={query.error} onRetry={() => query.refetch()} /></div>
        ) : query.data.data.length === 0 ? (
          <EmptyState
            icon={<Briefcase className="size-6" />}
            title={search || status || mine ? 'No matters match your filters' : 'No matters yet'}
            description={search || status || mine ? 'Try a different search or status.' : 'Open your first matter to start tracking deadlines, documents and time.'}
          />
        ) : (
          <Table caption="Matters">
            <thead>
              <tr>
                <Th>Matter</Th>
                <Th>Client</Th>
                <Th>Status</Th>
                <Th>Responsible</Th>
                <Th>Next deadline</Th>
                <Th>Opened</Th>
              </tr>
            </thead>
            <tbody>
              {query.data.data.map((m) => (
                <Tr key={m.id} onClick={() => navigate(`/matters/${m.id}`)}>
                  <Td>
                    <Link to={`/matters/${m.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-on-surface hover:text-primary">{m.title}</Link>
                    <div className="text-xs text-on-surface-variant">{m.reference}{m.case_number && ` · ${m.case_number}`}</div>
                  </Td>
                  <Td>{m.client?.name}</Td>
                  <Td><MatterStatusBadge status={m.status} label={m.status_label} /></Td>
                  <Td className="text-on-surface-variant">{m.responsible_lawyer?.name ?? 'Unassigned'}</Td>
                  <Td>
                    {m.next_deadline ? (
                      <>
                        <div className="max-w-48 truncate">{m.next_deadline.title}</div>
                        <div className={m.next_deadline.days_remaining <= 3 ? 'text-xs font-medium text-danger' : 'text-xs text-on-surface-variant'}>
                          {date(m.next_deadline.due_date)} · {relativeDays(m.next_deadline.days_remaining)}
                        </div>
                      </>
                    ) : (
                      <span className="text-on-surface-variant">—</span>
                    )}
                  </Td>
                  <Td className="whitespace-nowrap text-on-surface-variant">{date(m.opened_at)}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
        <Pagination page={query.data} onPage={setPage} />
      </Card>

      <MatterForm open={creating} onClose={() => setCreating(false)} />
    </>
  )
}
