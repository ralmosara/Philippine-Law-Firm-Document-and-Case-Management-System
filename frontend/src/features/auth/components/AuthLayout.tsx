import { Scale } from 'lucide-react'
import type { ReactNode } from 'react'

/** The centred card used by sign-in, two-factor and password reset screens. */
export function AuthLayout({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <main className="flex min-h-dvh items-center justify-center bg-surface-dim px-4 py-12">
      <div className="w-full max-w-md">
        <div className="rounded-3xl border border-outline-variant bg-surface p-8 sm:p-10">
          <div className="mb-8 flex flex-col items-center text-center">
            <span className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-primary text-on-primary">
              <Scale className="size-6" aria-hidden="true" />
            </span>
            <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
            {subtitle && <p className="mt-1 text-sm text-on-surface-variant">{subtitle}</p>}
          </div>
          {children}
        </div>
        {footer && <p className="mt-6 text-center text-sm text-on-surface-variant">{footer}</p>}
      </div>
    </main>
  )
}
