import { useQuery } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { ApiError, get, put } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { Button, IconButton } from '@/shared/ui/Button'
import { PageLoader } from '@/shared/ui/Feedback'
import { Checkbox, FormError, Input, Select } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'

export interface IntakeQuestion { key?: string; label: string; type: 'text' | 'textarea' | 'date' | 'number'; required: boolean; hint: string | null }
type Questions = Record<string, IntakeQuestion[]>

const TYPE_LABELS: Record<IntakeQuestion['type'], string> = { text: 'Short answer', textarea: 'Long answer', date: 'Date', number: 'Number' }

/** The firm's own questions on the public consultation form, for each type of case. */
export function IntakeQuestionsCard() {
  const query = useQuery({ queryKey: ['intake-questions'], queryFn: () => get<{ case_types: string[]; questions: Questions }>('/v1/intake-questions') })
  if (query.isPending || !query.data) return <Card className="lg:col-span-2"><PageLoader /></Card>
  return <QuestionsForm caseTypes={query.data.case_types} initial={query.data.questions} />
}

function QuestionsForm({ caseTypes, initial }: { caseTypes: string[]; initial: Questions }) {
  const [questions, setQuestions] = useState<Questions>(initial)
  const [caseType, setCaseType] = useState<string>(caseTypes.find((c) => initial[c]?.length) ?? caseTypes[0] ?? '')
  const save = useApiMutation((input: object) => put<{ questions: Questions }>('/v1/intake-questions', input), { invalidate: [['intake-questions']], success: 'Questions saved', toastErrors: false })
  const error = save.error ? ApiError.from(save.error) : null
  const list: IntakeQuestion[] = questions[caseType] ?? []
  const setList = (next: IntakeQuestion[]) => setQuestions({ ...questions, [caseType]: next })
  const update = (i: number, patch: Partial<IntakeQuestion>) => setList(list.map((q, j) => (j === i ? { ...q, ...patch } : q)))

  return (
    <Card className="lg:col-span-2">
      <CardHeader title="Consultation form questions" description="Ask more for each type of case (for example the date of dismissal for labor cases, or the title number for land). The answers show on the request and are added to the matter's description." />
      <div className="flex flex-col gap-4 p-5 text-sm">
        <Select aria-label="Type of case" value={caseType} onChange={(e) => setCaseType(e.target.value)} className="sm:w-64">
          {caseTypes.map((c) => <option key={c} value={c}>{c}{questions[c]?.length ? ` (${questions[c].length})` : ''}</option>)}
        </Select>
        {list.length === 0 && <p className="text-on-surface-variant">No extra questions for {caseType}.</p>}
        {list.map((q, i) => (
          <div key={i} className="flex flex-wrap items-center gap-2">
            <Input aria-label={`Question ${i + 1}`} value={q.label} onChange={(e) => update(i, { label: e.target.value })} placeholder="Date of dismissal" className="min-w-56 flex-1" />
            <Select aria-label={`Question ${i + 1}: kind of answer`} value={q.type} onChange={(e) => update(i, { type: e.target.value as IntakeQuestion['type'] })} className="w-40">
              {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </Select>
            <Input aria-label={`Question ${i + 1}: hint`} value={q.hint ?? ''} onChange={(e) => update(i, { hint: e.target.value || null })} placeholder="Hint (optional)" className="w-56" />
            <Checkbox label="Required" checked={q.required} onChange={(e) => update(i, { required: e.target.checked })} />
            <IconButton label={`Remove question ${i + 1}`} onClick={() => setList(list.filter((_, j) => j !== i))}><Trash2 className="size-4" /></IconButton>
          </div>
        ))}
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="text" icon={<Plus className="size-4" />} disabled={list.length >= 10} onClick={() => setList([...list, { label: '', type: 'text', required: false, hint: null }])}>Add a question</Button>
          <Button className="ml-auto" loading={save.isPending} onClick={() => save.mutate({ questions: Object.fromEntries(Object.entries(questions).map(([c, qs]) => [c, qs.filter((q) => q.label.trim())]).filter(([, qs]) => (qs as IntakeQuestion[]).length)) }, { onSuccess: (r) => setQuestions(Array.isArray(r.questions) ? {} : r.questions) })}>Save questions</Button>
        </div>
        <FormError message={error?.message ?? null} />
      </div>
    </Card>
  )
}
