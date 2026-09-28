import { HandCoins } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/shared/ui/Button'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useDisbursements } from '../api'
import { DetailDialog, RequestDialog } from './DisbursementDialogs'
import { DisbursementTable } from './DisbursementsPage'

/** The matter's cash advances, on its Time & expenses tab. */
export function MatterDisbursementsCard({ matter, canRequest }: { matter: { id: number; client_id: number }; canRequest: boolean }) {
  const query = useDisbursements({ matter_id: matter.id, status: 'all' })
  const [requesting, setRequesting] = useState(false)
  const [openId, setOpenId] = useState<number | null>(null)
  const selected = query.data?.data.find((d) => d.id === openId)

  return (
    <Card>
      <CardHeader
        title="Cash advances"
        description="Requested, approved and released funds for case costs; liquidated receipts appear under Expenses."
        actions={canRequest && <Button variant="tonal" size="sm" icon={<HandCoins className="size-4" />} onClick={() => setRequesting(true)}>Request funds</Button>}
      />
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-3"><ErrorState error={query.error} /></div> : query.data.data.length === 0 ? (
        <EmptyState title="No cash advances on this matter" />
      ) : (
        <DisbursementTable rows={query.data.data} onOpen={(d) => setOpenId(d.id)} />
      )}
      {requesting && query.data && <RequestDialog matter={matter} categories={query.data.categories} onClose={() => setRequesting(false)} />}
      {selected && query.data && <DetailDialog key={selected.id} d={selected} categories={query.data.categories} onClose={() => setOpenId(null)} />}
    </Card>
  )
}
