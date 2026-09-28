import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'

export interface EmailAttachment {
  name: string
  size: number
  file_id: number | null
  skipped: string | null
}

export interface MatterEmail {
  id: number
  status: 'queued' | 'review' | 'filed' | 'rejected'
  source: 'forward' | 'upload'
  from_email: string | null
  from_name: string | null
  to: string | null
  cc: string | null
  subject: string | null
  sent_at: string | null
  received_at: string | null
  preview: string | null
  attachments: EmailAttachment[]
  eml_file_id: number | null
  filed_by: string | null
  reviewed_by: string | null
  body_text?: string | null
}

export function useMatterEmails(matterId: number) {
  const queryClient = useQueryClient()
  const query = useQuery({
    queryKey: ['matter-emails', matterId],
    queryFn: () => get<{ enabled: boolean; address: string | null; emails: MatterEmail[] }>(`/v1/matters/${matterId}/emails`),
    // Filing (virus scan, attachments) runs in the background; refresh until it finishes.
    refetchInterval: (query) => (query.state.data?.emails.some((e) => e.status === 'queued') ? 3000 : false),
  })

  // Filing finishes after the upload returns; refresh the matter's files when it does.
  const filed = query.data?.emails.filter((e) => e.status === 'filed').length
  useEffect(() => {
    if (filed !== undefined) void queryClient.invalidateQueries({ queryKey: ['files', matterId] })
  }, [filed, matterId, queryClient])

  return query
}

export function useMatterEmail(id: number | null) {
  return useQuery({ queryKey: ['matter-emails', 'one', id], queryFn: () => get<MatterEmail>(`/v1/matter-emails/${id}`), enabled: id !== null })
}

export function useReviewEmail(matterId: number) {
  return useApiMutation(({ id, action }: { id: number; action: 'accept' | 'reject' }) => post<MatterEmail>(`/v1/matter-emails/${id}/${action}`), {
    invalidate: [['matter-emails'], ['files', matterId], ['notifications']],
    success: (e) => (e.status === 'rejected' ? 'Email rejected' : 'Email accepted; filing it now'),
  })
}

export function useUploadEml(matterId: number) {
  return useApiMutation(
    (file: File) => {
      const body = new FormData()
      body.append('file', file)
      return post<MatterEmail>(`/v1/matters/${matterId}/emails`, body)
    },
    { invalidate: [['matter-emails', matterId], ['files', matterId]], success: 'Email filed' },
  )
}

export function useRotateAddress(matterId: number) {
  return useApiMutation(() => post<{ address: string }>(`/v1/matters/${matterId}/emails/address`), {
    invalidate: [['matter-emails', matterId]],
    success: 'New address created; the old one no longer works',
  })
}
