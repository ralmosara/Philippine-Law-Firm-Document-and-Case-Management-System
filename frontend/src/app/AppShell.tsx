import clsx from 'clsx'
import { ChevronDown, LogOut, Menu, Scale, Search, Users, X } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { GlobalTimeTracker } from '@/features/billing/components/GlobalTimeTracker'
import { useCurrentSession, useLogout } from '@/features/auth/session'
import { useUnreadMessages } from '@/features/messages/api'
import { useOpenIntakeCount } from '@/features/intake/api'
import { NotificationBell } from '@/features/notifications/components/NotificationBell'
import type { Abilities } from '@/shared/api/types'
import { Avatar } from '@/shared/ui/Feedback'

interface NavItem {
  to: string
  label: string
  ability?: keyof Abilities
  end?: boolean
  /** A live count shown next to the label. */
  badge?: 'messages' | 'intake'
}

interface NavGroup {
  label: string
  /** A group with a single link and no menu (Home). */
  to?: string
  items: NavItem[]
}

/** The menu bar, grouped the way the work is: cases, documents, money, compliance, setup. */
const GROUPS: NavGroup[] = [
  { label: 'Home', to: '/', items: [{ to: '/', label: 'Home', end: true }] },
  {
    label: 'Matters',
    items: [
      { to: '/matters', label: 'Matters' },
      { to: '/clients', label: 'Clients' },
      { to: '/intake', label: 'Intake requests', ability: 'work_matters', badge: 'intake' },
      { to: '/prospects', label: 'Business development', ability: 'work_matters' },
      { to: '/court-day', label: 'Court day', ability: 'work_matters' },
      { to: '/corporate', label: 'Corporate secretarial' },
      { to: '/directory', label: 'Directory' },
      { to: '/calendar', label: 'Calendar' },
      { to: '/tasks', label: 'Tasks' },
    ],
  },
  {
    label: 'Documents',
    items: [
      { to: '/documents', label: 'Documents & files' },
      { to: '/messages', label: 'Client messages', badge: 'messages' },
      { to: '/knowledge', label: 'Knowledge bank' },
    ],
  },
  {
    label: 'Billing',
    items: [
      { to: '/billing', label: 'Time & billing' },
      { to: '/trust', label: 'Trust accounts', ability: 'work_matters' },
      { to: '/disbursements', label: 'Cash advances', ability: 'work_matters' },
      { to: '/reports', label: 'Reports', ability: 'manage_finances' },
      { to: '/tax', label: 'BIR tax compliance', ability: 'manage_finances' },
    ],
  },
  {
    label: 'Compliance',
    items: [
      { to: '/compliance', label: 'Conflicts, MCLE & notarial' },
      { to: '/privacy', label: 'Data privacy', ability: 'manage_firm' },
    ],
  },
  { label: 'Setup', items: [{ to: '/settings', label: 'Firm settings', ability: 'manage_firm' }] },
]

const SEARCHES = [
  { value: 'matters', label: 'Matters', path: '/matters' },
  { value: 'clients', label: 'Clients', path: '/clients' },
  { value: 'files', label: 'Files', path: '/documents?tab=files' },
] as const

export function AppShell() {
  const { user, firm, abilities } = useCurrentSession()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const location = useLocation()

  // Close the mobile menu on navigation.
  useEffect(() => setDrawerOpen(false), [location.pathname])

  const groups = GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => !i.ability || abilities[i.ability]) })).filter((g) => g.items.length > 0)
  const badges = { messages: useUnreadMessages().data ?? 0, intake: useOpenIntakeCount(abilities.work_matters).data ?? 0 }
  const groupBadge = (g: NavGroup) => g.items.reduce((sum, i) => sum + (i.badge ? badges[i.badge] : 0), 0)
  const isActive = (g: NavGroup) => g.items.some((i) => (i.end ? location.pathname === i.to : location.pathname === i.to || location.pathname.startsWith(`${i.to}/`)))

  return (
    <div className="flex min-h-dvh flex-col">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-primary focus:px-4 focus:py-2 focus:text-on-primary">
        Skip to content
      </a>

      <header className="sticky top-0 z-30 text-on-nav shadow-[0_1px_3px_rgb(0_0_0/0.25)]">
        {/* Top bar: brand, search, notifications, user. */}
        <div className="flex h-12 items-center gap-3 bg-nav px-3 lg:px-4">
          <button type="button" aria-label="Open menu" onClick={() => setDrawerOpen(true)} className="flex size-9 items-center justify-center rounded-[3px] hover:bg-nav-hover lg:hidden">
            <Menu className="size-5" />
          </button>
          <NavLink to="/" className="flex min-w-0 items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-[3px] bg-primary text-white"><Scale className="size-4" aria-hidden="true" /></span>
            <span className="hidden min-w-0 sm:block">
              <span className="block text-sm leading-tight font-bold">Lex PH</span>
              <span className="block max-w-56 truncate text-xs leading-tight opacity-75">{firm.name}</span>
            </span>
          </NavLink>
          <GlobalSearch />
          <div className="ml-auto flex items-center gap-1">
            <NotificationBell userId={user.id} />
            <UserMenu name={user.name} role={user.role_label} email={user.email} />
          </div>
        </div>

        {/* Menu bar with drop-down groups (desktop). */}
        <nav aria-label="Main" className="hidden h-9 items-stretch bg-nav-2 px-2 lg:flex">
          {groups.map((g) => <MenuGroup key={g.label} group={g} active={isActive(g)} badge={groupBadge(g)} badges={badges} />)}
        </nav>
      </header>

      {/* Mobile menu: the same groups, listed. */}
      {drawerOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button type="button" aria-label="Close menu" className="absolute inset-0 bg-black/40" onClick={() => setDrawerOpen(false)} />
          <nav aria-label="Main" className="absolute inset-y-0 left-0 flex w-72 flex-col overflow-y-auto bg-surface shadow-(--shadow-elevated)">
            <div className="flex h-12 items-center justify-between bg-nav px-3 text-on-nav">
              <span className="text-sm font-bold">Lex PH</span>
              <button type="button" aria-label="Close menu" onClick={() => setDrawerOpen(false)} className="flex size-9 items-center justify-center rounded-[3px] hover:bg-nav-hover"><X className="size-5" /></button>
            </div>
            {groups.map((g) => (
              <div key={g.label} className="border-b border-outline-variant py-1">
                {g.items.length > 1 && <p className="px-4 pt-2 pb-1 text-xs font-bold tracking-wide text-on-surface-variant uppercase">{g.label}</p>}
                {g.items.map((i) => (
                  <NavLink key={i.to} to={i.to} end={i.end} className={({ isActive: on }) => clsx('flex items-center justify-between px-4 py-2 text-sm', on ? 'bg-primary-container font-semibold text-on-primary-container' : 'text-on-surface hover:bg-surface-container')}>
                    {i.label}
                    {i.badge && badges[i.badge] > 0 && <span className="rounded-[2px] bg-primary px-1.5 text-xs font-semibold text-on-primary">{badges[i.badge]}</span>}
                  </NavLink>
                ))}
              </div>
            ))}
          </nav>
        </div>
      )}

      <main id="main" tabIndex={-1} className="mx-auto w-full max-w-[1600px] flex-1 px-3 pt-4 pb-24 focus:outline-none lg:px-5">
        <Outlet />
      </main>

      {abilities.work_matters && <GlobalTimeTracker />}
    </div>
  )
}

/** One menu on the bar: opens on hover or click, closes on Escape or leaving. */
function MenuGroup({ group, active, badge, badges }: { group: NavGroup; active: boolean; badge: number; badges: Record<'messages' | 'intake', number> }) {
  const [open, setOpen] = useState(false)
  const location = useLocation()
  const closeTimer = useRef<ReturnType<typeof setTimeout>>(undefined)

  useEffect(() => setOpen(false), [location.pathname])
  useEffect(() => {
    if (!open) return
    const close = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false)
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [open])

  const item = clsx('flex items-center gap-1.5 px-3.5 text-sm font-semibold transition-colors', active ? 'bg-surface-dim text-on-surface' : 'text-on-nav hover:bg-nav-hover')

  if (group.to) {
    return <NavLink to={group.to} end className={item}>{group.label}</NavLink>
  }

  return (
    <div
      className="relative flex"
      onMouseEnter={() => { clearTimeout(closeTimer.current); setOpen(true) }}
      onMouseLeave={() => { closeTimer.current = setTimeout(() => setOpen(false), 120) }}
    >
      <button type="button" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((o) => !o)} className={item}>
        {group.label}
        {badge > 0 && <span className="rounded-[2px] bg-primary px-1 text-xs text-on-primary" aria-label={`${badge} new`}>{badge}</span>}
        <ChevronDown className="size-3.5 opacity-70" aria-hidden="true" />
      </button>
      {open && (
        <div role="menu" className="absolute top-full left-0 z-50 min-w-56 border border-outline bg-surface py-1 shadow-(--shadow-elevated)">
          {group.items.map((i) => (
            <NavLink
              key={i.to}
              to={i.to}
              end={i.end}
              role="menuitem"
              className={({ isActive: on }) => clsx('flex items-center justify-between gap-4 px-3 py-1.5 text-sm', on ? 'bg-primary-container font-semibold text-on-primary-container' : 'text-on-surface hover:bg-primary hover:text-on-primary')}
            >
              {i.label}
              {i.badge && badges[i.badge] > 0 && <span className="rounded-[2px] bg-primary px-1.5 text-xs font-semibold text-on-primary">{badges[i.badge]}</span>}
            </NavLink>
          ))}
        </div>
      )}
    </div>
  )
}

/** Search matters, clients or files from anywhere. */
function GlobalSearch() {
  const navigate = useNavigate()
  const [scope, setScope] = useState<(typeof SEARCHES)[number]['value']>('matters')
  const [term, setTerm] = useState('')

  const submit = (e: FormEvent) => {
    e.preventDefault()
    const target = SEARCHES.find((s) => s.value === scope) ?? SEARCHES[0]
    const q = term.trim()
    navigate(q ? `${target.path}${target.path.includes('?') ? '&' : '?'}q=${encodeURIComponent(q)}` : target.path)
    setTerm('')
  }

  return (
    <form role="search" onSubmit={submit} className="mx-auto hidden h-8 w-full max-w-xl overflow-hidden rounded-[3px] bg-surface text-on-surface md:flex">
      <label className="sr-only" htmlFor="global-search-scope">Search in</label>
      <select id="global-search-scope" value={scope} onChange={(e) => setScope(e.target.value as typeof scope)} className="border-r border-outline-variant bg-surface-container-high px-2 text-xs font-semibold text-on-surface focus:outline-none">
        {SEARCHES.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
      </select>
      <input
        type="search"
        aria-label="Search"
        value={term}
        onChange={(e) => setTerm(e.target.value)}
        placeholder="Search by name, reference, case number…"
        className="min-w-0 flex-1 bg-transparent px-2.5 text-sm placeholder:text-on-surface-variant focus:outline-none"
      />
      <button type="submit" aria-label="Run search" className="flex w-9 items-center justify-center bg-primary text-on-primary hover:bg-primary-hover">
        <Search className="size-4" />
      </button>
    </form>
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
        className="flex items-center gap-2 rounded-[3px] py-1 pr-2 pl-1 hover:bg-nav-hover"
      >
        <Avatar name={name} className="size-7" />
        <span className="hidden text-left sm:block">
          <span className="block text-sm leading-tight font-semibold">{name}</span>
          <span className="block text-xs leading-tight opacity-75">{role}</span>
        </span>
        <ChevronDown className="hidden size-3.5 opacity-70 sm:block" aria-hidden="true" />
      </button>

      {open && (
        <>
          <button type="button" aria-hidden="true" tabIndex={-1} className="fixed inset-0 z-40 cursor-default" onClick={() => setOpen(false)} />
          <div role="menu" className="absolute right-0 z-50 mt-1 w-64 border border-outline bg-surface py-1 text-on-surface shadow-(--shadow-elevated)">
            <div className="border-b border-outline-variant px-3 py-2">
              <p className="text-sm font-semibold">{name}</p>
              <p className="truncate text-xs text-on-surface-variant">{email}</p>
            </div>
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                setOpen(false)
                navigate('/profile')
              }}
              className="flex w-full items-center gap-2.5 px-3 py-1.5 text-left text-sm hover:bg-primary hover:text-on-primary"
            >
              <Users className="size-4" aria-hidden="true" /> Profile & password
            </button>
            <button
              type="button"
              role="menuitem"
              onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/login', { replace: true }) })}
              className="flex w-full items-center gap-2.5 px-3 py-1.5 text-left text-sm hover:bg-primary hover:text-on-primary"
            >
              <LogOut className="size-4" aria-hidden="true" /> Sign out
            </button>
          </div>
        </>
      )}
    </div>
  )
}
