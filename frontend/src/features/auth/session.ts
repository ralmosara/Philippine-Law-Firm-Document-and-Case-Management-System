import { type QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { ApiError, get, post } from '@/shared/api/axios'
import type { Abilities, Lookups, Session, User } from '@/shared/api/types'

export const sessionKey = ['session'] as const

/** The signed-in staff member, their firm and abilities; `null` when signed out. */
export function useSession() {
  return useQuery({
    queryKey: sessionKey,
    queryFn: async () => {
      try {
        return await get<Session>('/v1/auth/me')
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null
        throw error
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })
}

/** The current user's session. Only call below <RequireAuth>, where it is guaranteed. */
export function useCurrentSession(): Session {
  const { data } = useSession()
  if (!data) throw new Error('useCurrentSession() called outside an authenticated route')
  return data
}

export function useAbilities(): Abilities {
  return useCurrentSession().abilities
}

/** Either signed in, or the password was right and an authenticator code is needed. */
export type LoginResult = { user: User } | { two_factor: true; challenge: string }

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (credentials: { email: string; password: string; remember: boolean }) => post<LoginResult>('/v1/auth/login', credentials),
    onSuccess: async (result) => {
      if ('user' in result) await startSession(queryClient)
    },
  })
}

/** Second sign-in step for accounts with two-factor authentication. */
export function useTwoFactorChallenge() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { challenge: string; code?: string; recovery_code?: string }) => post<{ user: User }>('/v1/auth/two-factor-challenge', input),
    onSuccess: () => startSession(queryClient),
  })
}

/** Staff reset by email; portal accounts by client id, since one email can belong to clients of several firms. */
export type ResetScope = 'staff' | 'portal'

const RESET_BASE: Record<ResetScope, string> = { staff: '/v1/auth', portal: '/portal' }

export function useForgotPassword(scope: ResetScope = 'staff') {
  return useMutation({ mutationFn: (email: string) => post<{ message: string }>(`${RESET_BASE[scope]}/forgot-password`, { email }) })
}

export function useResetPassword(scope: ResetScope = 'staff') {
  return useMutation({
    mutationFn: (input: { token: string; email?: string; client?: number; password: string; password_confirmation: string }) =>
      post<{ message: string }>(`${RESET_BASE[scope]}/reset-password`, input),
  })
}

async function startSession(queryClient: QueryClient) {
  queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'session' })
  await queryClient.invalidateQueries({ queryKey: sessionKey })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => post('/v1/auth/logout'),
    onSettled: () => {
      queryClient.clear()
      queryClient.setQueryData(sessionKey, null)
    },
  })
}

/** When any request comes back 401, the session has ended: drop cached data. */
export function useUnauthorizedListener() {
  const queryClient = useQueryClient()
  useEffect(() => {
    const onUnauthorized = (event: Event) => {
      const url = (event as CustomEvent<string | undefined>).detail ?? ''
      if (url.startsWith('/portal') || url.startsWith('portal')) return
      queryClient.setQueryData(sessionKey, null)
    }
    // Refetching the session brings up the two-step setup screen (RequireAuth).
    const onTwoFactorRequired = () => void queryClient.invalidateQueries({ queryKey: sessionKey })
    window.addEventListener('auth:unauthorized', onUnauthorized)
    window.addEventListener('auth:two-factor-required', onTwoFactorRequired)
    return () => {
      window.removeEventListener('auth:unauthorized', onUnauthorized)
      window.removeEventListener('auth:two-factor-required', onTwoFactorRequired)
    }
  }, [queryClient])
}

/** Enumerations for selects and labels, served by the backend. */
export function useLookups() {
  return useQuery({
    queryKey: ['lookups'],
    queryFn: () => get<Lookups>('/v1/lookups'),
    staleTime: Infinity,
  })
}
