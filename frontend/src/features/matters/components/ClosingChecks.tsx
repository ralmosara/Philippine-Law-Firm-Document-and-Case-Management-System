import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, Ban, CheckCircle2, FileText } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { Button } from '@/shared/ui/Button'
import { Spinner } from '@/shared/ui/Feedback'

interface Check { key: string; message: string }
export interface ClosingCheck { blockers: Check[]; warnings: Check[] }

export function useClosingCheck(matterId: number, enabled: boolean) {
  return useQuery({ queryKey: ['matters', matterId, 'closing-check'], queryFn: () => get<ClosingCheck>(`/v1/matters/${matterId}/closing-check`), enabled, staleTime: 0 })
}

/** What must be settled (blockers) and what is still open (warnings) before closing. */
export function ClosingChecks({ query }: { query: ReturnType<typeof useClosingCheck> }) {
  if (query.isPending) return <span className="mt-3 flex items-center gap-2"><Spinner className="size-4" /> Checking what is still open…</span>
  if (query.isError || !query.data) return null
  const { blockers, warnings } = query.data

  if (blockers.length === 0 && warnings.length === 0) {
    return <p className="mt-3 flex items-center gap-2 text-success"><CheckCircle2 className="size-4" aria-hidden /> Nothing is left open: no trust funds, unbilled work, unpaid bills or open deadlines.</p>
  }

  return (
    <div className="mt-3 flex flex-col gap-2" role="status">
      {blockers.length > 0 && (
        <div className="rounded-[3px] bg-danger-container p-3 text-on-danger-container">
          <p className="flex items-center gap-2 font-medium"><Ban className="size-4" aria-hidden /> Cannot close yet</p>
          <ul className="mt-1 list-disc pl-5">{blockers.map((b) => <li key={b.message}>{b.message}</li>)}</ul>
        </div>
      )}
      {warnings.length > 0 && (
        <div className="rounded-[3px] bg-warning-container p-3 text-on-warning-container">
          <p className="flex items-center gap-2 font-medium"><AlertTriangle className="size-4" aria-hidden /> Still open{blockers.length === 0 && ': closing confirms you have seen these'}</p>
          <ul className="mt-1 list-disc pl-5">{warnings.map((w) => <li key={w.message}>{w.message}</li>)}</ul>
        </div>
      )}
    </div>
  )
}

/** Draft the closing letter to the client, then open it to edit and send on letterhead. */
export function ClosingLetterButton({ matterId }: { matterId: number }) {
  const navigate = useNavigate()
  const draft = useApiMutation(() => post<{ id: number }>(`/v1/matters/${matterId}/closing-letter`), { invalidate: [['documents']], success: 'Closing letter drafted' })

  return (
    <Button variant="tonal" icon={<FileText className="size-4" />} loading={draft.isPending} onClick={() => draft.mutate(undefined, { onSuccess: (d) => navigate(`/documents/${d.id}`) })}>
      Draft closing letter
    </Button>
  )
}
