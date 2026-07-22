import { type MouseEvent, type ReactNode, type RefObject, useEffect, useRef, useState } from 'react'
import { numberFormattingLocale } from './copy'
import {
  Bell,
  Building2,
  ChevronDown,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  PanelRightClose,
  PanelRightOpen,
  Search,
  ShieldCheck,
  X,
} from 'lucide-react'

import './AppShell.css'

type Locale = 'ar' | 'en'

export type SidebarNavigationItem = {
  key: string
  label: string
  path: string
  icon: ReactNode
  active: boolean
  count?: string
  onSelect: () => void
}

export type SidebarNavigationGroup = {
  key: string
  label: string
  icon: ReactNode
  items: SidebarNavigationItem[]
}

export type AppShellCopy = {
  platform: string
  switchLanguage: string
  currentFacility: string
  notifications: string
  profile: string
  logout: string
  rightsReserved: string
  organizationName: string
  officeName: string
  ownerName: string
  openNavigation: string
  closeNavigation: string
  closeNotifications: string
  navigationTitle: string
  platformUser: string
  internalSystem: string
  collapseNavigation: string
  expandNavigation: string
}

type AppShellProps = {
  locale: Locale
  copy: AppShellCopy
  facilityName: string
  navigationGroups: SidebarNavigationGroup[]
  unreadNotifications: number
  notificationButtonRef: RefObject<HTMLButtonElement | null>
  notificationsOpen: boolean
  onLocaleChange: () => void
  onNotificationsToggle: () => void
  onLogout: () => void
  /** Where the user-menu profile entry points. Omit to hide the entry. */
  personalSecurity?: { path: string; onSelect: () => void }
  notificationPanel: ReactNode
  children: ReactNode
  globalSearchLabel?: string
  onGlobalSearch?: (query: string) => void
}

type SidebarContentProps = {
  copy: AppShellCopy
  navigationGroups: SidebarNavigationGroup[]
  openGroups: Record<string, boolean>
  onToggleGroup: (groupKey: string) => void
  headingId: string
  onNavigate: () => void
  showCloseButton?: boolean
  onClose?: () => void
}

function SidebarContent({
  copy,
  headingId,
  navigationGroups,
  onClose,
  onNavigate,
  onToggleGroup,
  openGroups,
  showCloseButton = false,
}: SidebarContentProps) {
  function follow(event: MouseEvent<HTMLAnchorElement>, action: () => void) {
    event.preventDefault()
    onNavigate()
    action()
  }

  return (
    <div className="sidebar-content">
      <div className="sidebar-brand-row">
        <div className="sidebar-brand" id={headingId}>
          <span className="sidebar-mark" aria-hidden="true"><ShieldCheck /></span>
          <span>
            <strong>{copy.platform}</strong>
            <small>{copy.internalSystem}</small>
          </span>
        </div>
        {showCloseButton && (
          <button type="button" className="shell-icon-button sidebar-close" aria-label={copy.closeNavigation} onClick={onClose}>
            <X aria-hidden="true" />
          </button>
        )}
      </div>

      <nav className="primary-navigation" aria-label={copy.navigationTitle}>
        {navigationGroups.map((group) => {
          const expanded = openGroups[group.key] === true
          const listId = `${headingId}-${group.key}`
          return (
            <section className="navigation-group" key={group.key}>
              <button
                type="button"
                className="navigation-group-toggle"
                aria-expanded={expanded}
                aria-controls={listId}
                onClick={() => onToggleGroup(group.key)}
              >
                <span className="navigation-icon" aria-hidden="true">{group.icon}</span>
                <span className="navigation-group-label">{group.label}</span>
                <ChevronDown className="navigation-group-chevron" aria-hidden="true" />
              </button>
              <ul
                id={listId}
                className="navigation-group-items"
                data-group-label={group.label}
                hidden={!expanded}
              >
                {group.items.map((item) => (
                  <li key={item.key}>
                    <a
                      href={item.path}
                      aria-label={item.label}
                      aria-current={item.active ? 'page' : undefined}
                      onClick={(event) => follow(event, item.onSelect)}
                    >
                      <span className="navigation-icon" aria-hidden="true">{item.icon}</span>
                      <span>{item.label}</span>
                      {item.count && <span className="navigation-item-count">{item.count}</span>}
                    </a>
                  </li>
                ))}
              </ul>
            </section>
          )
        })}
      </nav>
    </div>
  )
}

export function AppShell({
  children,
  copy,
  facilityName,
  locale,
  navigationGroups,
  notificationButtonRef,
  notificationsOpen,
  onLocaleChange,
  onLogout,
  onNotificationsToggle,
  personalSecurity,
  notificationPanel,
  unreadNotifications,
  globalSearchLabel,
  onGlobalSearch,
}: AppShellProps) {
  const [navigationOpen, setNavigationOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [globalSearchQuery, setGlobalSearchQuery] = useState('')
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem('cluster.sidebar-collapsed') === 'true'
    } catch {
      return false
    }
  })
  const activeGroupKey = navigationGroups.find((group) => group.items.some((item) => item.active))?.key
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>(() => {
    const initialGroupKey = activeGroupKey ?? navigationGroups[0]?.key
    return initialGroupKey ? { [initialGroupKey]: true } : {}
  })
  const navigationButtonRef = useRef<HTMLButtonElement>(null)
  const navigationPanelRef = useRef<HTMLElement>(null)
  const notificationsPanelRef = useRef<HTMLElement>(null)
  const notificationLabel = unreadNotifications > 0
    ? `${copy.notifications}: ${new Intl.NumberFormat(numberFormattingLocale(locale)).format(unreadNotifications)}`
    : copy.notifications

  useEffect(() => {
    if (!activeGroupKey) return
    setOpenGroups({ [activeGroupKey]: true })
  }, [activeGroupKey])

  function toggleGroup(groupKey: string) {
    setOpenGroups((current) => current[groupKey] ? {} : { [groupKey]: true })
  }

  function closeNavigation() {
    setNavigationOpen(false)
    window.requestAnimationFrame(() => navigationButtonRef.current?.focus())
  }

  function toggleSidebar() {
    setSidebarCollapsed((current) => {
      const next = !current
      try {
        window.localStorage.setItem('cluster.sidebar-collapsed', String(next))
      } catch {
        // The sidebar still changes when browser preference storage is unavailable.
      }
      return next
    })
  }

  useEffect(() => {
    if (!navigationOpen) return

    const panel = navigationPanelRef.current
    const focusable = panel?.querySelectorAll<HTMLElement>('button, a[href], [tabindex]:not([tabindex="-1"])')
    const desktopQuery = window.matchMedia('(min-width: 768px)')
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    focusable?.[0]?.focus()

    function onDesktopChange(event: MediaQueryListEvent) {
      if (!event.matches) return
      setNavigationOpen(false)
      window.requestAnimationFrame(() => {
        document.querySelector<HTMLElement>('.desktop-sidebar [aria-current="page"]')?.focus()
      })
    }

    function onKeyDown(event: globalThis.KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setNavigationOpen(false)
        window.requestAnimationFrame(() => navigationButtonRef.current?.focus())
        return
      }
      if (event.key !== 'Tab' || !focusable?.length) return

      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    desktopQuery.addEventListener('change', onDesktopChange)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      desktopQuery.removeEventListener('change', onDesktopChange)
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
    }
  }, [navigationOpen])

  useEffect(() => {
    if (!notificationsOpen) return

    const panel = notificationsPanelRef.current
    const focusable = panel?.querySelectorAll<HTMLElement>('button, a[href], input, textarea, select, [tabindex]:not([tabindex="-1"])')
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    focusable?.[0]?.focus()

    function onKeyDown(event: globalThis.KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        onNotificationsToggle()
        window.requestAnimationFrame(() => notificationButtonRef.current?.focus())
        return
      }
      if (event.key !== 'Tab' || !focusable?.length) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
    }
  }, [notificationButtonRef, notificationsOpen, onNotificationsToggle])

  return (
    <>
      <div
      className="app-shell"
      data-locale={locale}
      data-sidebar-collapsed={sidebarCollapsed}
      inert={notificationsOpen ? true : undefined}
    >
      <aside className="desktop-sidebar" aria-labelledby="desktop-sidebar-heading">
        <SidebarContent
          copy={copy}
          headingId="desktop-sidebar-heading"
          navigationGroups={navigationGroups}
          onNavigate={() => undefined}
          onToggleGroup={toggleGroup}
          openGroups={openGroups}
        />
      </aside>

      <div className="shell-workspace" inert={navigationOpen ? true : undefined}>
        <header className="site-header">
          <button
            type="button"
            className="shell-icon-button mobile-menu-button"
            ref={navigationButtonRef}
            aria-label={copy.openNavigation}
            aria-expanded={navigationOpen}
            aria-controls="mobile-navigation"
            onClick={() => setNavigationOpen(true)}
          >
            <Menu aria-hidden="true" />
          </button>

          <button
            type="button"
            className="shell-icon-button sidebar-collapse-button"
            aria-label={sidebarCollapsed ? copy.expandNavigation : copy.collapseNavigation}
            aria-pressed={sidebarCollapsed}
            onClick={toggleSidebar}
          >
            {locale === 'ar'
              ? sidebarCollapsed ? <PanelRightOpen aria-hidden="true" /> : <PanelRightClose aria-hidden="true" />
              : sidebarCollapsed ? <PanelLeftOpen aria-hidden="true" /> : <PanelLeftClose aria-hidden="true" />}
          </button>

          <div className="scope-context" aria-label={copy.currentFacility}>
            <Building2 aria-hidden="true" />
            <span>
              <strong>{facilityName}</strong>
            </span>
          </div>

          {globalSearchLabel && onGlobalSearch && (
            <form
              className="global-search"
              role="search"
              onSubmit={(event) => {
                event.preventDefault()
                const query = globalSearchQuery.trim()
                if (query) onGlobalSearch(query)
              }}
            >
              <Search aria-hidden="true" />
              <input
                type="search"
                value={globalSearchQuery}
                onChange={(event) => setGlobalSearchQuery(event.target.value)}
                placeholder={globalSearchLabel}
                aria-label={globalSearchLabel}
              />
              <kbd aria-hidden="true">⌘K</kbd>
            </form>
          )}

          <div className="header-actions">
            <button type="button" className="shell-action-button shell-language-button" aria-label={copy.switchLanguage} onClick={onLocaleChange}>
              <span>{copy.switchLanguage.slice(0, 2)}</span>
            </button>
            <button
              type="button"
              className="shell-icon-button notification-button"
              ref={notificationButtonRef}
              aria-label={notificationLabel}
              aria-expanded={notificationsOpen}
              aria-controls="notification-panel"
              onClick={onNotificationsToggle}
            >
              <Bell aria-hidden="true" />
              {unreadNotifications > 0 && <span className="notification-count" aria-hidden="true">{Math.min(unreadNotifications, 9)}</span>}
            </button>
          </div>

          <div className="header-user-menu">
            <button
              type="button"
              className="header-user-context"
              aria-expanded={userMenuOpen}
              aria-controls="user-menu"
              onClick={() => setUserMenuOpen((open) => !open)}
            >
              <span className="user-avatar" aria-hidden="true">{copy.platformUser.slice(0, 1)}</span>
              <span className="header-user-copy">
                <strong>{copy.platformUser}</strong>
              </span>
            </button>
            {userMenuOpen && (
              <div id="user-menu" className="header-user-dropdown" role="menu">
                {personalSecurity && (
                  <a
                    href={personalSecurity.path}
                    role="menuitem"
                    onClick={(event) => {
                      event.preventDefault()
                      setUserMenuOpen(false)
                      personalSecurity.onSelect()
                    }}
                  >
                    {copy.profile}
                  </a>
                )}
                <button type="button" role="menuitem" onClick={onLogout}>{copy.logout}</button>
              </div>
            )}
          </div>
        </header>

        <div className="content-stage">
          <main className="main-content">{children}</main>
        </div>

        <footer className="app-footer">
          <strong>{copy.rightsReserved}</strong>
          <span>{copy.organizationName}</span>
          <span>{copy.officeName}</span>
          <span>{copy.ownerName}</span>
        </footer>

      </div>

      {navigationOpen && (
        <div className="mobile-navigation-layer" onMouseDown={(event) => {
          if (event.target === event.currentTarget) closeNavigation()
        }}>
          <aside
            id="mobile-navigation"
            className="mobile-navigation"
            ref={navigationPanelRef}
            role="dialog"
            aria-modal="true"
            aria-label={copy.navigationTitle}
          >
            <SidebarContent
              copy={copy}
              headingId="mobile-sidebar-heading"
              navigationGroups={navigationGroups}
              onClose={closeNavigation}
              onNavigate={closeNavigation}
              onToggleGroup={toggleGroup}
              openGroups={openGroups}
              showCloseButton
            />
          </aside>
        </div>
      )}
    </div>

      {notificationsOpen && (
        <div className="notifications-dialog-layer" onMouseDown={(event) => {
          if (event.target === event.currentTarget) onNotificationsToggle()
        }}>
          <aside
            ref={notificationsPanelRef}
            id="notification-panel"
            className="notifications-dialog"
            role="dialog"
            aria-modal="true"
            aria-label={copy.notifications}
          >
            <div className="notifications-dialog-head">
              <strong>{copy.notifications}</strong>
              <button type="button" className="shell-icon-button" aria-label={copy.closeNotifications} onClick={onNotificationsToggle}>
                <X aria-hidden="true" />
              </button>
            </div>
            {notificationPanel}
          </aside>
        </div>
      )}
    </>
  )
}
