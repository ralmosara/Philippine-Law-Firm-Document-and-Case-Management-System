import { FileCode2, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useAbilities } from '@/features/auth/session'
import { ApiError } from '@/shared/api/axios'
import type { DocumentTemplate } from '@/shared/api/types'
import { dateTime } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { ConfirmDialog, Dialog } from '@/shared/ui/Dialog'
import { Badge, EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Textarea } from '@/shared/ui/Form'
import { Card, CardHeader } from '@/shared/ui/Layout'
import { useDeleteTemplate, useSaveTemplate, useTemplates } from '../api'
import { STANDARD_FIELDS } from './TemplatePicker'

export function TemplatesManager() {
  const abilities = useAbilities()
  const templates = useTemplates()
  const remove = useDeleteTemplate()
  const [editing, setEditing] = useState<DocumentTemplate | 'new' | null>(null)
  const [deleting, setDeleting] = useState<DocumentTemplate | null>(null)

  return (
    <Card>
      <CardHeader
        title="Templates"
        description={<>Write placeholders like <code className="rounded bg-surface-container px-1">{'{{ client_name }}'}</code>; matter, client, court and lawyer fields fill in automatically.</>}
        actions={abilities.work_matters && <Button variant="tonal" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>New template</Button>}
      />
      {templates.isPending ? (
        <PageLoader />
      ) : templates.isError ? (
        <ErrorState error={templates.error} />
      ) : templates.data.length === 0 ? (
        <EmptyState icon={<FileCode2 className="size-6" />} title="No templates yet" />
      ) : (
        <ul className="divide-y divide-outline-variant">
          {templates.data.map((t) => (
            <li key={t.id} className="flex items-center gap-4 px-5 py-3">
              <div className="min-w-0 flex-1">
                <p className="font-medium">{t.name}</p>
                <p className="text-xs text-on-surface-variant">{t.category ?? 'Uncategorised'} · updated {dateTime(t.updated_at)}</p>
              </div>
              <span className="hidden flex-wrap justify-end gap-1 md:flex">
                {t.merge_fields.filter((f) => !STANDARD_FIELDS.has(f)).map((f) => <Badge key={f}>{f}</Badge>)}
              </span>
              {abilities.work_matters && <IconButton label={`Edit ${t.name}`} onClick={() => setEditing(t)}><Pencil className="size-4" /></IconButton>}
              {abilities.manage_firm && <IconButton label={`Delete ${t.name}`} onClick={() => setDeleting(t)}><Trash2 className="size-4" /></IconButton>}
            </li>
          ))}
        </ul>
      )}

      {editing && <TemplateDialog template={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete template?"
        description="Documents already generated from it are not affected."
        destructive
        confirmLabel="Delete"
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
      />
    </Card>
  )
}

function TemplateDialog({ template, onClose }: { template?: DocumentTemplate; onClose: () => void }) {
  const save = useSaveTemplate(template?.id)
  const [name, setName] = useState(template?.name ?? '')
  const [category, setCategory] = useState(template?.category ?? '')
  const [body, setBody] = useState(template?.body ?? '')
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      await save.mutateAsync({ name, category: category || null, body })
      onClose()
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title={template ? 'Edit template' : 'New template'} size="xl" footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="template-form" loading={save.isPending}>Save template</Button></>}>
      <form id="template-form" onSubmit={submit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2"><FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} /></div>
        <Field label="Name" required error={error?.field('name')}>
          {(a) => <Input {...a} required value={name} onChange={(e) => setName(e.target.value)} />}
        </Field>
        <Field label="Category" hint="e.g. pleading, contract, affidavit, letter">
          {(a) => <Input {...a} value={category} onChange={(e) => setCategory(e.target.value)} />}
        </Field>
        <Field label="Body" required className="sm:col-span-2" error={error?.field('body')} hint={`Automatic fields: ${[...STANDARD_FIELDS].join(', ')}`}>
          {(a) => <Textarea {...a} required rows={18} className="font-mono text-[13px]" value={body} onChange={(e) => setBody(e.target.value)} />}
        </Field>
      </form>
    </Dialog>
  )
}
