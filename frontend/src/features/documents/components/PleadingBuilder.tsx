import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { KnowledgePicker } from '@/features/knowledge/components/KnowledgeDialogs'
import { useLawyerOptions } from '@/features/users/api'
import { ApiError, get, post } from '@/shared/api/axios'
import { useApiMutation } from '@/shared/api/hooks'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { FormError, Checkbox, Field, Input, Select, Textarea } from '@/shared/ui/Form'

interface PleadingOptions {
  types: { value: string; label: string; initiatory: boolean }[]
  client_roles: { value: string; label: string }[]
}

interface PleadingInput {
  type: string
  title?: string
  body?: string
  counsel_id?: number
  verification: boolean
  certification: boolean
  service: boolean
}

function usePleadingOptions(enabled = true) {
  return useQuery({ queryKey: ['pleadings', 'options'], queryFn: () => get<PleadingOptions>('/v1/pleadings/options'), staleTime: Infinity, enabled })
}

/**
 * Assemble a pleading from the matter: caption, title, body, prayer,
 * signature block, and the verification, certification and service parts
 * the Rules require. A live preview shows exactly what will be created.
 */
export function PleadingBuilder({ matterId, open, onClose }: { matterId: number; open: boolean; onClose: () => void }) {
  const options = usePleadingOptions(open)
  const lawyers = useLawyerOptions(open)
  const navigate = useNavigate()
  const [form, setForm] = useState<PleadingInput>({ type: 'motion', title: '', body: '', verification: false, certification: false, service: true })
  const [preview, setPreview] = useState('')
  const [warnings, setWarnings] = useState<string[]>([])
  const [picking, setPicking] = useState(false)
  const create = useApiMutation((input: PleadingInput) => post<{ id: number }>(`/v1/matters/${matterId}/pleadings`, clean(input)), {
    invalidate: [['documents']],
    success: 'Pleading created as a draft document',
    toastErrors: false,
  })

  // Initiatory pleadings need a verification and a certification against forum shopping.
  const setType = (type: string) => {
    const initiatory = options.data?.types.find((t) => t.value === type)?.initiatory ?? false
    setForm((f) => ({ ...f, type, verification: initiatory, certification: initiatory }))
  }

  useEffect(() => {
    if (!open) return
    const timer = setTimeout(() => {
      post<{ text: string; warnings?: string[] }>(`/v1/matters/${matterId}/pleadings/preview`, clean(form)).then((r) => { setPreview(r.text); setWarnings(r.warnings ?? []) }).catch(() => setPreview(''))
    }, 300)
    return () => clearTimeout(timer)
  }, [form, matterId, open])

  const error = create.error ? ApiError.from(create.error) : null

  return (
    <Dialog
      open={open}
      onClose={onClose}
      size="xl"
      title="Draft a pleading"
      description="Filled in from the matter: court, parties, docket number and your signature details. Edit the result like any document, then download it as Word or PDF."
      footer={
        <>
          <Button variant="text" onClick={onClose}>Cancel</Button>
          <Button loading={create.isPending} onClick={() => create.mutate(form, { onSuccess: (d) => { onClose(); navigate(`/documents/${d.id}`) } })}>Create document</Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[18rem_1fr]">
        <div className="flex flex-col gap-4">
          <Field label="Kind">
            {(a) => (
              <Select {...a} value={form.type} onChange={(e) => setType(e.target.value)}>
                {options.data?.types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
              </Select>
            )}
          </Field>
          <Field label="Title" hint="e.g. Motion for Extension of Time to File Answer">
            {(a) => <Input {...a} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />}
          </Field>
          <Field label="Signed by">
            {(a) => (
              <Select {...a} value={form.counsel_id ?? ''} onChange={(e) => setForm({ ...form, counsel_id: e.target.value ? Number(e.target.value) : undefined })}>
                <option value="">Responsible lawyer</option>
                {lawyers.data?.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
              </Select>
            )}
          </Field>
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-sm font-medium">Include</legend>
            <Checkbox label="Verification (Rule 7, Sec. 4)" checked={form.verification} onChange={(e) => setForm({ ...form, verification: e.target.checked })} />
            <Checkbox label="Certification against forum shopping (Rule 7, Sec. 5)" checked={form.certification} onChange={(e) => setForm({ ...form, certification: e.target.checked })} />
            <Checkbox label="Explanation of service and copy furnished" checked={form.service} onChange={(e) => setForm({ ...form, service: e.target.checked })} />
          </fieldset>
          <Field label="Body (optional)" hint="Leave blank for an outline to fill in.">
            {(a) => <Textarea {...a} rows={6} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} />}
          </Field>
          <Button size="sm" variant="text" className="self-start" onClick={() => setPicking(true)}>Insert from the knowledge bank…</Button>
          {picking && <KnowledgePicker onClose={() => setPicking(false)} onInsert={(text) => setForm((f) => ({ ...f, body: f.body ? `${f.body}

${text}` : text }))} />}
          <FormError message={error?.message} />
        </div>
        <div className="min-w-0">
          <p className="mb-1 text-sm font-medium">Preview</p>
          {warnings.length > 0 && (
            <div role="status" className="mb-2 rounded-[3px] bg-warning-container p-3 text-sm text-on-warning-container">
              <p className="font-medium">Check the signature details before filing:</p>
              <ul className="list-disc pl-5">{warnings.map((w) => <li key={w}>{w}</li>)}</ul>
              <p className="mt-1">Update them under your profile (Signature details for pleadings).</p>
            </div>
          )}
          <pre className="max-h-[60vh] overflow-auto rounded-[3px] border border-outline-variant bg-surface-container p-4 font-mono text-xs leading-relaxed whitespace-pre" aria-label="Pleading preview">{preview || 'Preparing preview…'}</pre>
        </div>
      </div>
    </Dialog>
  )
}

function clean(input: PleadingInput) {
  return { ...input, title: input.title || undefined, body: input.body || undefined }
}
