import { useQuery } from '@tanstack/react-query'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export type ImportType = 'clients' | 'matters' | 'deadlines' | 'trust_balances' | 'invoices' | 'time_entries'
export type RowStatus = 'ready' | 'duplicate' | 'error'

export interface ImportTypeInfo {
  type: ImportType
  label: string
  allowed: boolean
  columns: { key: string; label: string; required: boolean; example: string; hint: string | null }[]
}

export interface ImportSummary {
  total: number
  ready: number
  duplicate: number
  error: number
  created?: number
  undone?: number
  kept?: string[]
  ignored_columns?: string[]
}

export interface DataImport {
  id: number
  type: ImportType
  label: string
  filename: string
  status: 'previewed' | 'committed' | 'undone'
  summary: ImportSummary
  can_undo: boolean
  created_by: string | null
  committed_at: string | null
  undone_at: string | null
  created_at: string
}

export interface DataImportDetail extends DataImport {
  columns: { key: string; label: string }[]
  rows: { line: number; status: RowStatus; messages: string[]; warnings: string[]; raw: Record<string, string>; created: boolean }[]
}

const invalidate = [['imports'], ['clients'], ['matters'], ['deadlines'], ['tasks'], ['trust'], ['dashboard']]

export function useImportTypes() {
  return useQuery({ queryKey: ['imports', 'types'], queryFn: () => get<ImportTypeInfo[]>('/v1/imports/types'), staleTime: Infinity })
}

export function useImports() {
  return useQuery({ queryKey: ['imports', 'history'], queryFn: () => get<DataImport[]>('/v1/imports') })
}

export function useImport(id: number | null) {
  return useQuery({ queryKey: ['imports', id], queryFn: () => get<DataImportDetail>(`/v1/imports/${id}`), enabled: id !== null })
}

export function usePreviewImport() {
  return useApiMutation(
    ({ type, file }: { type: ImportType; file: File }) => {
      const body = new FormData()
      body.append('type', type)
      body.append('file', file)
      return post<DataImportDetail>('/v1/imports', body)
    },
    { invalidate: [['imports', 'history']], toastErrors: false },
  )
}

export function useCommitImport() {
  return useApiMutation((id: number) => post<DataImportDetail>(`/v1/imports/${id}/commit`), {
    invalidate,
    success: (i) => `Imported ${i.summary.created ?? 0} ${i.label.toLowerCase()}`,
    toastErrors: false,
  })
}

export function useUndoImport() {
  return useApiMutation((id: number) => post<DataImportDetail & { undone: number; kept: string[] }>(`/v1/imports/${id}/undo`), {
    invalidate,
    success: (r) => (r.kept.length ? `Undid ${r.undone}; ${r.kept.length} kept because they are in use` : `Undid ${r.undone} records`),
  })
}
