import { useMemo, useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { usePrincipal } from './principal-context'
import { useLocale, useSetLocale } from './session-context'
import { shellCopy } from '../i18n'

export interface NavEntry {
  path: string
  label: string
}

export function AppShell({ onLogout }: { onLogout: () => void }) {
  const locale = useLocale()
  const setLocale = useSetLocale()
  const copy = shellCopy[locale]
  const principal = usePrincipal()
  const navigate = useNavigate()
  const location = useLocation()
  const [mobileNavOpen, setMobileNavOpen] = useState(false)

  const capabilities = principal.capabilities ?? []
  const features = principal.features ?? { work_management: false, tasks: false }

  const navEntries: NavEntry[] = useMemo(() => {
    const can = (cap: string) => capabilities.includes(cap)
    const entries: NavEntry[] = [
      { path: '/', label: copy.home },
      ...(features.tasks && can('tasks.list') ? [{ path: '/tasks', label: copy.tasks }] : []),
      ...(can('documents.list') ? [{ path: '/documents', label: copy.documents }] : []),
      ...(can('organization.cluster.read') || can('organization.facility.read')
        ? [{ path: '/organization', label: copy.organization }]
        : []),
      ...(can('identity.account.read') || can('authorization.role.read')
        ? [{ path: '/accounts-permissions', label: copy.accountsPermissions }]
        : []),
      ...(can('reporting.read') || can('audit.event.read')
        ? [{ path: '/reports-monitoring', label: copy.reportsMonitoring }]
        : []),
      ...(can('platform_settings.read') || can('platform_operations.health.read')
        ? [{ path: '/platform-management', label: copy.platformManagement }]
        : []),
    ]
    return entries
  }, [capabilities, features, copy])

  const currentPath = location.pathname

  return (
    <div className="shell">
      <aside className={`shell__sidebar${mobileNavOpen ? ' shell__sidebar--open' : ''}`}>
        <div className="shell__brand">{copy.brand}</div>
        <nav className="shell__nav" aria-label={copy.menu}>
          {navEntries.map((entry) => (
            <button
              key={entry.path}
              type="button"
              className={`shell__nav-item${currentPath === entry.path ? ' shell__nav-item--active' : ''}`}
              aria-current={currentPath === entry.path ? 'page' : undefined}
              onClick={() => {
                navigate(entry.path)
                setMobileNavOpen(false)
              }}
            >
              {entry.label}
            </button>
          ))}
        </nav>
      </aside>
      {mobileNavOpen && <div className="drawer-overlay" onClick={() => setMobileNavOpen(false)} />}
      <div className="shell__main">
        <header className="shell__header">
          <button type="button" className="button button--quiet" onClick={() => setMobileNavOpen((open) => !open)}>
            ☰
          </button>
          <div className="shell__header-spacer" />
          {principal.effectiveScope && (
            <span className="status-badge status-badge--info">{principal.effectiveScope.label}</span>
          )}
          <button type="button" className="button button--quiet" onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}>
            {locale === 'ar' ? 'English' : 'العربية'}
          </button>
          <button type="button" className="button button--quiet" onClick={onLogout}>
            {copy.logout}
          </button>
        </header>
        <main className="shell__content">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
