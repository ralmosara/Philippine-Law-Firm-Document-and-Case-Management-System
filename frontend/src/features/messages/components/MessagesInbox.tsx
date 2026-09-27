import clsx from 'clsx'
import { ArrowLeft, MessageSquarePlus, MessagesSquare } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAbilities } from '@/features/auth/session'
import { fileDownloadUrl } from '@/features/documents/api'
import { useMatterOptions } from '@/features/trust/api'
import { ApiError } from '@/shared/api/axios'
import { dateTime } from '@/shared/lib/format'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { EmptyState, ErrorState, PageLoader } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Select, Textarea } from '@/shared/ui/Form'
import { Card, PageHeader } from '@/shared/ui/Layout'
import { useReply, useStartThread, useThread, useThreads, type MessageThread } from '../api'
import { Conversation } from './Conversation'

/** Secure messages with clients: every matter's conversations in one inbox. */
export function MessagesInbox() {
  const params = useParams()
  const threadId = params.id ? Number(params.id) : null
  const abilities = useAbilities()
  const [composing, setComposing] = useState(false)

  return (
    <>
      <PageHeader
        title="Messages"
        description="Private conversations with clients. Clients read and reply in the portal; email alerts never include the message."
        actions={abilities.work_matters && <Button icon={<MessageSquarePlus className="size-4" />} onClick={() => setComposing(true)}>New message</Button>}
      />
      <Card className="grid h-[calc(100dvh-14rem)] min-h-[28rem] grid-cols-1 overflow-hidden md:grid-cols-[20rem_1fr]">
        <div className={clsx('min-h-0 overflow-y-auto border-outline-variant md:border-r', threadId && 'hidden md:block')}>
          <ThreadList activeId={threadId} linkTo={(t) => `/messages/${t.id}`} />
        </div>
        <div className={clsx('flex min-h-0 flex-col', !threadId && 'hidden md:flex')}>
          {threadId ? <StaffConversation id={threadId} /> : <EmptyState icon={<MessagesSquare className="size-6" />} title="Choose a conversation" />}
        </div>
      </Card>
      {composing && <NewThreadDialog onClose={() => setComposing(false)} />}
    </>
  )
}

export function ThreadList({ activeId, linkTo, matterId }: { activeId?: number | null; linkTo: (thread: MessageThread) => string; matterId?: number }) {
  const threads = useThreads({ matter_id: matterId })

  if (threads.isPending) return <PageLoader />
  if (threads.isError) return <div className="p-4"><ErrorState error={threads.error} /></div>
  if (threads.data.data.length === 0) return <EmptyState icon={<MessagesSquare className="size-6" />} title="No conversations yet" />

  return (
    <ul className="divide-y divide-outline-variant">
      {threads.data.data.map((t) => (
        <li key={t.id}>
          <Link to={linkTo(t)} aria-current={t.id === activeId ? 'page' : undefined} className={clsx('block px-4 py-3 hover:bg-surface-container', t.id === activeId && 'bg-primary-container/60')}>
            <div className="flex items-start justify-between gap-2">
              <span className={clsx('truncate text-sm', t.unread_count ? 'font-semibold' : 'font-medium')}>{t.client?.name}</span>
              {!!t.unread_count && <span className="shrink-0 rounded-full bg-primary px-2 text-xs font-medium text-on-primary">{t.unread_count}</span>}
            </div>
            <p className="truncate text-sm">{t.subject}</p>
            <p className="truncate text-xs text-on-surface-variant">{t.matter?.reference} · {dateTime(t.last_message_at)}</p>
            {t.preview && <p className="mt-0.5 line-clamp-1 text-xs text-on-surface-variant">{t.preview}</p>}
          </Link>
        </li>
      ))}
    </ul>
  )
}

function StaffConversation({ id }: { id: number }) {
  const thread = useThread(id)
  const reply = useReply(id)

  if (thread.isPending) return <PageLoader />
  if (thread.isError) return <div className="p-4"><ErrorState error={thread.error} onRetry={() => thread.refetch()} /></div>
  const t = thread.data

  return (
    <>
      <div className="flex items-center gap-2 border-b border-outline-variant px-4 py-3">
        <Link to="/messages" className="md:hidden" aria-label="Back to conversations"><ArrowLeft className="size-5" /></Link>
        <div className="min-w-0">
          <p className="truncate font-semibold">{t.subject}</p>
          <p className="truncate text-xs text-on-surface-variant">
            {t.client?.name} · {t.matter && <Link to={`/matters/${t.matter.id}`} className="text-primary hover:underline">{t.matter.reference} · {t.matter.title}</Link>}
          </p>
        </div>
      </div>
      <Conversation
        messages={t.messages ?? []}
        onSend={(body, file) => reply.mutateAsync({ body, file })}
        sending={reply.isPending}
        downloadUrl={fileDownloadUrl}
        otherSideLabel={t.client?.name ?? 'Client'}
      />
    </>
  )
}

export function NewThreadDialog({ onClose, matterId }: { onClose: () => void; matterId?: number }) {
  const start = useStartThread()
  const matters = useMatterOptions({ enabled: !matterId })
  const navigate = useNavigate()
  const [form, setForm] = useState({ matter_id: matterId ? String(matterId) : '', subject: '', body: '' })
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const thread = await start.mutateAsync({ matter_id: Number(form.matter_id), subject: form.subject, body: form.body, file })
      onClose()
      navigate(`/messages/${thread.id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title="New message to a client"
      description="The client gets an email alert and reads the message in the portal."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="new-thread" loading={start.isPending}>Send</Button></>}
    >
      <form id="new-thread" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : error?.field('file')} />
        {!matterId && (
          <Field label="Matter" required error={error?.field('matter_id')}>
            {(a) => (
              <Select {...a} required value={form.matter_id} onChange={(e) => setForm((f) => ({ ...f, matter_id: e.target.value }))}>
                <option value="">Choose a matter…</option>
                {matters.data?.map((m) => <option key={m.id} value={m.id}>{m.reference} · {m.title}</option>)}
              </Select>
            )}
          </Field>
        )}
        <Field label="Subject" required error={error?.field('subject')}>
          {(a) => <Input {...a} required maxLength={255} value={form.subject} onChange={(e) => setForm((f) => ({ ...f, subject: e.target.value }))} />}
        </Field>
        <Field label="Message" required error={error?.field('body')}>
          {(a) => <Textarea {...a} required rows={6} maxLength={10000} value={form.body} onChange={(e) => setForm((f) => ({ ...f, body: e.target.value }))} />}
        </Field>
        <Field label="Attachment" hint="Optional. Saved to the matter’s files and shared with the client.">
          {(a) => <input {...a} type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" />}
        </Field>
      </form>
    </Dialog>
  )
}
