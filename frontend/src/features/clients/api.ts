import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Client, Paginated } from '@/shared/api/types'

export interface ClientFilters {
  search?: string
  type?: string
  page?: number
}

export function useClients(filters: ClientFilters) {
  return useQuery({
    queryKey: ['clients', 'list', filters],
    queryFn: () => get<Paginated<Client>>('/v1/clients', { ...filters }),
    placeholderData: keepPreviousData,
  })
}

export function useClient(id: number) {
  return useQuery({ queryKey: ['clients', id], queryFn: () => get<Client>(`/v1/clients/${id}`) })
}

/** All clients as {id, name} for pickers. */
export function useClientOptions(enabled = true) {
  return useQuery({
    queryKey: ['clients', 'options'],
    queryFn: () => get<{ id: number; name: string; type: string }[]>('/v1/clients/options'),
    enabled,
    staleTime: 60_000,
  })
}

export type ClientInput = Pick<Client, 'type' | 'name'> & Partial<Pick<Client, 'tin' | 'email' | 'phone' | 'address' | 'notes'>>

export function useSaveClient(id?: number) {
  return useApiMutation((input: ClientInput) => (id ? put<Client>(`/v1/clients/${id}`, input) : post<Client>('/v1/clients', input)), {
    invalidate: [['clients']],
    success: id ? 'Client updated' : (c) => `${c.name} added`,
    toastErrors: false,
  })
}

export function useDeleteClient() {
  return useApiMutation((id: number) => del(`/v1/clients/${id}`), { invalidate: [['clients']], success: 'Client deleted' })
}

export function usePortalAccess(id: number) {
  return useApiMutation((input: { portal_enabled: boolean; password?: string; send_invite?: boolean }) => put<Client>(`/v1/clients/${id}/portal-access`, input), {
    invalidate: [['clients']],
    success: (c) => (!c.portal_enabled ? 'Portal access revoked' : c.portal_password_set ? 'Portal access saved' : `Invitation emailed to ${c.email}`),
    toastErrors: false,
  })
}
