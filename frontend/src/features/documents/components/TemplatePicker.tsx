import { FilePlus2, FileText } from 'lucide-react'
import { useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMatterOptions } from '@/features/trust/api'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { Field, FormError, Input, Select } from '@/shared/ui/Form'
import clsx from 'clsx'
import { useCreateDocument, useTemplates } from '../api'

/** Merge fields the server fills in from the matter, client, lawyer and firm (see DocumentMerger::dataFor). */
export const STANDARD_FIELDS = new Set([
  'firm_name', 'firm_address', 'client_name', 'client_address', 'client_tin', 'matter_title', 'matter_reference',
  'case_number', 'case_type', 'court', 'court_branch', 'judge', 'lawyer_name', 'lawyer_roll_number', 'lawyer_ibp_number', 'date_today',
])

const humanize = (field: string) => field.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

/** Start a new document on a matter, from a template or blank. */
export function TemplatePicker({ open, onClose, matterId }: { open: boolean; onClose: () => void; matterId?: number }) {
  const templates = useTemplates()
  const matters = useMatterOptions({ enabled: open && !matterId })
  const create = useCreateDocument()
  const navigate = useNavigate()

  const [templateId, setTemplateId] = useState<number | 'blank' | null>(null)
  const [selectedMatter, setSelectedMatter] = useState(matterId ? String(matterId) : '')
  const [title, setTitle] = useState('')
  const [fields, setFields] = useState<Record<string, string>>({})
  const [error, setError] = useState<ApiError | null>(null)

  const template = typeof templateId === 'number' ? templates.data?.find((t) => t.id === templateId) : undefined
  const customFields = useMemo(() => template?.merge_fields.filter((f) => !STANDARD_FIELDS.has(f)) ?? [], [template])

  const close = () => {
    setTemplateId(null)
    setTitle('')
    setFields({})
    setError(null)
    onClose()
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const doc = await create.mutateAsync({
        matter_id: Number(selectedMatter),
        ...(template ? { template_id: template.id, fields, title: title || undefined } : { title, content: `${title.toUpperCase()}\n\n` }),
      })
      close()
      navigate(`/documents/${doc.id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open={open}
      onClose={close}
      title="New document"
      size="lg"
      footer={
        <>
          <Button variant="text" onClick={close}>Cancel</Button>
          <Button type="submit" form="new-document" loading={create.isPending} disabled={templateId === null || !selectedMatter || (templateId === 'blank' && !title.trim())}>
            Create draft
          </Button>
        </>
      }
    >
      <form id="new-document" onSubmit={submit} className="flex flex-col gap-5">
        <FormError message={error?.message} />

        {!matterId && (
          <Field label="Matter" required error={error?.field('matter_id')}>
            {(a) => (
              <Select {...a} value={selectedMatter} onChange={(e) => setSelectedMatter(e.target.value)} required>
                <option value="">Select a matter…</option>
                {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
              </Select>
            )}
          </Field>
        )}

        <fieldset>
          <legend className="mb-2 text-sm font-medium">Start from</legend>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <TemplateOption selected={templateId === 'blank'} onSelect={() => setTemplateId('blank')} icon={<FilePlus2 className="size-5" />} name="Blank document" detail="Start with an empty draft" />
            {templates.data?.map((t) => (
              <TemplateOption key={t.id} selected={templateId === t.id} onSelect={() => setTemplateId(t.id)} icon={<FileText className="size-5" />} name={t.name} detail={t.category ?? `${t.merge_fields.length} merge fields`} />
            ))}
          </div>
        </fieldset>

        <Field label="Title" required={templateId === 'blank'} hint={template ? `Defaults to "${template.name} — <matter title>"` : undefined}>
          {(a) => <Input {...a} value={title} onChange={(e) => setTitle(e.target.value)} />}
        </Field>

        {customFields.length > 0 && (
          <fieldset className="grid grid-cols-1 gap-4 rounded-[3px] bg-surface-container p-4 sm:grid-cols-2">
            <legend className="sr-only">Template fields</legend>
            <p className="text-sm text-on-surface-variant sm:col-span-2">This template needs a few details. Client, matter, court and lawyer fields are filled in automatically.</p>
            {customFields.map((f) => (
              <Field key={f} label={humanize(f)}>
                {(a) => <Input {...a} value={fields[f] ?? ''} onChange={(e) => setFields((all) => ({ ...all, [f]: e.target.value }))} />}
              </Field>
            ))}
          </fieldset>
        )}
      </form>
    </Dialog>
  )
}

function TemplateOption({ selected, onSelect, icon, name, detail }: { selected: boolean; onSelect: () => void; icon: ReactNode; name: string; detail: string }) {
  return (
    <button
      type="button"
      aria-pressed={selected}
      onClick={onSelect}
      className={clsx(
        'flex items-start gap-3 rounded-[3px] border p-3 text-left transition-colors',
        selected ? 'border-primary bg-primary-container/50' : 'border-outline-variant hover:bg-surface-container',
      )}
    >
      <span className={clsx('mt-0.5', selected ? 'text-primary' : 'text-on-surface-variant')}>{icon}</span>
      <span>
        <span className="block text-sm font-medium">{name}</span>
        <span className="block text-xs text-on-surface-variant">{detail}</span>
      </span>
    </button>
  )
}
