import { Plus, UserRound } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useDebounced, useUrlPage, useUrlState } from '@/shared/lib/hooks'
import { Button } from '@/shared/ui/Button'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { SearchInput, Select } from '@/shared/ui/Form'
import { Card, PageHeader, Pagination, Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useClients } from '../api'
import { ClientForm } from './ClientForm'

export function ClientsList() {
  const navigate = useNavigate()
  const [search, setSearch] = useUrlState('q')
  const [type, setType] = useUrlState('type')
  const [page, setPage] = useUrlPage()
  const [creating, setCreating] = useState(false)
  const query = useClients({ search: useDebounced(search), type, page })

  return (
    <>
      <PageHeader
        title="Clients"
        description="Individuals and organisations the firm represents."
        actions={<Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>New client</Button>}
      />

      <Card>
        <div className="flex flex-col gap-3 border-b border-outline-variant p-4 sm:flex-row">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, email or TIN" className="flex-1" />
          <Select aria-label="Client type" value={type} onChange={(e) => setType(e.target.value)} className="sm:w-48">
            <option value="">All types</option>
            <option value="individual">Individuals</option>
            <option value="corporate">Corporations</option>
          </Select>
        </div>

        {query.isPending ? (
          <PageLoader />
        ) : query.isError ? (
          <div className="p-4"><ErrorState error={query.error} onRetry={() => query.refetch()} /></div>
        ) : query.data.data.length === 0 ? (
          <EmptyState icon={<UserRound className="size-6" />} title={search ? 'No clients match your search' : 'No clients yet'} />
        ) : (
          <Table caption="Clients">
            <thead>
              <tr><Th>Name</Th><Th>Contact</Th><Th>TIN</Th><Th align="right">Active matters</Th><Th>Portal</Th></tr>
            </thead>
            <tbody>
              {query.data.data.map((c) => (
                <Tr key={c.id} onClick={() => navigate(`/clients/${c.id}`)}>
                  <Td>
                    <Link to={`/clients/${c.id}`} onClick={(e) => e.stopPropagation()} className="font-medium hover:text-primary">{c.name}</Link>
                    <div className="text-xs text-on-surface-variant capitalize">{c.type}</div>
                  </Td>
                  <Td className="text-on-surface-variant">
                    <div>{c.email ?? '—'}</div>
                    <div className="text-xs">{c.phone}</div>
                  </Td>
                  <Td className="text-on-surface-variant tabular-nums">{c.tin ?? '—'}</Td>
                  <Td align="right">{c.active_matters_count ?? 0} <span className="text-on-surface-variant">/ {c.matters_count ?? 0}</span></Td>
                  <Td>{c.portal_enabled ? <Badge tone="success">Enabled</Badge> : <Badge>Off</Badge>}</Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        )}
        <Pagination page={query.data} onPage={setPage} />
      </Card>

      <ClientForm open={creating} onClose={() => setCreating(false)} />
    </>
  )
}
