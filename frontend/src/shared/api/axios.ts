import axios, { AxiosError, type AxiosRequestConfig } from 'axios'
import { noteNetworkFailure } from '@/shared/offline/connectivity'
import { getLocale } from '@/shared/lib/i18n'

/**
 * HTTP client for the Laravel API. Authentication is Sanctum's cookie-based
 * SPA mode: the session cookie is httpOnly, and axios echoes the XSRF-TOKEN
 * cookie back as the X-XSRF-TOKEN header on every mutating request.
 */
export const apiClient = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

let csrfCookie: Promise<unknown> | null = null

/** Fetch the XSRF cookie once per page load (and again after it expires). */
export function ensureCsrfCookie(): Promise<unknown> {
  csrfCookie ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true }).catch((error) => {
    csrfCookie = null
    throw error
  })
  return csrfCookie
}

const SAFE_METHODS = new Set(['get', 'head', 'options'])

apiClient.interceptors.request.use(async (config) => {
  // The portal's language, for messages the server writes (validation errors, labels).
  config.headers.set('X-Locale', getLocale())
  if (!SAFE_METHODS.has((config.method ?? 'get').toLowerCase())) {
    await ensureCsrfCookie()
  }
  return config
})

apiClient.interceptors.response.use(undefined, async (error: AxiosError) => {
  const config = error.config as (AxiosRequestConfig & { _retried?: boolean }) | undefined

  // No answer at all: the server cannot be reached right now.
  if (!error.response && error.code !== 'ERR_CANCELED') noteNetworkFailure()

  // 419: the CSRF token expired (e.g. a long-idle tab). Refresh it and retry once.
  if (error.response?.status === 419 && config && !config._retried) {
    csrfCookie = null
    config._retried = true
    return apiClient.request(config)
  }

  if (error.response?.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:unauthorized', { detail: config?.url }))
  }

  // The firm started requiring two-step verification during this session.
  if (error.response?.status === 403 && (error.response.data as { code?: string } | undefined)?.code === 'two_factor_required') {
    window.dispatchEvent(new CustomEvent('auth:two-factor-required'))
  }

  return Promise.reject(ApiError.from(error))
})

/** A normalised API failure: `{status, message, errors?}` from the backend envelope. */
export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }

  static from(error: unknown): ApiError {
    if (error instanceof ApiError) return error
    if (error instanceof AxiosError) {
      const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
      if (!error.response) {
        return new ApiError(0, 'Unable to reach the server. Check your connection and try again.')
      }
      return new ApiError(error.response.status, data?.message ?? error.message, data?.errors ?? {})
    }
    return new ApiError(0, error instanceof Error ? error.message : 'Something went wrong.')
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

/** Unwrap response data. */
export async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  return (await apiClient.get<T>(url, { params: clean(params) })).data
}

export async function post<T>(url: string, body?: unknown): Promise<T> {
  return (await apiClient.post<T>(url, body)).data
}

export async function put<T>(url: string, body?: unknown): Promise<T> {
  return (await apiClient.put<T>(url, body)).data
}

export async function patch<T>(url: string, body?: unknown): Promise<T> {
  return (await apiClient.patch<T>(url, body)).data
}

export async function del(url: string): Promise<void> {
  await apiClient.delete(url)
}

/** Drop empty query parameters so URLs stay clean and cache keys stable. */
function clean(params?: Record<string, unknown>): Record<string, unknown> | undefined {
  if (!params) return undefined
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '' && v !== false))
}
