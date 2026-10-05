import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { get, patch, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import type { Deadline, DeadlineComputation, DeadlineKind, DeadlineRule } from '@/shared/api/types'

const invalidate = [['deadlines'], ['matters'], ['dashboard'], ['tasks']]

export function useDeadlinesInRange(from: string, to: string, mine = false) {
  return useQuery({
    queryKey: ['deadlines', 'range', from, to, mine],
    queryFn: () => get<Deadline[]>('/v1/deadlines', { from, to, mine: mine ? 1 : undefined }),
  })
}

export function useDeadline(id: number | null) {
  return useQuery({
    queryKey: ['deadlines', id],
    queryFn: () => get<Deadline>(`/v1/deadlines/${id}`),
    enabled: id !== null,
  })
}

export function useDeadlineRules() {
  return useQuery({ queryKey: ['deadline-rules'], queryFn: () => get<DeadlineRule[]>('/v1/deadline-rules', { active_only: 1 }), staleTime: 5 * 60_000 })
}

/** Live preview of a reglementary due date as the user types a trigger date. */
export function useComputeDeadline(ruleId: number | null, triggerDate: string) {
  return useQuery({
    queryKey: ['deadlines', 'compute', ruleId, triggerDate],
    queryFn: () => post<DeadlineComputation>('/v1/deadlines/compute', { deadline_rule_id: ruleId, trigger_date: triggerDate }),
    enabled: ruleId !== null && /^\d{4}-\d{2}-\d{2}$/.test(triggerDate),
    staleTime: Infinity,
  })
}

export type DeadlineInput =
  | { deadline_rule_id: number; trigger_date: string; title?: string; assigned_to?: number | null; notes?: string }
  | { kind: DeadlineKind; title: string; due_date: string; due_time?: string | null; location?: string | null; assigned_to?: number | null; notes?: string; priority?: string; notify_client?: boolean }

export function useCreateDeadline(matterId: number) {
  return useApiMutation((input: DeadlineInput) => post<Deadline>(`/v1/matters/${matterId}/deadlines`, input), {
    invalidate,
    success: (d) => `Scheduled "${d.title}"`,
    toastErrors: false,
  })
}

export function useUpdateDeadline(id: number) {
  return useApiMutation((input: Partial<Pick<Deadline, 'title' | 'due_time' | 'location' | 'notes'>> & { assigned_to?: number | null }) => patch<Deadline>(`/v1/deadlines/${id}`, input), {
    invalidate,
    success: 'Deadline updated',
  })
}

export function useCompleteDeadline() {
  return useApiMutation(({ id, notes }: { id: number; notes?: string }) => post<Deadline>(`/v1/deadlines/${id}/complete`, { notes }), {
    invalidate,
    success: (d) => `"${d.title}" marked complete`,
  })
}

export function useRescheduleDeadline() {
  return useApiMutation(({ id, ...body }: { id: number; due_date: string; reason: string }) => post<Deadline>(`/v1/deadlines/${id}/reschedule`, body), {
    invalidate,
    success: 'Deadline rescheduled',
  })
}

export function useCancelDeadline() {
  return useApiMutation(({ id, reason }: { id: number; reason: string }) => post<Deadline>(`/v1/deadlines/${id}/cancel`, { reason }), {
    invalidate,
    success: 'Deadline cancelled',
  })
}

export type BoardColumn = 'todo' | 'in_progress' | 'review' | 'done'

export function useTasks(params: { matter_id?: number; assignee?: string }) {
  return useQuery({ queryKey: ['tasks', params], queryFn: () => get<Deadline[]>('/v1/tasks', { ...params }) })
}

/** Moves a card immediately and rolls back if the server refuses. */
export function useMoveTask(params: { matter_id?: number; assignee?: string }) {
  const queryClient = useQueryClient()
  const key = ['tasks', params]
  return useMutation({
    mutationFn: ({ id, column }: { id: number; column: BoardColumn }) => post<Deadline>(`/v1/tasks/${id}/move`, { column }),
    onMutate: async ({ id, column }) => {
      await queryClient.cancelQueries({ queryKey: key })
      const previous = queryClient.getQueryData<Deadline[]>(key)
      queryClient.setQueryData<Deadline[]>(key, (tasks) =>
        tasks?.map((t) => (t.id !== id ? t : column === 'done' ? { ...t, status: 'completed' } : { ...t, progress: column, status: t.status === 'completed' ? 'pending' : t.status })),
      )
      return { previous }
    },
    onError: (_error, _vars, context) => queryClient.setQueryData(key, context?.previous),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['tasks'] }),
  })
}
