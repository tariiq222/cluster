import { type MouseEvent, type ReactNode, type RefObject, useEffect, useRef, useState } from 'react'
import {
  Bell,
  Building2,
  FilePlus2,
  Inbox,
  Languages,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  PanelRightClose,
  PanelRightOpen,
  ShieldCheck,
  X,
} from 'lucide-react'

import './AppShell.css'

type Locale = 'ar' | 'en'
type ActiveNavigation = 'requests' | 'create' | 'organization'

export type AppShellCopy = {
  platform: string
  switchLanguage: string
  currentFacility: string
  myRequests: string
  newRequest: string
  organization: string
  notifications: string
  logout: string
  rightsReserved: string
  organizationName: string
  officeName: string
  ownerName: string
  openNavigation: string
  closeNavigation: string
  navigationTitle: string
  services: string
  platformUser: string
  internalSystem: string
  collapseNavigation: string
  expandNavigation: string
}

type AppShellProps = {
  locale: Locale
  copy: AppShellCopy
  facilityName: string
  activeNavigation: ActiveNavigation
  unreadNotifications: number
  notificationButtonRef: RefObject<HTMLButtonElement | null>
  notificationsOpen: boolean
  onLocaleChange: () => void
  onNotificationsToggle: () => void
  onLogout: () => void
  onNavigateRequests: () => void
  onNavigateCreate: () => void
  onNavigateOrganization: () => void
  children: ReactNode
}

type SidebarContentProps = Pick<AppShellProps,
  'activeNavigation' | 'copy' | 'facilityName' | 'onNavigateCreate' | 'onNavigateOrganization' | 'onNavigateRequests'
> & {
  headingId: string
  onNavigate: () => void
  showCloseButton?: boolean
  onClose?: () => void
}

function SidebarContent({
  activeNavigation,
  copy,
  facilityName,
  headingId,
  onClose,
  onNavigate,
  onNavigateCreate,
  onNavigateOrganization,
  onNavigateRequests,
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
        <p className="navigation-section-label">{copy.services}</p>
        <a
          href="/"
          aria-label={copy.myRequests}
          aria-current={activeNavigation === 'requests' ? 'page' : undefined}
          onClick={(event) => follow(event, onNavigateRequests)}
        >
          <Inbox aria-hidden="true" />
          <span>{copy.myRequests}</span>
        </a>
        <a
          href="/work-records/new"
          aria-label={copy.newRequest}
          aria-current={activeNavigation === 'create' ? 'page' : undefined}
          onClick={(event) => follow(event, onNavigateCreate)}
        >
          <FilePlus2 aria-hidden="true" />
          <span>{copy.newRequest}</span>
        </a>
        <a
          href="/admin/organization"
          aria-label={copy.organization}
          aria-current={activeNavigation === 'organization' ? 'page' : undefined}
          onClick={(event) => follow(event, onNavigateOrganization)}
        >
          <Building2 aria-hidden="true" />
          <span>{copy.organization}</span>
        </a>
      </nav>

      <div className="sidebar-user-context">
        <span className="user-avatar" aria-hidden="true">{copy.platformUser.slice(0, 1)}</span>
        <span>
          <strong>{copy.platformUser}</strong>
          <small>{facilityName}</small>
        </span>
      </div>
    </div>
  )
}

export function AppShell({
  activeNavigation,
  children,
  copy,
  facilityName,
  locale,
  notificationButtonRef,
  notificationsOpen,
  onLocaleChange,
  onLogout,
  onNavigateCreate,
  onNavigateOrganization,
  onNavigateRequests,
  onNotificationsToggle,
  unreadNotifications,
}: AppShellProps) {
  const [navigationOpen, setNavigationOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try {
      return window.localStorage.getItem('cluster.sidebar-collapsed') === 'true'
    } catch {
      return false
    }
  })
  const navigationButtonRef = useRef<HTMLButtonElement>(null)
  const navigationPanelRef = useRef<HTMLElement>(null)
  const notificationLabel = unreadNotifications > 0
    ? `${copy.notifications}: ${new Intl.NumberFormat(locale === 'ar' ? 'ar-SA-u-nu-arab' : 'en-US').format(unreadNotifications)}`
    : copy.notifications

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
    const desktopQuery = window.matchMedia('(min-width: 761px)')
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

  return (
    <div
      className="app-shell"
      data-locale={locale}
      data-sidebar-collapsed={sidebarCollapsed}
      inert={notificationsOpen ? true : undefined}
    >
      <aside className="desktop-sidebar" aria-labelledby="desktop-sidebar-heading">
        <SidebarContent
          activeNavigation={activeNavigation}
          copy={copy}
          facilityName={facilityName}
          headingId="desktop-sidebar-heading"
          onNavigate={() => undefined}
          onNavigateCreate={onNavigateCreate}
          onNavigateOrganization={onNavigateOrganization}
          onNavigateRequests={onNavigateRequests}
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
              <small>{copy.currentFacility}</small>
              <strong>{facilityName}</strong>
            </span>
          </div>

          <div className="header-actions">
            <button type="button" className="shell-action-button shell-language-button" aria-label={copy.switchLanguage} onClick={onLocaleChange}>
              <Languages aria-hidden="true" />
              <span>{copy.switchLanguage}</span>
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
            <button type="button" className="shell-icon-button" aria-label={copy.logout} onClick={onLogout}>
              <LogOut aria-hidden="true" />
            </button>
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
              activeNavigation={activeNavigation}
              copy={copy}
              facilityName={facilityName}
              headingId="mobile-sidebar-heading"
              onClose={closeNavigation}
              onNavigate={closeNavigation}
              onNavigateCreate={onNavigateCreate}
              onNavigateOrganization={onNavigateOrganization}
              onNavigateRequests={onNavigateRequests}
              showCloseButton
            />
          </aside>
        </div>
      )}
    </div>
  )
}
