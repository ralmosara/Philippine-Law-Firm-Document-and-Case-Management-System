import clsx from 'clsx'
import {
  BarChart3,
  ChartNoAxesColumn,
  Briefcase,
  CalendarDays,
  FileText,
  Inbox,
  KanbanSquare,
  Landmark,
  LogOut,
  MessagesSquare,
  Menu,
  Receipt,
  Scale,
  Settings,
  ShieldCheck,
  Users,
  UserRound,
  X,
  FileLock2,
} from 'lucide-react'
import { useEffect, useState, type ComponentType } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { GlobalTimeTracker } from '@/features/billing/components/GlobalTimeTracker'
import { useCurrentSession, useLogout } from '@/features/auth/session'
import { useUnreadMessages } from '@/features/messages/api'
import { useOpenIntakeCount } from '@/features/intake/api'
import type { Abilities } from '@/shared/api/types'
import { IconButton } from '@/shared/ui/Button'
import { NotificationBell } from '@/features/notifications/components/NotificationBell'
import { Avatar } from '@/shared/ui/Feedback'

interface NavItem {
  to: string
  label: string
  icon: ComponentType<{ className?: string }>
  ability?: keyof Abilities
  end?: boolean
  /** A live count shown next to the label. */
  badge?: 'messages' | 'intake'
}

const NAV: NavItem[] = [
  { to: '/', label: 'Home', icon: BarChart3, end: true },
  { to: '/matters', label: 'Matters', icon: Briefcase },
  { to: '/clients', label: 'Clients', icon: UserRound },
  { to: '/calendar', label: 'Calendar', icon: CalendarDays },
  { to: '/tasks', label: 'Tasks', icon: KanbanSquare },
  { to: '/messages', label: 'Messages', icon: MessagesSquare, badge: 'messages' },
  { to: '/intake', label: 'Intake', icon: Inbox, ability: 'work_matters', badge: 'intake' },
  { to: '/documents', label: 'Documents', icon: FileText },
  { to: '/billing', label: 'Time & Billing', icon: Receipt },
  { to: '/trust', label: 'Trust Accounts', icon: Landmark, ability: 'work_matters' },
  { to: '/reports', label: 'Reports', icon: ChartNoAxesColumn, ability: 'manage_finances' },
  { to: '/compliance', label: 'Compliance', icon: ShieldCheck },
  { to: '/privacy', label: 'Data Privacy', icon: FileLock2, ability: 'manage_firm' },
  { to: '/settings', label: 'Firm Settings', icon: Settings, ability: 'manage_firm' },
]

export function AppShell() {
  const { user, firm, abilities } = useCurrentSession()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const location = useLocation()

  // Close the mobile drawer on navigation.
  useEffect(() => setDrawerOpen(false), [location.pathname])

  const items = NAV.filter((item) => !item.ability || abilities[item.ability])
  const badges = { messages: useUnreadMessages().data ?? 0, intake: useOpenIntakeCount(abilities.work_matters).data ?? 0 }

  const nav = (
    <nav aria-label="Main" className="flex flex-col gap-0.5 px-3">
      {items.map(({ to, label, icon: Icon, end, badge }) => (
        <NavLink
          key={to}
          to={to}
          end={end}
          className={({ isActive }) =>
            clsx(
              'flex h-10 items-center gap-3 rounded-full px-4 text-sm font-medium transition-colors',
              isActive ? 'bg-primary-container text-on-primary-container' : 'text-on-surface-variant hover:bg-on-surface/5',
            )
          }
        >
          <Icon className="size-5 shrink-0" aria-hidden="true" />
          <span className="flex-1">{label}</span>
          {badge && badges[badge] > 0 && (
            <span className="rounded-full bg-primary px-2 text-xs font-semibold text-on-primary" aria-label={`${badges[badge]} ${badge === 'messages' ? 'unread' : 'to review'}`}>{badges[badge]}</span>
          )}
        </NavLink>
      ))}
    </nav>
  )

  const brand = (
    <div className="flex items-center gap-3 px-6 py-5">
      <span className="flex size-9 items-center justify-center rounded-xl bg-primary text-on-primary">
        <Scale className="size-5" aria-hidden="true" />
      </span>
      <div className="min-w-0">
        <p className="text-sm leading-tight font-semibold">Lex PH</p>
        <p className="truncate text-xs text-on-surface-variant">{firm.name}</p>
      </div>
    </div>
  )

  return (
    <div className="min-h-dvh lg:grid lg:grid-cols-[16rem_1fr]">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-full focus:bg-primary focus:px-4 focus:py-2 focus:text-on-primary">
        Skip to content
      </a>

      {/* Desktop navigation */}
      <aside className="sticky top-0 hidden h-dvh flex-col overflow-y-auto border-r border-outline-variant bg-surface-dim lg:flex">
        {brand}
        {nav}
      </aside>

      {/* Mobile drawer */}
      {drawerOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button type="button" aria-label="Close menu" className="absolute inset-0 bg-black/40" onClick={() => setDrawerOpen(false)} />
          <aside className="absolute inset-y-0 left-0 flex w-72 flex-col overflow-y-auto rounded-r-3xl bg-surface">
            <div className="flex items-center justify-between pr-3">
              {brand}
              <IconButton label="Close menu" onClick={() => setDrawerOpen(false)}>
                <X className="size-5" />
              </IconButton>
            </div>
            {nav}
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center gap-2 border-b border-outline-variant bg-surface-dim/90 px-4 backdrop-blur lg:px-8">
          <IconButton label="Open menu" onClick={() => setDrawerOpen(true)} className="lg:hidden">
            <Menu className="size-5" />
          </IconButton>
          <div className="flex-1" />
          <NotificationBell userId={user.id} />
          <UserMenu name={user.name} role={user.role_label} email={user.email} />
        </header>

        <main id="main" tabIndex={-1} className="mx-auto w-full max-w-7xl flex-1 px-4 pt-6 pb-24 focus:outline-none lg:px-8 lg:pt-8">
          <Outlet />
        </main>
      </div>

      {abilities.work_matters && <GlobalTimeTracker />}
    </div>
  )
}

function UserMenu({ name, role, email }: { name: string; role: string; email: string }) {
  const [open, setOpen] = useState(false)
  const logout = useLogout()
  const navigate = useNavigate()

  useEffect(() => {
    if (!open) return
    const close = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [open])

  return (
    <div className="relative">
      <button
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className="flex items-center gap-2 rounded-full py-1 pr-3 pl-1 hover:bg-on-surface/5"
      >
        <Avatar name={name} />
        <span className="hidden text-left sm:block">
          <span className="block text-sm leading-tight font-medium">{name}</span>
          <span className="block text-xs text-on-surface-variant">{role}</span>
        </span>
      </button>

      {open && (
        <>
          <button type="button" aria-hidden="true" tabIndex={-1} className="fixed inset-0 z-40 cursor-default" onClick={() => setOpen(false)} />
          <div role="menu" className="absolute right-0 z-50 mt-2 w-64 rounded-2xl border border-outline-variant bg-surface p-2 shadow-(--shadow-elevated)">
            <div className="px-3 py-2">
              <p className="text-sm font-medium">{name}</p>
              <p className="truncate text-xs text-on-surface-variant">{email}</p>
            </div>
            <hr className="my-1 border-outline-variant" />
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false)
                navigate('/profile')
              }}
              className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-container"
            >
              <Users className="size-4" aria-hidden="true" /> Profile & password
            </button>
            <button
              type="button"
              role="menuitem"
              onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })}
              className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-container"
            >
              <LogOut className="size-4" aria-hidden="true" /> Sign out
            </button>
          </div>
        </>
      )}
    </div>
  )
}
