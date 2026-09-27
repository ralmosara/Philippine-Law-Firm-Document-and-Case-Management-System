import clsx from 'clsx'
import { Paperclip, Send, X } from 'lucide-react'
import { useEffect, useId, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { ApiError } from '@/shared/api/axios'
import { dateTime, fileSize } from '@/shared/lib/format'
import { Button, IconButton } from '@/shared/ui/Button'
import { FormError, Textarea } from '@/shared/ui/Form'
import type { ChatMessage } from '../api'

/**
 * A message thread and its reply box, for both the firm and the client
 * portal. `downloadUrl` differs per side (staff and portal file routes).
 */
export function Conversation({
  messages,
  onSend,
  sending,
  downloadUrl,
  otherSideLabel,
}: {
  messages: ChatMessage[]
  onSend: (body: string, file: File | null) => Promise<unknown>
  sending: boolean
  downloadUrl: (fileId: number) => string
  otherSideLabel: string
}) {
  const [body, setBody] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string | null>(null)
  const end = useRef<HTMLDivElement>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const inputId = useId()

  // Keep the newest message in view.
  // Braces matter: newer browsers return a Promise from scrollIntoView, which React would treat as a cleanup.
  useEffect(() => {
    end.current?.scrollIntoView({ block: 'end' })
  }, [messages.length])

  const send = async (e?: FormEvent) => {
    e?.preventDefault()
    if (!body.trim()) return
    setError(null)
    try {
      await onSend(body.trim(), file)
      setBody('')
      setFile(null)
      if (fileInput.current) fileInput.current.value = ''
    } catch (err) {
      const apiError = ApiError.from(err)
      setError(apiError.field('file') ?? apiError.field('body') ?? apiError.message)
    }
  }

  // Ctrl/Cmd+Enter sends; Enter alone makes a new line.
  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) void send()
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <ol aria-label="Messages" className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4">
        {messages.map((m) => (
          <li key={m.id} className={clsx('flex max-w-[85%] flex-col', m.mine ? 'self-end items-end' : 'self-start items-start')}>
            <span className="mb-1 text-xs text-on-surface-variant">{m.mine ? 'You' : (m.sender_name ?? otherSideLabel)} · {dateTime(m.created_at)}</span>
            <div className={clsx('rounded-2xl px-4 py-2.5 text-sm leading-6 whitespace-pre-wrap break-words', m.mine ? 'rounded-br-md bg-primary text-on-primary' : 'rounded-bl-md bg-surface-container-high text-on-surface')}>
              {m.body}
            </div>
            {m.attachment && (
              <a href={downloadUrl(m.attachment.id)} download className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                <Paperclip className="size-3.5" aria-hidden="true" /> {m.attachment.name} ({fileSize(m.attachment.size_bytes)})
              </a>
            )}
          </li>
        ))}
        <div ref={end} />
      </ol>

      <form onSubmit={send} className="border-t border-outline-variant p-3">
        <FormError message={error} />
        <label htmlFor={inputId} className="sr-only">Write a message</label>
        <Textarea id={inputId} rows={3} maxLength={10000} placeholder="Write a message…  (Ctrl+Enter to send)" value={body} onChange={(e) => setBody(e.target.value)} onKeyDown={onKeyDown} className="mt-2" />
        <div className="mt-2 flex items-center justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            <input ref={fileInput} type="file" className="sr-only" id={`${inputId}-file`} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            <label htmlFor={`${inputId}-file`} className="inline-flex cursor-pointer items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary/8">
              <Paperclip className="size-4" aria-hidden="true" /> Attach
            </label>
            {file && (
              <span className="flex min-w-0 items-center gap-1 text-xs text-on-surface-variant">
                <span className="truncate">{file.name}</span>
                <IconButton label="Remove attachment" onClick={() => { setFile(null); if (fileInput.current) fileInput.current.value = '' }}><X className="size-3.5" /></IconButton>
              </span>
            )}
          </div>
          <Button type="submit" icon={<Send className="size-4" />} loading={sending} disabled={!body.trim()}>Send</Button>
        </div>
      </form>
    </div>
  )
}
