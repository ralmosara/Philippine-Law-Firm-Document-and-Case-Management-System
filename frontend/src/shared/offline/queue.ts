import { AxiosError } from 'axios'
import { apiClient } from '@/shared/api/axios'

/**
 * Saves that wait for a signal. In a courtroom or a provincial hall of
 * justice the phone is often offline; a time entry or a hearing outcome
 * recorded there is kept on the phone and sent when the connection returns.
 *
 * Each item carries an Idempotency-Key, so the server records it once even
 * if it arrives twice (the first answer lost on the way back). Only a few
 * writes are queueable; everything else needs the server's answer at once.
 */

export interface QueuedWrite {
  id: string
  method: 'post'
  url: string
  body: unknown
  label: string
  createdAt: string
  userId: number
}

export interface FailedWrite extends QueuedWrite {
  error: string
}

const DB = 'lexph-offline'
const STORE = 'writes'
const FAILED = 'failed'

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB, 1)
    req.onupgradeneeded = () => {
      req.result.createObjectStore(STORE, { keyPath: 'id' })
      req.result.createObjectStore(FAILED, { keyPath: 'id' })
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

async function tx<T>(store: string, mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await open()
  return new Promise((resolve, reject) => {
    const req = run(db.transaction(store, mode).objectStore(store))
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

const listeners = new Set<() => void>()
const changed = () => listeners.forEach((l) => l())

export function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export async function pending(userId: number): Promise<QueuedWrite[]> {
  const all = await tx<QueuedWrite[]>(STORE, 'readonly', (s) => s.getAll())
  return all.filter((w) => w.userId === userId).sort((a, b) => a.createdAt.localeCompare(b.createdAt))
}

export async function failed(userId: number): Promise<FailedWrite[]> {
  return (await tx<FailedWrite[]>(FAILED, 'readonly', (s) => s.getAll())).filter((w) => w.userId === userId)
}

export async function dismissFailed(id: string): Promise<void> {
  await tx(FAILED, 'readwrite', (s) => s.delete(id))
  changed()
}

/** Whether a request failed for want of a connection (rather than being refused). */
function offline(error: unknown): boolean {
  if (typeof navigator !== 'undefined' && !navigator.onLine) return true
  const cause = (error as { cause?: unknown })?.cause
  const axiosError = error instanceof AxiosError ? error : cause instanceof AxiosError ? cause : null
  if (axiosError) return !axiosError.response
  return (error as { status?: number })?.status === 0
}

/**
 * Send now, or keep it for later when there is no connection.
 * Resolves with the server's answer, or with `{ queued: true }`.
 */
export async function sendOrQueue<T>(write: { url: string; body: unknown; label: string; userId: number }): Promise<T | { queued: true }> {
  const id = crypto.randomUUID()
  try {
    return (await apiClient.post<T>(write.url, write.body, { headers: { 'Idempotency-Key': id } })).data
  } catch (error) {
    if (!offline(error)) throw error
    await tx(STORE, 'readwrite', (s) => s.put({ ...write, id, method: 'post', createdAt: new Date().toISOString() } satisfies QueuedWrite))
    changed()
    return { queued: true }
  }
}

let flushing: Promise<number> | null = null

/**
 * Send what is waiting, oldest first. Stops at the first connection failure;
 * a write the server refuses (e.g. the hearing was already recorded from the
 * office) is set aside with the reason, for the user to see.
 */
export function flush(userId: number): Promise<number> {
  flushing ??= (async () => {
    let sent = 0
    try {
      for (const write of await pending(userId)) {
        try {
          await apiClient.post(write.url, write.body, { headers: { 'Idempotency-Key': write.id } })
        } catch (error) {
          if (offline(error)) break
          const message = (error as { message?: string }).message ?? 'Refused by the server'
          await tx(FAILED, 'readwrite', (s) => s.put({ ...write, error: message } satisfies FailedWrite))
        }
        await tx(STORE, 'readwrite', (s) => s.delete(write.id))
        sent++
        changed()
      }
    } finally {
      flushing = null
    }
    return sent
  })()
  return flushing
}

export function isQueued(result: unknown): result is { queued: true } {
  return typeof result === 'object' && result !== null && (result as { queued?: boolean }).queued === true
}
