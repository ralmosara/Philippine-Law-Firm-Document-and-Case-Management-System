import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { Copy, FilePlus2, MessageSquarePlus, RotateCcw, Send, Sparkles } from 'lucide-react'
import { Fragment, useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { fileDownloadUrl } from '@/features/documents/api'
import { ApiError } from '@/shared/api/axios'
import { Button } from '@/shared/ui/Button'
import { Dialog } from '@/shared/ui/Dialog'
import { EmptyState, PageLoader, Spinner } from '@/shared/ui/Feedback'
import { Field, FormError, Input, Textarea } from '@/shared/ui/Form'
import { Card } from '@/shared/ui/Layout'
import { useToast } from '@/shared/ui/Toast'
import { useAsk, useAssistantConversation, useAssistantConversations, useAssistantStatus, useRetryAnswer, useSaveAnswerAsDocument, type AiMessage } from '../api'

const QUICK_PROMPTS = [
  'Summarize this matter: parties, key facts, where the case stands and what is next.',
  'List every date and deadline mentioned in the documents and files, in order.',
  'What are the weaknesses in our client’s position, and what evidence would address them?',
  'Draft a demand letter to the opposing party based on the facts in the file.',
]

/** Ask Claude about this matter's own documents and files, with citations back to them. */
export function MatterAssistant({ matterId }: { matterId: number }) {
  const status = useAssistantStatus()
  const conversations = useAssistantConversations(matterId, !!status.data?.available)
  const [activeId, setActiveId] = useState<number | null>(null)
  const conversation = useAssistantConversation(activeId)
  const ask = useAsk(matterId)
  const queryClient = useQueryClient()
  const [question, setQuestion] = useState('')
  const end = useRef<HTMLDivElement>(null)
  const messages = conversation.data?.messages ?? []
  const pending = messages.some((m) => m.status === 'pending')

  // Braces matter: newer browsers return a Promise from scrollIntoView, which React would treat as a cleanup.
  useEffect(() => {
    end.current?.scrollIntoView({ block: 'end' })
  }, [messages.length, pending])

  if (status.isPending) return <PageLoader />
  if (!status.data?.available) {
    return <Card><EmptyState icon={<Sparkles className="size-6" />} title="AI assistant unavailable" description={status.data?.reason ?? undefined} /></Card>
  }

  const send = async (text: string, e?: FormEvent) => {
    e?.preventDefault()
    if (!text.trim() || pending) return
    const result = await ask.mutateAsync({ question: text.trim(), ...(activeId ? { conversation_id: activeId } : {}) })
    queryClient.setQueryData(['assistant', 'conversation', result.id], result)
    setActiveId(result.id)
    setQuestion('')
  }

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) void send(question)
  }

  return (
    <Card className="grid h-[calc(100dvh-16rem)] min-h-[32rem] grid-cols-1 overflow-hidden md:grid-cols-[16rem_1fr]">
      <aside className="hidden min-h-0 flex-col border-r border-outline-variant md:flex">
        <div className="p-3">
          <Button variant="tonal" size="sm" className="w-full" icon={<MessageSquarePlus className="size-4" />} onClick={() => setActiveId(null)}>New conversation</Button>
        </div>
        <ul className="min-h-0 flex-1 overflow-y-auto">
          {conversations.data?.map((c) => (
            <li key={c.id}>
              <button type="button" onClick={() => setActiveId(c.id)} className={clsx('w-full truncate px-4 py-2 text-left text-sm hover:bg-surface-container', c.id === activeId && 'bg-primary-container/60 font-medium')} title={c.title}>
                {c.title}
              </button>
            </li>
          ))}
        </ul>
        <p className="border-t border-outline-variant p-3 text-[11px] leading-4 text-on-surface-variant">Conversations are private to you. Each question sends this matter’s documents and files to Anthropic and is logged.</p>
      </aside>

      <div className="flex min-h-0 flex-col">
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {!activeId ? (
            <div className="mx-auto flex max-w-xl flex-col items-center gap-4 py-8 text-center">
              <Sparkles className="size-8 text-primary" aria-hidden="true" />
              <div>
                <p className="font-semibold">Ask about this matter</p>
                <p className="text-sm text-on-surface-variant">Answers come from the matter’s documents and uploaded files, with citations you can open. Always verify before relying on them.</p>
              </div>
              <div className="grid w-full gap-2 sm:grid-cols-2">
                {QUICK_PROMPTS.map((p) => (
                  <button key={p} type="button" disabled={ask.isPending} onClick={() => void send(p)} className="rounded-xl border border-outline-variant p-3 text-left text-sm hover:border-primary hover:bg-primary/5 disabled:opacity-50">{p}</button>
                ))}
              </div>
            </div>
          ) : conversation.isPending ? <PageLoader /> : (
            <ol className="mx-auto flex max-w-3xl flex-col gap-4" aria-label="Conversation" aria-live="polite">
              {messages.map((m) => <AssistantTurn key={m.id} message={m} />)}
              <div ref={end} />
            </ol>
          )}
        </div>

        <form onSubmit={(e) => void send(question, e)} className="border-t border-outline-variant p-3">
          <label htmlFor="assistant-question" className="sr-only">Ask the assistant</label>
          <div className="flex items-end gap-2">
            <Textarea id="assistant-question" rows={2} maxLength={8000} placeholder="Ask about this matter, or ask for a draft…  (Ctrl+Enter to send)" value={question} onChange={(e) => setQuestion(e.target.value)} onKeyDown={onKeyDown} className="flex-1" />
            <Button type="submit" icon={<Send className="size-4" />} loading={ask.isPending} disabled={!question.trim() || pending}>Ask</Button>
          </div>
          <p className="mt-1 text-[11px] text-on-surface-variant">AI can be wrong. Check facts against the cited sources and law against current rules and jurisprudence.</p>
        </form>
      </div>
    </Card>
  )
}

function AssistantTurn({ message }: { message: AiMessage }) {
  const retry = useRetryAnswer()
  const toast = useToast()
  const [saving, setSaving] = useState(false)

  if (message.role === 'user') {
    return <li className="self-end max-w-[85%] rounded-2xl rounded-br-md bg-primary px-4 py-2.5 text-sm whitespace-pre-wrap text-on-primary">{message.content}</li>
  }

  if (message.status === 'pending') {
    return <li className="flex items-center gap-2 text-sm text-on-surface-variant"><Spinner className="size-4" /> Reading the case file and writing an answer…</li>
  }

  if (message.status === 'failed') {
    return (
      <li className="flex flex-col items-start gap-2 rounded-xl bg-danger-container p-3 text-sm text-on-danger-container">
        {message.error}
        <Button variant="text" size="sm" icon={<RotateCcw className="size-4" />} loading={retry.isPending} onClick={() => retry.mutate(message.id)}>Try again</Button>
      </li>
    )
  }

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(message.content ?? '')
      toast.success('Copied')
    } catch {
      toast.error('Copy failed')
    }
  }

  return (
    <li className="flex flex-col gap-2">
      <div className="rounded-2xl rounded-bl-md bg-surface-container-high px-4 py-3 text-sm leading-6 whitespace-pre-wrap text-on-surface">
        <WithCitations text={message.content ?? ''} />
      </div>
      <div className="flex gap-1">
        <Button variant="text" size="sm" icon={<Copy className="size-4" />} onClick={copy}>Copy</Button>
        <Button variant="text" size="sm" icon={<FilePlus2 className="size-4" />} onClick={() => setSaving(true)}>Save as draft document</Button>
      </div>
      {saving && <SaveDialog messageId={message.id} onClose={() => setSaving(false)} />}
    </li>
  )
}

/** Turns [D12], [F5] and [M] into links to the cited document, file or matter facts. */
function WithCitations({ text }: { text: string }) {
  const parts = text.split(/(\[(?:M|[DF]\d+)\])/g)
  return (
    <>
      {parts.map((part, i) => {
        const match = /^\[(M|D|F)(\d*)\]$/.exec(part)
        if (!match) return <Fragment key={i}>{part}</Fragment>
        const [, kind, id] = match
        const chip = 'mx-0.5 inline-flex items-center rounded-md bg-primary-container px-1.5 text-[11px] font-semibold text-on-primary-container align-baseline no-underline hover:brightness-95'
        if (kind === 'D') return <Link key={i} to={`/documents/${id}`} className={chip} title="Open the cited document">D{id}</Link>
        if (kind === 'F') return <a key={i} href={fileDownloadUrl(Number(id))} download className={chip} title="Download the cited file">F{id}</a>
        return <span key={i} className={chip} title="From the matter’s details">Matter</span>
      })}
    </>
  )
}

function SaveDialog({ messageId, onClose }: { messageId: number; onClose: () => void }) {
  const save = useSaveAnswerAsDocument()
  const navigate = useNavigate()
  const [title, setTitle] = useState('')
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    try {
      const { document_id } = await save.mutateAsync({ messageId, title })
      navigate(`/documents/${document_id}`)
    } catch (err) {
      setError(ApiError.from(err))
    }
  }

  return (
    <Dialog open onClose={onClose} title="Save as a draft document" description="It opens in the document editor as version 1, marked as drafted with the AI assistant."
      footer={<><Button variant="text" onClick={onClose}>Cancel</Button><Button type="submit" form="save-answer" loading={save.isPending} disabled={!title.trim()}>Save and open</Button></>}>
      <form id="save-answer" onSubmit={submit} className="flex flex-col gap-4">
        <FormError message={error && !Object.keys(error.errors).length ? error.message : undefined} />
        <Field label="Document title" required error={error?.field('title')}>
          {(a) => <Input {...a} autoFocus required maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Demand letter to Mr. Reyes" />}
        </Field>
      </form>
    </Dialog>
  )
}
