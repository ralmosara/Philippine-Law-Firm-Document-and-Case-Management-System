import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiClient, get, post, put } from '@/shared/api/axios'
import { messageForm, type MessageThread } from '@/features/messages/api'
import { getLocale, setLocale, type Locale } from '@/shared/lib/i18n'
import { disconnectRealtime, usePrivateChannel } from '@/shared/realtime/echo'

export interface PortalClient {
  id: number
  name: string
  email: string
  locale: Locale
  /** For live message threads; null means poll. */
  realtime: { key: string } | null
  firm: { id: number; name: string; email: string | null; phone: string | null; address: string | null }
}

export interface PortalMatter {
  id: number
  reference: string
  title: string
  case_type: string
  case_number: string | null
  status: string
  status_label: string
  progress: number
  opened_at: string
  lawyer: { name: string; email: string } | null
  next_hearing: { title: string; date: string; time: string | null; location: string | null } | null
}

export interface PortalMatterDetail extends PortalMatter {
  court: string | null
  court_branch: string | null
  description: string | null
  /** Shared by the firm: centavos (basis amount) or minutes (basis hours). */
  budget: { basis: 'amount' | 'hours'; total: number; used: number; percent: number; includes_expenses: boolean } | null
  /** Asked for once the matter closed. */
  feedback: { id: number; rating: number | null; comment: string | null; responded_at: string | null; can_change: boolean } | null
  timeline: { status: string; date: string }[]
  documents: { id: number; title: string; status: string; updated_at: string }[]
  files: { id: number; name: string; description: string | null; size_bytes: number; created_at: string }[]
}

export interface PortalInvoice {
  id: number
  number: string
  status: 'issued' | 'partially_paid' | 'paid'
  is_overdue: boolean
  can_pay_online: boolean
  total_cents: number
  paid_cents: number
  balance_cents: number
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
  matter: { reference: string; title: string } | null
  /** Deposit slips and screenshots the client sent, newest first. */
  payment_proofs?: { id: number; status: 'pending' | 'confirmed' | 'rejected'; amount_cents: number; paid_on: string; reject_reason: string | null; created_at: string }[]
}

export interface PortalTrustAccount {
  id: number
  account_number: string
  balance_cents: number
  status: string
  matter: { reference: string; title: string } | null
  transactions: { id: number; type: 'deposit' | 'disbursement'; amount_cents: number; balance_after_cents: number; description: string; date: string }[]
}

const portalKey = ['portal', 'me'] as const

export function usePortalSession() {
  return useQuery({
    queryKey: portalKey,
    queryFn: async () => {
      try {
        const client = (await get<{ client: PortalClient }>('/portal/me')).client
        // Signed in, the language saved on the client's record (set on any device) applies.
        setLocale(client.locale)
        return client
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) return null
        throw error
      }
    },
    retry: false,
    staleTime: 5 * 60_000,
  })
}

export function usePortalLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) => post<{ client: PortalClient }>('/portal/login', credentials),
    onSuccess: (data) => {
      // A language picked on the sign-in screen is saved to the client's record.
      const chosen = getLocale()
      if (chosen !== data.client.locale) void put('/portal/locale', { locale: chosen })
      queryClient.setQueryData(portalKey, { ...data.client, locale: chosen })
    },
  })
}

export function usePortalLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => post('/portal/logout'),
    onSettled: () => {
      disconnectRealtime()
      queryClient.removeQueries({ queryKey: ['portal'] })
      queryClient.setQueryData(portalKey, null)
    },
  })
}

export const usePortalMatters = () => useQuery({ queryKey: ['portal', 'matters'], queryFn: async () => (await get<{ data: PortalMatter[] }>('/portal/matters')).data })
export const usePortalMatter = (id: number) => useQuery({ queryKey: ['portal', 'matters', id], queryFn: () => get<PortalMatterDetail>(`/portal/matters/${id}`) })
export const usePortalDocument = (id: number | null) =>
  useQuery({ queryKey: ['portal', 'documents', id], queryFn: () => get<{ id: number; title: string; version: number; content: string | null }>(`/portal/documents/${id}`), enabled: id !== null })
export const usePortalInvoices = () => useQuery({ queryKey: ['portal', 'invoices'], queryFn: () => get<{ data: PortalInvoice[]; outstanding_cents: number }>('/portal/invoices') })
export const usePortalTrust = () => useQuery({ queryKey: ['portal', 'trust'], queryFn: async () => (await get<{ data: PortalTrustAccount[] }>('/portal/trust-accounts')).data })

export interface PortalSignatureRequest {
  id: number
  status: 'pending' | 'signed' | 'declined' | 'cancelled' | 'expired'
  message: string | null
  expires_at: string | null
  requested_at: string
  requested_by: string | null
  document: { id: number; title: string }
  matter: { reference: string; title: string } | null
}

export interface PortalSignatureDetail extends PortalSignatureRequest {
  content: string
  content_sha256: string
  signer_name: string | null
  responded_at: string | null
}

export const portalFileUrl = (id: number) => `/api/portal/files/${id}/download`

export const usePortalSignatureRequests = () =>
  useQuery({ queryKey: ['portal', 'signatures'], queryFn: async () => (await get<{ data: PortalSignatureRequest[] }>('/portal/signature-requests')).data })

export const usePortalSignatureRequest = (id: number) =>
  useQuery({ queryKey: ['portal', 'signatures', id], queryFn: () => get<PortalSignatureDetail>(`/portal/signature-requests/${id}`) })

export function useSignDocument(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { signer_name: string; method: 'drawn' | 'typed'; signature_image?: string; consent: boolean }) =>
      post<{ status: string; signed_at: string }>(`/portal/signature-requests/${id}/sign`, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal'] }),
  })
}

export function useDeclineSignature(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (reason: string) => post<{ status: string }>(`/portal/signature-requests/${id}/decline`, { reason: reason || undefined }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal'] }),
  })
}

/** Opens PayMongo's hosted checkout; the invoice is marked paid when PayMongo confirms. */
export function usePayInvoice() {
  return useMutation({
    mutationFn: (invoiceId: number) => post<{ checkout_url: string }>(`/portal/invoices/${invoiceId}/checkout`),
    onSuccess: ({ checkout_url }) => {
      if (new URL(checkout_url).protocol !== 'https:') throw new Error('Unexpected payment page address.')
      window.location.assign(checkout_url)
    },
  })
}

/** The client's inbox: live when the server offers a WebSocket, otherwise polled. */
export function usePortalThreads(matterId?: number, enabled = true) {
  const session = usePortalSession().data
  const queryClient = useQueryClient()
  usePrivateChannel(session?.realtime, '/portal/broadcasting/auth', enabled && session ? `portal-client.${session.id}` : null, {
    // The inbox lists only; an open thread listens on its own channel.
    '.thread.updated': () => void queryClient.invalidateQueries({ queryKey: ['portal', 'messages'], predicate: (q) => q.queryKey[2] !== 'thread' }, { cancelRefetch: false }),
  })
  return useQuery({
    queryKey: ['portal', 'messages', matterId ?? null],
    enabled,
    queryFn: () => get<{ data: MessageThread[]; unread: number }>('/portal/message-threads', { matter_id: matterId }),
    refetchInterval: session?.realtime ? 5 * 60_000 : 60_000,
  })
}

export function usePortalThread(id: number) {
  const session = usePortalSession().data
  const queryClient = useQueryClient()
  usePrivateChannel(session?.realtime, '/portal/broadcasting/auth', `message-thread.${id}`, {
    '.thread.updated': (payload) => {
      if ((payload as { change?: string }).change === 'message') void queryClient.invalidateQueries({ queryKey: ['portal', 'messages', 'thread', id] }, { cancelRefetch: false })
    },
  })
  return useQuery({ queryKey: ['portal', 'messages', 'thread', id], queryFn: () => get<MessageThread>(`/portal/message-threads/${id}`), refetchInterval: session?.realtime ? 2 * 60_000 : 15_000 })
}

export function usePortalStartThread() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { matter_id: number; subject: string; body: string; file?: File | null }) =>
      apiClient.post<MessageThread>('/portal/message-threads', messageForm({ matter_id: input.matter_id, subject: input.subject, body: input.body }, input.file)).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal', 'messages'] }),
  })
}

export function usePortalReply(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { body: string; file?: File | null }) => apiClient.post<MessageThread>(`/portal/message-threads/${id}/messages`, messageForm({ body: input.body }, input.file)).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal', 'messages'] }),
  })
}
