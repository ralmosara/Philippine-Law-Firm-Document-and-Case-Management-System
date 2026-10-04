import clsx from 'clsx'
import { ArrowLeft, MessageSquarePlus, MessagesSquare } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Conversation } from '@/features/messages/components/Conversation'
import { ApiError } from '@/shared/api/axios'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, PageHeader } from '@/shared/ui/Layout'
import { portalFileUrl, usePortalMatters, usePortalReply, usePortalStartThread, usePortalThread, usePortalThreads } from '../api'
import { t } from '@/shared/lib/i18n'

/** The client's secure inbox with their lawyers. */
export function PortalMessages() {
  const params = useParams()
  const threadId = params.id ? Number(params.id) : null
  const threads = usePortalThreads()
  const [composing, setComposing] = useState(false)

  return (
    <>
      <PageHeader
        back={<Link to="/portal" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary"><ArrowLeft className="size-4" /> {t('Your matters')}</Link>}
        title={t('Messages')}
        description={t('A private channel with your lawyers. Messages stay here, not in your email.')}
        actions={<Button icon={<MessageSquarePlus className="size-4" />} onClick={() => setComposing(true)}>{t('New message')}</Button>}
      />
      <Card className="grid h-[calc(100dvh-16rem)] min-h-[28rem] grid-cols-1 overflow-hidden md:grid-cols-[18rem_1fr]">
        <div className={clsx('min-h-0 overflow-y-auto border-outline-variant md:border-r', threadId && 'hidden md:block')}>
          {threads.isPending ? <PageLoader /> : threads.isError ? <ErrorState error={threads.error} /> : threads.data.data.length === 0 ? (
            <EmptyState icon={<MessagesSquare className="size-6" />} title={t('No messages yet')} description={t('Ask your lawyer a question about your matter.')} />
          ) : (
            <ul className="divide-y divide-outline-variant">
              {threads.data.data.map((t) => (
                <li key={t.id}>
                  <Link to={`/portal/messages/${t.id}`} aria-current={t.id === threadId ? 'page' : undefined} className={clsx('block px-4 py-3 hover:bg-surface-container', t.id === threadId && 'bg-primary-container/60')}>
                    <div className="flex items-start justify-between gap-2">
                      <span className={clsx('truncate text-sm', t.unread_count ? 'font-semibold' : 'font-medium')}>{t.subject}</span>
                      {!!t.unread_count && <span className="shrink-0 rounded-full bg-primary px-2 text-xs font-medium text-on-primary">{t.unread_count}</span>}
                    </div>
                    <p className="truncate text-xs text-on-surface-variant">{t.matter?.title} · {dateTime(t.last_message_at)}</p>
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className={clsx('flex min-h-0 flex-col', !threadId && 'hidden md:flex')}>
          {threadId ? <ClientConversation id={threadId} /> : <EmptyState icon={<MessagesSquare className="size-6" />} title={t('Choose a conversation')} />}
        </div>
      </Card>
      {composing && <NewPortalThread onClose={() => setComposing(false)} />}
    </>
  )
}

function ClientConversation({ id }: { id: number }) {
  const thread = usePortalThread(id)
  const reply = usePortalReply(id)

  if (thread.isPending) return <PageLoader />
  if (thread.isError) return <div className="p-4"><ErrorState error={thread.error} /></div>

  return (
    <>
      <div className="flex items-center gap-2 border-b border-outline-variant px-4 py-3">
        <Link to="/portal/messages" className="md:hidden" aria-label={t('Back to messages')}><ArrowLeft className="size-5" /></Link>
        <div className="min-w-0">
          <p className="truncate font-semibold">{thread.data.subject}</p>
          <p className="truncate text-xs text-on-surface-variant">{thread.data.matter?.title}</p>
        </div>
      </div>
      <Conversation
        messages={thread.data.messages ?? []}
        onSend={(body, file) => reply.mutateAsync({ body, file })}
        sending={reply.isPending}
        downloadUrl={portalFileUrl}
        otherSideLabel={t('Your lawyer')}
      />
    </>
  )
}

function NewPortalThread({ onClose }: { onClose: () => void }) {
  const matters = usePortalMatters()
  const start = usePortalStartThread()
  const navigate = useNavigate()
  const [form, setForm] = useState({ matter_id: '', subject: '', body: '' })
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const thread = await start.mutateAsync({ matter_id: Number(form.matter_id), subject: form.subject, body: form.body, file })
      onClose()
      navigate(`/portal/messages/${thread.id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title={t('New message')} footer={<><Button variant="text" onClick={onClose}>{t('Cancel')}</Button><Button type="submit" form="portal-thread" loading={start.isPending}>{t('Send')}</Button></>}>
      <form id="portal-thread" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : error?.field('file')} />
        <Field label={t('About which matter?')} required error={error?.field('matter_id')}>
          {(a) => (
            <Select {...a} required value={form.matter_id} onChange={(e) => setForm((f) => ({ ...f, matter_id: e.target.value }))}>
              <option value="">{t('Choose…')}</option>
              {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.title}</option>)}
            </Select>
          )}
        </Field>
        <Field label={t('Subject')} required error={error?.field('subject')}>
          {(a) => <Input {...a} required maxLength={255} value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} />}
        </Field>
        <Field label={t('Message')} required error={error?.field('body')}>
          {(a) => <Textarea {...a} required rows={6} maxLength={10000} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} />}
        </Field>
        <Field label={t('Attachment')} hint={t('Optional: a document or photo for your lawyer (up to 20 MB).')}>
          {(a) => <input {...a} type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" />}
        </Field>
      </form>
    </Dialog>
  )
}
