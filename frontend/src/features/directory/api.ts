import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export interface Court {
  id: number
  level: string
  level_label: string
  name: string
  branch: string | null
  station: string | null
  label: string
  address: string | null
  email: string | null
  phone: string | null
  notes: string | null
  matters_count: number | null
  contacts_count: number | null
}

export interface Contact {
  id: number
  kind: string
  kind_label: string
  name: string
  title: string | null
  display_name: string
  organization: string | null
  court_id: number | null
  court: string | null
  email: string | null
  phone: string | null
  address: string | null
  roll_number: string | null
  notes: string | null
  is_active: boolean
  matters_count: number | null
}

export type CourtInput = Pick<Court, 'level' | 'name' | 'branch' | 'station' | 'address' | 'email' | 'phone' | 'notes'>
export type ContactInput = Pick<Contact, 'kind' | 'name' | 'title' | 'organization' | 'court_id' | 'email' | 'phone' | 'address' | 'roll_number' | 'notes' | 'is_active'>

export interface MatterLink {
  id: number
  role: string
  role_label: string
  notes: string | null
  contact: Contact
}

export function useCourts(search = '') {
  return useQuery({
    queryKey: ['directory', 'courts', search],
    queryFn: () => get<{ levels: Record<string, string>; data: Court[] }>('/v1/directory/courts', { search: search || undefined }),
    placeholderData: keepPreviousData,
  })
}

export function useCourt(id: number | null) {
  return useQuery({
    queryKey: ['directory', 'court', id],
    queryFn: () => get<Court & { contacts: Contact[]; matters: { id: number; reference: string; title: string; status: string }[] }>(`/v1/directory/courts/${id}`),
    enabled: id !== null,
  })
}

export function useContacts(params: { search?: string; kind?: string; include_inactive?: boolean } = {}) {
  return useQuery({
    queryKey: ['directory', 'contacts', params],
    queryFn: () => get<{ kinds: Record<string, string>; roles: Record<string, string>; data: Contact[] }>('/v1/directory/contacts', { ...params, include_inactive: params.include_inactive ? 1 : undefined, search: params.search || undefined, kind: params.kind || undefined }),
    placeholderData: keepPreviousData,
  })
}

export function useContact(id: number | null) {
  return useQuery({
    queryKey: ['directory', 'contact', id],
    queryFn: () => get<Contact & { matters: { role: string; role_label: string; matter: { id: number; reference: string; title: string; status: string } }[] }>(`/v1/directory/contacts/${id}`),
    enabled: id !== null,
  })
}

export function useSaveCourt(id?: number) {
  return useApiMutation((input: CourtInput) => (id ? put<Court>(`/v1/directory/courts/${id}`, input) : post<Court>('/v1/directory/courts', input)), {
    invalidate: [['directory']],
    success: 'Court saved',
    toastErrors: false,
  })
}

export function useDeleteCourt() {
  return useApiMutation((id: number) => del(`/v1/directory/courts/${id}`), { invalidate: [['directory']], success: 'Court removed' })
}

export function useSaveContact(id?: number) {
  return useApiMutation((input: ContactInput) => (id ? put<Contact>(`/v1/directory/contacts/${id}`, input) : post<Contact>('/v1/directory/contacts', input)), {
    invalidate: [['directory']],
    success: 'Contact saved',
    toastErrors: false,
  })
}

export function useDeleteContact() {
  return useApiMutation((id: number) => del(`/v1/directory/contacts/${id}`), { invalidate: [['directory']], success: 'Contact removed' })
}

export function useMatterContacts(matterId: number) {
  return useQuery({ queryKey: ['directory', 'matter', matterId], queryFn: () => get<{ court: Court | null; contacts: MatterLink[] }>(`/v1/matters/${matterId}/contacts`) })
}

export function useSetMatterCourt(matterId: number) {
  return useApiMutation((court_id: number | null) => put(`/v1/matters/${matterId}/court`, { court_id }), {
    invalidate: [['directory', 'matter', matterId], ['matters', matterId], ['matters']],
    success: 'Court set; the caption now uses it',
  })
}

export function useLinkContact(matterId: number) {
  return useApiMutation((input: { contact_id: number; role: string }) => post(`/v1/matters/${matterId}/contacts`, input), {
    invalidate: [['directory', 'matter', matterId], ['matters', matterId]],
    success: 'Added to the matter',
    toastErrors: false,
  })
}

export function useUnlinkContact(matterId: number) {
  return useApiMutation((id: number) => del(`/v1/matter-contacts/${id}`), { invalidate: [['directory', 'matter', matterId]], success: 'Removed from the matter' })
}
