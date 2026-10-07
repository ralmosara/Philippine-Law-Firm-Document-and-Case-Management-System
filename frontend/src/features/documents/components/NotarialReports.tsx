import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, FileDown } from 'lucide-react'
import { useState } from 'react'
import { useAbilities } from '@/features/auth/session'
import { post, get } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { date, dateTime } from '@/shared/lib/format'
import { Button, DownloadButton } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Select } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

interface Month { month: string; entries: number; submitted_at: string | null; submitted_by: string | null; notes: string | null }
interface Reports {
  notary: { id: number; name: string; commission_number: string | null; commission_place: string | null; commission_expires_on: string | null }
  notaries: { id: number; name: string }[]
  due_day: number
  months: Month[]
}

const label = (m: string) => new Date(`${m}-01T00:00:00`).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })

/**
 * The monthly report to the clerk of court: a certified copy of the month's
 * register entries (or a statement of none), due by the 10th of the next month.
 */
export function NotarialReports() {
  const abilities = useAbilities()
  const [notaryId, setNotaryId] = useState<string>('')
  const query = useQuery({ queryKey: ['notarial-reports', notaryId], queryFn: () => get<Reports>('/v1/notarial-reports', notaryId ? { notary_id: notaryId } : {}) })
  const [marking, setMarking] = useState<Month | null>(null)
  const submit = useApiMutation((input: { month: string; notes: string }) => post(`/v1/notarial-reports/${input.month}/submitted${notaryId ? `?notary_id=${notaryId}` : ''}`, { notes: input.notes || null }), { invalidate: [['notarial-reports']], success: 'Marked as submitted' })

  if (query.isPending) return <Card><PageLoader /></Card>
  if (query.isError) return <Card><div className="p-4"><ErrorState error={query.error} /></div></Card>
  const r = query.data
  const q = notaryId ? `?notary_id=${notaryId}` : ''

  return (
    <Card>
      <CardHeader
        title="Monthly reports to the clerk of court"
        description={`A certified copy of each month's register entries, or a statement that there were none, due by the ${r.due_day}th of the next month. Download, sign, file it, then mark it submitted.`}
        actions={abilities.manage_firm && r.notaries.length > 0 && (
          <Select aria-label="Notary" value={notaryId} onChange={(e) => setNotaryId(e.target.value)} className="w-56">
            <option value="">Me</option>
            {r.notaries.map((n) => <option key={n.id} value={n.id}>{n.name}</option>)}
          </Select>
        )}
      />
      {!r.notary.commission_number && <p className="mx-5 mt-4 rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">No notarial commission on file for {r.notary.name}. Add it under Profile → Signature details so it prints on the report.</p>}
      <Table caption="Monthly notarial reports" compact>
        <thead><tr><Th>Month</Th><Th align="right">Entries</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
        <tbody>
          {r.months.map((m) => (
            <tr key={m.month}>
              <Td className="font-medium">{label(m.month)}</Td>
              <Td align="right">{m.entries || 'None'}</Td>
              <Td className="text-sm">{m.submitted_at ? <><Badge tone="success">Submitted {date(m.submitted_at)}</Badge><div className="text-xs text-on-surface-variant">{m.submitted_by}{m.notes && ` · ${m.notes}`}</div></> : <Badge tone="warning">Not submitted</Badge>}</Td>
              <Td align="right" className="whitespace-nowrap">
                <DownloadButton size="sm" variant="text" href={`/api/v1/notarial-reports/${m.month}/pdf${q}`} icon={<FileDown className="size-4" />}>Report</DownloadButton>
                {!m.submitted_at && <Button size="sm" variant="tonal" icon={<CheckCircle2 className="size-4" />} onClick={() => setMarking(m)}>Mark submitted</Button>}
              </Td>
            </tr>
          ))}
        </tbody>
      </Table>
      <ConfirmDialog
        open={marking !== null}
        onClose={() => setMarking(null)}
        title={`${marking ? label(marking.month) : ''}: mark as submitted?`}
        description={`Record that the report was filed with the clerk of court. Reminders stop for this month. ${dateTime(new Date().toISOString())}.`}
        reasonLabel="Notes (optional)"
        confirmLabel="Mark submitted"
        loading={submit.isPending}
        onConfirm={(notes) => marking && submit.mutate({ month: marking.month, notes }, { onSuccess: () => setMarking(null) })}
      />
    </Card>
  )
}
