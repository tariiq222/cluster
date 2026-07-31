import { useCallback, useEffect, useMemo, useState } from 'react'
import { usePrincipal } from './principal-context'
import { useLocale, useSetLocale } from './session-context'
import { shellCopy } from '../i18n'
import { pathFromRoute, routeFromPath, type AppRoute, type RouteName } from '../routes'
import { WorkspaceContent } from './WorkspaceContent'

export interface NavEntry {
  route: RouteName
  label: string
  path: string
}

export function AppShell({ onLogout }: { onLogout: () => void }) {
  const locale = useLocale()
  const setLocale = useSetLocale()
  const copy = shellCopy[locale]
  const principal = usePrincipal()
  const [route, setRoute] = useState<AppRoute>(() => routeFromPath(window.location.pathname))
  const [mobileNavOpen, setMobileNavOpen] = useState(false)

  const navigate = useCallback((path: string) => {
    window.history.pushState({}, '', path)
    setRoute(routeFromPath(path))
    setMobileNavOpen(false)
  }, [])

  useEffect(() => {
    const onPopState = () => setRoute(routeFromPath(window.location.pathname))
    window.addEventListener('popstate', onPopState)
    return () => window.removeEventListener('popstate', onPopState)
  }, [])

  const capabilities = principal.capabilities ?? []
  const features = principal.features ?? { work_management: false, tasks: false }

  const navEntries: NavEntry[] = useMemo(() => {
    const can = (cap: string) => capabilities.includes(cap)
    const entries: NavEntry[] = [
      { route: 'home', label: copy.home, path: '/' },
      ...(features.tasks && can('tasks.list') ? [{ route: 'tasks' as RouteName, label: copy.tasks, path: '/tasks' }] : []),
      ...(can('documents.list') ? [{ route: 'documents' as RouteName, label: copy.documents, path: '/documents' }] : []),
      ...(can('organization.cluster.read') || can('organization.facility.read')
        ? [{ route: 'organization' as RouteName, label: copy.organization, path: '/organization' }]
        : []),
      ...(can('identity.account.read') || can('authorization.role.read')
        ? [{ route: 'accounts-permissions' as RouteName, label: copy.accountsPermissions, path: '/accounts-permissions' }]
        : []),
      ...(can('reporting.read') || can('audit.event.read')
        ? [{ route: 'reports-monitoring' as RouteName, label: copy.reportsMonitoring, path: '/reports-monitoring' }]
        : []),
      ...(can('platform_settings.read') || can('platform_operations.health.read')
        ? [{ route: 'platform-management' as RouteName, label: copy.platformManagement, path: '/platform-management' }]
        : []),
    ]
    return entries
  }, [capabilities, features, copy])

  const currentName = route.name

  return (
    <div className="shell">
      <aside className={`shell__sidebar${mobileNavOpen ? ' shell__sidebar--open' : ''}`}>
        <div className="shell__brand">{copy.brand}</div>
        <nav className="shell__nav" aria-label={copy.menu}>
          {navEntries.map((entry) => (
            <button
              key={entry.route}
              type="button"
              className={`shell__nav-item${currentName === entry.route ? ' shell__nav-item--active' : ''}`}
              aria-current={currentName === entry.route ? 'page' : undefined}
              onClick={() => navigate(entry.path)}
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
          <button
            type="button"
            className="button button--quiet"
            onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}
          >
            {locale === 'ar' ? 'English' : 'العربية'}
          </button>
          <button type="button" className="button button--quiet" onClick={onLogout}>
            {copy.logout}
          </button>
        </header>
        <main className="shell__content">
          <WorkspaceContent route={route} navigate={navigate} />
        </main>
      </div>
    </div>
  )
}

export { pathFromRoute }
