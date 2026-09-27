import { Clock, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useCurrentSession } from '@/features/auth/session'
import type { TimeEntry } from '@/shared/api/types'
import { date, duration, money } from '@/shared/lib/format'
import { IconButton } from '@/shared/ui/Button'
import { ConfirmDialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState } from '@/shared/ui/Feedback'
import { Table, Td, Th, Tr } from '@/shared/ui/Layout'
import { useDeleteTimeEntry } from '../api'
import { TimeTrackingForm } from './TimeTrackingForm'

export function TimeEntriesTable({ entries, showMatter = true, totals }: { entries: TimeEntry[]; showMatter?: boolean; totals?: { minutes: number; amount_cents: number } }) {
  const { user, abilities } = useCurrentSession()
  const [editing, setEditing] = useState<TimeEntry | null>(null)
  const [deleting, setDeleting] = useState<TimeEntry | null>(null)
  const remove = useDeleteTimeEntry()

  if (entries.length === 0) {
    return <EmptyState icon={<Clock className="size-6" />} title="No time logged" description="Use the timer or Log time to record work." />
  }

  const canModify = (e: TimeEntry) => !e.is_invoiced && (e.user?.id === user.id || abilities.manage_finances)

  return (
    <>
      <Table caption="Time entries">
        <thead>
          <tr>
            <Th>Date</Th>
            {showMatter && <Th>Matter</Th>}
            <Th>Work</Th>
            <Th>By</Th>
            <Th align="right">Time</Th>
            <Th align="right">Amount</Th>
            <Th><span className="sr-only">Actions</span></Th>
          </tr>
        </thead>
        <tbody>
          {entries.map((e) => (
            <Tr key={e.id}>
              <Td className="whitespace-nowrap">{date(e.work_date)}</Td>
              {showMatter && (
                <Td>
                  {e.matter && <Link to={`/matters/${e.matter.id}`} className="text-primary hover:underline">{e.matter.reference}</Link>}
                </Td>
              )}
              <Td className="max-w-md">
                <p className="line-clamp-2">{e.description}</p>
                <div className="mt-1 flex gap-1">
                  {e.is_invoiced && <Badge tone="success">Invoiced</Badge>}
                  {!e.is_billable && <Badge>Non-billable</Badge>}
                </div>
              </Td>
              <Td className="whitespace-nowrap text-on-surface-variant">{e.user?.name}</Td>
              <Td align="right">{duration(e.minutes)}</Td>
              <Td align="right">{money(e.amount_cents)}</Td>
              <Td align="right" className="whitespace-nowrap">
                {canModify(e) && (
                  <>
                    <IconButton label="Edit time entry" onClick={() => setEditing(e)}><Pencil className="size-4" /></IconButton>
                    <IconButton label="Delete time entry" onClick={() => setDeleting(e)}><Trash2 className="size-4" /></IconButton>
                  </>
                )}
              </Td>
            </Tr>
          ))}
        </tbody>
        {totals && (
          <tfoot>
            <tr className="font-medium">
              <Td colSpan={showMatter ? 4 : 3}>Total</Td>
              <Td align="right">{duration(totals.minutes)}</Td>
              <Td align="right">{money(totals.amount_cents)}</Td>
              <Td />
            </tr>
          </tfoot>
        )}
      </Table>

      {editing && <TimeTrackingForm open entry={editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete this time entry?"
        description={deleting && <>{duration(deleting.minutes)} on {date(deleting.work_date)}: “{deleting.description}”</>}
        destructive
        confirmLabel="Delete"
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
      />
    </>
  )
}
