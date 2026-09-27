import { FileText, Users } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import { DocumentStatusBadge } from '@/features/matters/components/StatusBadge'
import type { LegalDocument } from '@/shared/api/types'
import { dateTime } from '@/shared/lib/format'
import { EmptyState } from '@/shared/ui/Feedback'
import { Table, Td, Th, Tr } from '@/shared/ui/Layout'

export function DocumentsTable({ documents, showMatter = true }: { documents: LegalDocument[]; showMatter?: boolean }) {
  const navigate = useNavigate()

  if (documents.length === 0) {
    return <EmptyState icon={<FileText className="size-6" />} title="No documents" description="Generate one from a template or start a blank draft." />
  }

  return (
    <Table caption="Documents">
      <thead>
        <tr>
          <Th>Title</Th>
          {showMatter && <Th>Matter</Th>}
          <Th>Status</Th>
          <Th align="right">Version</Th>
          <Th>Last updated</Th>
        </tr>
      </thead>
      <tbody>
        {documents.map((d) => (
          <Tr key={d.id} onClick={() => navigate(`/documents/${d.id}`)}>
            <Td>
              <Link to={`/documents/${d.id}`} onClick={(e) => e.stopPropagation()} className="font-medium hover:text-primary">{d.title}</Link>
              {d.shared_with_client && (
                <span className="ml-2 inline-flex items-center gap-1 text-xs text-on-surface-variant"><Users className="size-3" aria-hidden="true" /> Shared with client</span>
              )}
            </Td>
            {showMatter && <Td className="text-on-surface-variant">{d.matter?.reference}</Td>}
            <Td><DocumentStatusBadge status={d.status} /></Td>
            <Td align="right">v{d.current_version}</Td>
            <Td className="whitespace-nowrap text-on-surface-variant">{dateTime(d.updated_at)}</Td>
          </Tr>
        ))}
      </tbody>
    </Table>
  )
}
