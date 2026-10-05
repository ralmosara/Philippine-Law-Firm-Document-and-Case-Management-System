import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { apiClient, del, get, post, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Paginated, RoleValue, User, UserRef } from '@/shared/api/types'

export function useUsers(params: { search?: string; page?: number }) {
  return useQuery({
    queryKey: ['users', 'list', params],
    queryFn: () => get<Paginated<User>>('/v1/users', { ...params }),
    placeholderData: keepPreviousData,
  })
}

/** Active lawyers, for "responsible lawyer" and "assign to" pickers. */
export function useLawyerOptions(enabled = true) {
  return useQuery({
    queryKey: ['users', 'lawyers'],
    queryFn: async () => (await get<Paginated<User>>('/v1/users', { lawyers_only: 1, active_only: 1, per_page: 100 })).data as UserRef[],
    enabled,
    staleTime: 5 * 60_000,
  })
}

/** Everyone active, for assigning tasks (paralegals included). */
export function useStaffOptions(enabled = true) {
  return useQuery({
    queryKey: ['users', 'staff'],
    queryFn: async () => (await get<Paginated<User>>('/v1/users', { active_only: 1, per_page: 100 })).data,
    enabled,
    staleTime: 5 * 60_000,
  })
}

export interface UserInput {
  name: string
  email: string
  role: RoleValue
  password?: string
  ibp_number?: string | null
  roll_number?: string | null
  mobile_number?: string | null
  hourly_rate_cents?: number
  daily_target_minutes?: number | null
  is_active?: boolean
}

export function useSaveUser(id?: number) {
  return useApiMutation((input: UserInput) => (id ? put<User>(`/v1/users/${id}`, input) : post<User>('/v1/users', input)), {
    invalidate: [['users']],
    success: id ? 'User updated' : (u) => `${u.name} added`,
    toastErrors: false,
  })
}

export function useDeactivateUser() {
  return useApiMutation((id: number) => del(`/v1/users/${id}`), { invalidate: [['users']], success: 'User deactivated' })
}

export function useChangePassword() {
  return useApiMutation((input: { current_password: string; password: string; password_confirmation: string }) => put('/v1/auth/password', input), {
    success: 'Password updated',
    toastErrors: false,
  })
}

export function useStartTwoFactor() {
  return useApiMutation((password: string) => post<{ secret: string; otpauth_url: string }>('/v1/auth/two-factor', { password }), { toastErrors: false })
}

export function useConfirmTwoFactor() {
  // The session is refreshed when the recovery codes dialog closes, so the
  // codes stay on screen even where the firm requires two-step verification.
  return useApiMutation((code: string) => post<{ recovery_codes: string[] }>('/v1/auth/two-factor/confirm', { code }), {
    success: 'Two-factor authentication is on',
    toastErrors: false,
  })
}

export function useDisableTwoFactor() {
  return useApiMutation((password: string) => apiClient.delete('/v1/auth/two-factor', { data: { password } }), {
    invalidate: [['session']],
    success: 'Two-factor authentication is off',
    toastErrors: false,
  })
}

export function useRegenerateRecoveryCodes() {
  return useApiMutation((password: string) => post<{ recovery_codes: string[] }>('/v1/auth/two-factor/recovery-codes', { password }), { toastErrors: false })
}

/** A firm administrator clears a colleague's authenticator (e.g. a lost phone). */
export function useResetUserTwoFactor() {
  return useApiMutation((id: number) => del(`/v1/users/${id}/two-factor`), { invalidate: [['users']], success: 'Two-factor authentication reset' })
}
