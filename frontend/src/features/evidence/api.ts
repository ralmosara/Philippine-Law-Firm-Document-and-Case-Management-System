import { useQuery } from '@tanstack/react-query'
import { del, get, patch, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type ExhibitSide = 'ours' | 'adverse'
export type ExhibitStatus = 'marked' | 'offered' | 'admitted' | 'denied' | 'withdrawn'

export interface Exhibit {
  id: number
  side: ExhibitSide
  marking: string
  description: string
  purpose: string | null
  witness: string | null
  matter_file_id: number | null
  file_name: string | null
  marked_on: string | null
  status: ExhibitStatus
  objection: string | null
  ruling: string | null
  ruled_on: string | null
  notes: string | null
}

export interface ExhibitList {
  exhibits: Exhibit[]
  letters: Record<ExhibitSide, boolean>
  next: Record<ExhibitSide, string>
  client_role: string | null
}

export type ExhibitInput = Partial<Omit<Exhibit, 'id' | 'file_name'>> & { parent?: string | null }

export const STATUS_LABELS: Record<ExhibitStatus, string> = {
  marked: 'Marked',
  offered: 'Offered',
  admitted: 'Admitted',
  denied: 'Denied',
  withdrawn: 'Withdrawn',
}

export function useExhibits(matterId: number) {
  return useQuery({ queryKey: ['exhibits', matterId], queryFn: () => get<ExhibitList>(`/v1/matters/${matterId}/exhibits`) })
}

export function useNextMarking(matterId: number, side: ExhibitSide, parent: string | null, enabled: boolean) {
  return useQuery({
    queryKey: ['exhibits', matterId, 'next', side, parent],
    queryFn: async () => (await get<{ marking: string }>(`/v1/matters/${matterId}/exhibits/next-marking`, { side, parent: parent || undefined })).marking,
    enabled,
  })
}

export function useSaveExhibit(matterId: number, id?: number) {
  return useApiMutation((input: ExhibitInput) => (id ? patch<Exhibit>(`/v1/exhibits/${id}`, input) : post<Exhibit>(`/v1/matters/${matterId}/exhibits`, input)), {
    invalidate: [['exhibits', matterId]],
    success: (e) => `Exhibit "${e.marking}" saved`,
    toastErrors: false,
  })
}

export function useDeleteExhibit(matterId: number) {
  return useApiMutation((id: number) => del(`/v1/exhibits/${id}`), { invalidate: [['exhibits', matterId]], success: 'Exhibit removed' })
}

export function useBulkExhibitStatus(matterId: number) {
  return useApiMutation((input: { ids: number[]; status: ExhibitStatus; ruled_on?: string | null; ruling?: string | null }) => post<{ updated: number }>(`/v1/matters/${matterId}/exhibits/status`, input), {
    invalidate: [['exhibits', matterId]],
    success: (r) => `${r.updated} exhibit(s) updated`,
    toastErrors: false,
  })
}

export function useCreateFormalOffer(matterId: number) {
  return useApiMutation((input: { ids: number[]; place?: string | null }) => post<{ id: number; title: string }>(`/v1/matters/${matterId}/exhibits/formal-offer`, input), {
    invalidate: [['documents']],
    success: 'Formal Offer of Evidence drafted',
  })
}

export function previewFormalOffer(matterId: number, ids: number[]) {
  return post<{ text: string }>(`/v1/matters/${matterId}/exhibits/formal-offer/preview`, { ids })
}
