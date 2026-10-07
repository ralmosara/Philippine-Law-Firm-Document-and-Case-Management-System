import { useQuery } from '@tanstack/react-query'
import { FileDown, FileSpreadsheet } from 'lucide-react'
import { get } from '@/shared/api/axios'
import { money } from '@/shared/lib/format'
import { useUrlState } from '@/shared/lib/hooks'
import { DownloadButton } from '@/shared/ui/Button'
import { ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, Input } from '@/shared/ui/Form'
import { Card, CardHeader, Table, Td, Th } from '@/shared/ui/Layout'

interface Book { key: string; title: string; entries: number; totals: Record<string, number>; labels: Record<string, string> }

const lastMonth = () => {
  const d = new Date()
  d.setDate(1)
  d.setMonth(d.getMonth() - 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const DESCRIBE: Record<string, string> = {
  'cash-receipts': 'Payments received on billing statements, with the tax clients withheld (Form 2307).',
  'cash-disbursements': "The firm's own money paid out: costs paid for clients, cash advances, refunds.",
  'general-journal': 'Billing statements issued (fees, VAT, expenses billed), write-offs and cash advances settled.',
  trust: "Client funds held in trust, kept apart from the firm's books: they are not the firm's income.",
}

/** The month's books for the accountant: CSV to post, PDF to print on loose leaf. */
export function BooksOfAccounts() {
  const [month, setMonth] = useUrlState('month', lastMonth())
  const query = useQuery({ queryKey: ['books', month], queryFn: () => get<{ books: Book[] }>('/v1/books', { month }), enabled: /^\d{4}-\d{2}$/.test(month) })

  return (
    <Card>
      <CardHeader title="Books of accounts" description="Each month's entries in the shape of the books the firm keeps for the BIR. Account titles are suggestions: your accountant maps them to the chart of accounts and decides the final entries." />
      <div className="border-b border-outline-variant p-4">
        <Field label="Month">{(a) => <Input {...a} type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="w-48" />}</Field>
      </div>
      {query.isPending ? <PageLoader /> : query.isError ? <div className="p-4"><ErrorState error={query.error} /></div> : (
        <Table caption="Books of accounts">
          <thead><tr><Th>Book</Th><Th align="right">Entries</Th><Th>Totals</Th><Th><span className="sr-only">Download</span></Th></tr></thead>
          <tbody>
            {query.data.books.map((b) => (
              <tr key={b.key}>
                <Td className="font-medium">{b.title}<div className="text-xs font-normal text-on-surface-variant">{DESCRIBE[b.key]}</div></Td>
                <Td align="right">{b.entries}</Td>
                <Td className="text-sm">{Object.entries(b.totals).map(([k, v]) => <div key={k}><span className="text-on-surface-variant">{b.labels[k]}:</span> {money(v)}</div>)}</Td>
                <Td align="right" className="whitespace-nowrap">
                  <DownloadButton size="sm" variant="text" href={`/api/v1/books/${b.key}?month=${month}&format=csv`} icon={<FileSpreadsheet className="size-4" />}>CSV</DownloadButton>
                  <DownloadButton size="sm" variant="text" href={`/api/v1/books/${b.key}?month=${month}&format=pdf`} icon={<FileDown className="size-4" />}>PDF</DownloadButton>
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  )
}
