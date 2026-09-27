import type { DeadlineStatus, DocumentStatus, InvoiceStatus, MatterStatus } from '@/shared/api/types'
import { Badge } from '@/shared/ui/Feedback'

const matterTone: Record<MatterStatus, 'neutral' | 'primary' | 'success' | 'warning' | 'danger'> = {
  intake: 'neutral',
  filed: 'primary',
  pre_trial: 'primary',
  trial: 'warning',
  decision: 'warning',
  appeal: 'danger',
  closed: 'success',
}

export function MatterStatusBadge({ status, label }: { status: MatterStatus; label: string }) {
  return <Badge tone={matterTone[status]}>{label}</Badge>
}

export function DeadlineStatusBadge({ status, daysRemaining }: { status: DeadlineStatus; daysRemaining?: number }) {
  if (status === 'completed') return <Badge tone="success">Completed</Badge>
  if (status === 'cancelled') return <Badge>Cancelled</Badge>
  if (status === 'missed') return <Badge tone="danger">Missed</Badge>
  if (daysRemaining !== undefined && daysRemaining < 0) return <Badge tone="danger">Overdue</Badge>
  if (daysRemaining !== undefined && daysRemaining <= 3) return <Badge tone="warning">Due soon</Badge>
  return <Badge tone="primary">Pending</Badge>
}

export function DocumentStatusBadge({ status }: { status: DocumentStatus }) {
  const map: Record<DocumentStatus, [string, 'neutral' | 'primary' | 'success' | 'warning']> = {
    draft: ['Draft', 'neutral'],
    final: ['Final', 'primary'],
    pending_signature: ['Awaiting signature', 'warning'],
    signed: ['Signed', 'success'],
    notarized: ['Notarized', 'success'],
  }
  const [label, tone] = map[status]
  return <Badge tone={tone}>{label}</Badge>
}

export function InvoiceStatusBadge({ status, overdue }: { status: InvoiceStatus; overdue?: boolean }) {
  if (status === 'issued' && overdue) return <Badge tone="danger">Overdue</Badge>
  const map: Record<InvoiceStatus, [string, 'neutral' | 'primary' | 'success' | 'warning']> = {
    draft: ['Draft', 'neutral'],
    issued: ['Issued', 'warning'],
    paid: ['Paid', 'success'],
    void: ['Void', 'neutral'],
  }
  const [label, tone] = map[status]
  return <Badge tone={tone}>{label}</Badge>
}
