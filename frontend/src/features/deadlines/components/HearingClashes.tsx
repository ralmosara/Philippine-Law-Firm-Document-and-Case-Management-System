import { useQuery } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import { get } from '@/shared/api/axios'

export interface Clash { id: number; title: string; time: string | null; matter: string | null; lawyer: string | null }

/** Hearings the same lawyer already has at about that time (less than two hours apart, or no time set). */
export function useHearingClashes(params: { matter_id: number; date: string; time?: string; assigned_to?: number }, enabled: boolean) {
  const query: Record<string, string> = { matter_id: String(params.matter_id), date: params.date }
  if (params.time) query.time = params.time
  if (params.assigned_to) query.assigned_to = String(params.assigned_to)

  return useQuery({
    queryKey: ['deadlines', 'clashes', query],
    queryFn: () => get<{ clashes: Clash[] }>('/v1/deadlines/clashes', query),
    enabled: enabled && !!params.date,
  })
}

export function ClashWarning({ clashes, intro }: { clashes: Clash[]; intro: string }) {
  if (clashes.length === 0) return null

  return (
    <div role="status" className="rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">
      <p className="flex items-center gap-2 font-medium"><AlertTriangle className="size-4" aria-hidden /> {intro}</p>
      <ul className="mt-1 list-disc pl-5">
        {clashes.map((c) => (
          <li key={c.id}>{c.time ?? 'No time set'} · {c.title}{c.matter && ` · ${c.matter}`}{c.lawyer && ` (${c.lawyer})`}</li>
        ))}
      </ul>
    </div>
  )
}
