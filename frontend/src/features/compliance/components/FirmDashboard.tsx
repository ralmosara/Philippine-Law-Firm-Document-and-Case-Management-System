import { GraduationCap } from 'lucide-react'
import { date } from '@/shared/lib/format'
import { Badge, EmptyState, ErrorState, PageLoader, ProgressBar } from '@/shared/ui/Feedback'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'
import { useFirmMcle } from '../api'

/** MCLE standing of every lawyer in the firm, least compliant first. */
export function FirmDashboard() {
  const query = useFirmMcle()

  if (query.isPending) return <PageLoader />
  if (query.isError) return <ErrorState error={query.error} />
  if (!query.data.period) return <EmptyState icon={<GraduationCap className="size-6" />} title="No active compliance period" />

  const behind = query.data.data.filter((l) => !l.is_compliant).length

  return (
    <Card>
      <CardHeader
        title={query.data.period.name}
        description={`Ends ${date(query.data.period.end_date)} · ${behind === 0 ? 'All lawyers are compliant' : `${behind} lawyer(s) still need units`}`}
      />
      <Table caption="Firm MCLE compliance">
        <thead><tr><Th>Lawyer</Th><Th>Roll No.</Th><Th className="w-1/3">Progress</Th><Th align="right">Units</Th><Th>Status</Th></tr></thead>
        <tbody>
          {query.data.data.map((l) => (
            <tr key={l.user_id}>
              <Td className="font-medium">{l.name}</Td>
              <Td className="text-on-surface-variant tabular-nums">{l.roll_number ?? '—'}</Td>
              <Td><ProgressBar value={l.percent} label={`${l.name} MCLE progress`} tone={l.is_compliant ? 'success' : l.percent < 50 ? 'danger' : 'warning'} /></Td>
              <Td align="right">{l.earned_units} / {l.required_units}</Td>
              <Td>{l.is_compliant ? <Badge tone="success">Compliant</Badge> : <Badge tone="warning">{l.remaining_units} to go</Badge>}</Td>
            </tr>
          ))}
        </tbody>
      </Table>
    </Card>
  )
}
