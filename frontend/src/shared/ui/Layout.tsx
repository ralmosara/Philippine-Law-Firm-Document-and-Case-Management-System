import clsx from 'clsx'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import type { ReactNode, ThHTMLAttributes, TdHTMLAttributes } from 'react'
import type { Paginated } from '@/shared/api/types'
import { IconButton } from './Button'

export function PageHeader({ title, description, actions, back }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; back?: ReactNode }) {
  return (
    <header className="mb-4 border-b border-outline-variant pb-3">
      {back && <div className="mb-1 text-xs">{back}</div>}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-normal text-on-surface">{title}</h1>
          {description && <div className="mt-0.5 text-sm text-on-surface-variant">{description}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-1.5">{actions}</div>}
      </div>
    </header>
  )
}

export function Card({ children, className, as: Tag = 'section' }: { children: ReactNode; className?: string; as?: 'section' | 'div' | 'article' }) {
  return <Tag className={clsx('rounded-(--radius-card) border border-outline-variant bg-surface shadow-[0_1px_1px_rgb(20_35_60/0.06)]', className)}>{children}</Tag>
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="border-b border-outline-variant">
      <div className="flex min-h-9 items-center justify-between gap-3 bg-portlet px-3 py-1.5">
        <h2 className="text-sm font-bold tracking-wide text-on-portlet uppercase">{title}</h2>
        {actions && <div className="flex shrink-0 items-center gap-1.5">{actions}</div>}
      </div>
      {description && <p className="px-3 py-2 text-sm text-on-surface-variant">{description}</p>}
    </div>
  )
}

export function StatCard({ label, value, detail, tone }: { label: string; value: ReactNode; detail?: ReactNode; tone?: 'danger' | 'warning' }) {
  return (
    <Card className={clsx('border-l-4 px-4 py-3', tone === 'danger' ? 'border-l-danger' : tone === 'warning' ? 'border-l-warning' : 'border-l-primary')} as="div">
      <p className="text-xs font-semibold tracking-wide text-on-surface-variant uppercase">{label}</p>
      <p className={clsx('mt-1 text-2xl font-semibold tabular-nums', tone === 'danger' ? 'text-danger' : tone === 'warning' ? 'text-warning' : 'text-on-surface')}>{value}</p>
      {detail && <p className="mt-0.5 text-xs text-on-surface-variant">{detail}</p>}
    </Card>
  )
}

export function DescriptionList({ items }: { items: { label: string; value: ReactNode }[] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
      {items.map((item) => (
        <div key={item.label}>
          <dt className="text-xs font-semibold tracking-wide text-on-surface-variant uppercase">{item.label}</dt>
          <dd className="mt-0.5 text-sm text-on-surface">{item.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}

/* Tables: semantic <table> for screen readers, horizontally scrollable on small screens. */

/** `compact` drops the minimum width, for tables inside narrow cards. */
export function Table({ children, caption, compact = false }: { children: ReactNode; caption?: string; compact?: boolean }) {
  return (
    // relative: absolutely positioned content (sr-only labels) stays inside
    // the scroll area instead of widening the whole page on phones.
    <div className="relative overflow-x-auto">
      <table className={clsx('w-full border-collapse text-left text-sm [&_tbody_tr:nth-child(even)]:bg-surface-container', !compact && 'min-w-[40rem]')}>
        {caption && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  )
}

export function Th({ className, align, ...props }: ThHTMLAttributes<HTMLTableCellElement> & { align?: 'right' }) {
  return <th scope="col" className={clsx('border-b border-outline bg-surface-container-high px-3 py-1.5 text-xs font-bold tracking-wide whitespace-nowrap text-on-portlet uppercase', align === 'right' && 'text-right', className)} {...props} />
}

export function Td({ className, align, ...props }: TdHTMLAttributes<HTMLTableCellElement> & { align?: 'right' }) {
  return <td className={clsx('border-b border-outline-variant px-3 py-1.5 align-middle text-on-surface', align === 'right' && 'text-right tabular-nums', className)} {...props} />
}

export function Tr({ children, onClick, className }: { children: ReactNode; onClick?: () => void; className?: string }) {
  return (
    <tr
      className={clsx('transition-colors last:[&>td]:border-b-0', onClick && 'cursor-pointer hover:!bg-primary-container/60', className)}
      onClick={onClick}
    >
      {children}
    </tr>
  )
}

export function Pagination<T>({ page, onPage }: { page: Paginated<T> | undefined; onPage: (page: number) => void }) {
  if (!page || page.meta.last_page <= 1) return null
  const { current_page, last_page, from, to, total } = page.meta
  return (
    <nav aria-label="Pagination" className="flex items-center justify-end gap-2 border-t border-outline-variant bg-surface-container px-3 py-1 text-xs text-on-surface-variant">
      <span>
        {from}–{to} of {total}
      </span>
      <IconButton label="Previous page" disabled={current_page <= 1} onClick={() => onPage(current_page - 1)}>
        <ChevronLeft className="size-5" />
      </IconButton>
      <IconButton label="Next page" disabled={current_page >= last_page} onClick={() => onPage(current_page + 1)}>
        <ChevronRight className="size-5" />
      </IconButton>
    </nav>
  )
}

export function Tabs<T extends string>({ tabs, value, onChange, label }: { tabs: { value: T; label: string; count?: number }[]; value: T; onChange: (value: T) => void; label: string }) {
  return (
    <div role="tablist" aria-label={label} className="mb-4 flex gap-0.5 overflow-x-auto border-b border-outline">
      {tabs.map((tab) => {
        const selected = tab.value === value
        return (
          <button
            key={tab.value}
            type="button"
            role="tab"
            aria-selected={selected}
            onClick={() => onChange(tab.value)}
            className={clsx(
              'relative -mb-px shrink-0 rounded-t-[3px] border px-3.5 py-1.5 text-sm font-semibold transition-colors',
              selected ? 'border-outline border-b-surface-dim bg-surface-dim text-on-surface' : 'border-outline-variant bg-surface-container-high text-on-surface-variant hover:bg-portlet hover:text-on-surface',
            )}
          >
            {tab.label}
            {tab.count !== undefined && <span className="ml-1.5 rounded-[2px] bg-primary px-1.5 text-xs text-on-primary">{tab.count}</span>}
          </button>
        )
      })}
    </div>
  )
}
