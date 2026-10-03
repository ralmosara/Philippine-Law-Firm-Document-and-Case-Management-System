import { onlineManager, useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query'
import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { useToast } from '@/shared/ui/Toast'
import { isQueued } from '@/shared/offline/queue'
import { ApiError } from './axios'

interface Options<TData> {
  /** Query key prefixes to refetch after success. */
  invalidate?: QueryKey[]
  /** Snackbar text on success. */
  success?: string | ((data: TData) => string)
  /** Show a snackbar on failure (default true; forms usually show errors inline instead). */
  toastErrors?: boolean
}

/**
 * useMutation with the app's conventions: invalidate related queries,
 * confirm success in a snackbar, surface failures.
 */
export function useApiMutation<TVariables = void, TData = unknown>(fn: (variables: TVariables) => Promise<TData>, options: Options<TData> = {}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const { invalidate = [], success, toastErrors = true } = options

  return useMutation<TData, ApiError, TVariables>({
    mutationFn: async (variables) => {
      try {
        return await fn(variables)
      } catch (error) {
        throw ApiError.from(error)
      }
    },
    onSuccess: async (data) => {
      const refresh = Promise.all(invalidate.map((queryKey) => queryClient.invalidateQueries({ queryKey })))
      // Offline (or kept for later), the lists cannot refresh until the
      // connection returns; do not hold the form open waiting for them.
      if (onlineManager.isOnline() && !isQueued(data)) await refresh
      else void refresh
      if (success) toast.success(typeof success === 'function' ? success(data) : success)
    },
    onError: (error) => {
      if (toastErrors) toast.error(error.message)
    },
  })
}

/**
 * Copy server validation errors onto react-hook-form fields; anything that
 * does not map to a field becomes the form-level (root) error.
 */
export function applyServerErrors<T extends FieldValues>(error: unknown, setError: UseFormSetError<T>, fields: readonly string[]): void {
  const apiError = ApiError.from(error)
  let unmatched = apiError.status === 422 ? '' : apiError.message

  for (const [field, messages] of Object.entries(apiError.errors)) {
    const base = field.split('.')[0] ?? field
    if (fields.includes(base)) {
      setError(base as Path<T>, { message: messages[0] })
    } else {
      unmatched ||= messages[0] ?? ''
    }
  }

  if (unmatched || (apiError.status === 422 && Object.keys(apiError.errors).length === 0)) {
    setError('root', { message: unmatched || apiError.message })
  }
}
