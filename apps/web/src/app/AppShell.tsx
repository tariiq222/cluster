import { useMemo, useState } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import {
  BarChart3,
  Bell,
  Building2,
  FileText,
  Home,
  Languages,
  ListTodo,
  LogOut,
  Moon,
  Search,
  Settings,
  ShieldCheck,
  Sun,
  User,
  UserRound,
  X,
  type LucideIcon,
} from 'lucide-react'
import { usePrincipal } from './principal-context'
import {
  ScreenHelpProvider,
} from './screen-help-provider'
import type { RegisteredScreenHelp } from './screen-help'
import { useLocale, useSetLocale } from './session-context'
import { useTheme } from '@/components/theme-provider'
import { CommandMenu } from '@/components/command-menu'
import { AppSidebar } from '@/components/app-sidebar'
import {
  ContextualHelp,
  ContextualHelpTrigger,
} from '@/components/contextual-help'
import { commandShortcut } from '@/lib/keyboard-shortcut'
import { directionForLocale, shellCopy, type Locale } from '../i18n'
import { Button } from '@/components/ui/button'
import { TooltipProvider } from '@/components/ui/tooltip'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
  useSidebar,
} from '@/components/ui/sidebar'

export interface NavEntry {
  path: string
  label: string
  icon: LucideIcon
}

export interface NavGroup {
  label: string
  entries: NavEntry[]
}

/* Personal destinations in the account menu; they are not part of the
 * capability-filtered nav groups and stay reachable from the header. */
const accountMenuCopy = {
  ar: { myAccount: 'حسابي', notifications: 'الإشعارات' },
  en: { myAccount: 'My Account', notifications: 'Notifications' },
} as const

const organizationReadCapabilities = [
  'organization.cluster.read',
  'organization.facility.read',
  'organization.unit.read',
  'organization.position.read',
  'organization.person.read',
  'organization.assignment.read',
  'organization.temporary-assignment.read',
] as const

/* Icon-mode tooltips open on the physical side facing the page content:
 * the right-side Arabic sidebar pops left, the left-side English sidebar
 * pops right. The generated SidebarMenuButton default is physical right. */
function tooltipSide(locale: Locale): 'left' | 'right' {
  return locale === 'ar' ? 'left' : 'right'
}

/* The generated SidebarMenuButton styles match `data-active` on attribute
 * presence, so React's `data-active="false"` would light inactive entries
 * up as active. Passing `data-active={active || undefined}` keeps the
 * attribute off inactive controls while `isActive` preserves the generated
 * active look. */
function SidebarNavLink({
  path,
  label,
  icon: Icon,
  active,
}: {
  path: string
  label: string
  icon: LucideIcon
  active: boolean
}) {
  const { isMobile, setOpenMobile } = useSidebar()
  const locale = useLocale()
  return (
    <SidebarMenuButton
      asChild
      isActive={active}
      data-active={active || undefined}
      tooltip={{ children: label, side: tooltipSide(locale) }}
      className="focus-visible:ring-sidebar-foreground!"
      onClick={isMobile ? () => setOpenMobile(false) : undefined}
    >
      <Link to={path} aria-current={active ? 'page' : undefined}>
        <Icon aria-hidden="true" />
        <span>{label}</span>
      </Link>
    </SidebarMenuButton>
  )
}

/* The generated mobile Sheet hides its own close button on the sidebar
 * surface, so the shell provides a visible one that closes the sheet and
 * stays below the md breakpoint (never on desktop or icon-collapse mode). */
function SidebarCloseButton({ label }: { label: string }) {
  const { setOpenMobile } = useSidebar()
  return (
    <Button
      variant="ghost"
      size="icon"
      aria-label={label}
      aria-controls="app-sidebar-navigation"
      onClick={() => setOpenMobile(false)}
      className="size-11 shrink-0 focus-visible:ring-foreground! md:hidden"
    >
      <X aria-hidden="true" />
    </Button>
  )
}

/* The generated SidebarTrigger never exposes the controlled sidebar state,
 * so the shell's own trigger links it to the navigation landmark: the
 * expanded state is programmatically determinable (WCAG 4.1.2) and follows
 * useSidebar automatically on both desktop and the mobile sheet. */
function ShellSidebarTrigger({ label }: { label: string }) {
  const { open, openMobile, isMobile } = useSidebar()
  return (
    <SidebarTrigger
      id="app-sidebar-trigger"
      aria-label={label}
      title={label}
      aria-controls="app-sidebar-navigation"
      aria-expanded={isMobile ? openMobile : open}
      className="-ms-2 max-md:size-11 focus-visible:ring-foreground!"
    />
  )
}

export function AppShell({ onLogout }: { onLogout: () => void }) {
  const locale = useLocale()
  const setLocale = useSetLocale()
  const copy = shellCopy[locale]
  const principal = usePrincipal()
  const location = useLocation()
  const { theme, resolved, setTheme } = useTheme()
  const [commandOpen, setCommandOpen] = useState(false)
  const [helpOpen, setHelpOpen] = useState(false)
  const [screenHelp, setScreenHelp] = useState<RegisteredScreenHelp | null>(null)
  const searchShortcut = commandShortcut()

  const capabilities = useMemo(
    () => principal.capabilities ?? [],
    [principal.capabilities],
  )
  const features = useMemo(
    () => principal.features ?? { tasks: false },
    [principal.features],
  )

  const navGroups: NavGroup[] = useMemo(() => {
    const can = (cap: string) => capabilities.includes(cap)
    const work: NavEntry[] = [
      ...(features.tasks && can('tasks.list')
        ? [{ path: '/tasks', label: copy.tasks, icon: ListTodo }]
        : []),
      ...(can('documents.list')
        ? [{ path: '/documents', label: copy.documents, icon: FileText }]
        : []),
    ]
    const organization: NavEntry[] = [
      ...(organizationReadCapabilities.some(can)
        ? [{ path: '/organization', label: copy.organization, icon: Building2 }]
        : []),
      ...(can('identity.account.read') || can('authorization.role.read')
        ? [
            {
              path: '/access',
              label: copy.accountsPermissions,
              icon: ShieldCheck,
            },
          ]
        : []),
    ]
    const system: NavEntry[] = [
      ...(can('reporting.read') || can('audit.event.read')
        ? [{ path: '/reports', label: copy.reportsMonitoring, icon: BarChart3 }]
        : []),
      ...(can('platform_settings.read') ||
      can('platform_operations.health.read')
        ? [
            {
              path: '/platform',
              label: copy.platformManagement,
              icon: Settings,
            },
          ]
        : []),
    ]
    const groups: NavGroup[] = [
      {
        label: copy.navOverview,
        entries: [{ path: '/', label: copy.home, icon: Home }],
      },
    ]
    if (work.length) groups.push({ label: copy.navWork, entries: work })
    if (organization.length)
      groups.push({ label: copy.navOrganization, entries: organization })
    if (system.length) groups.push({ label: copy.navSystem, entries: system })
    return groups
  }, [capabilities, features, copy])

  const isActive = (path: string) =>
    location.pathname === path || location.pathname.startsWith(`${path}/`)

  const commandNavigationEntries = navGroups.flatMap((group) => group.entries)

  /* Current destination shown in the header: the capability-filtered nav
   * entry whose branch contains the route (parents cover nested detail and
   * import pages), falling back to the personal destinations outside the
   * sidebar navigation. No new router contract — pure derivation. */
  const headerContext = useMemo(() => {
    const pathname = location.pathname
    const active = (path: string) =>
      pathname === path || pathname.startsWith(`${path}/`)
    for (const group of navGroups) {
      for (const entry of group.entries) {
        if (active(entry.path))
          return { groupLabel: group.label, entryLabel: entry.label }
      }
    }
    if (pathname === '/me')
      return { groupLabel: null, entryLabel: accountMenuCopy[locale].myAccount }
    if (pathname === '/notifications')
      return { groupLabel: null, entryLabel: copy.notifications }
    if (pathname === '/search')
      return { groupLabel: null, entryLabel: copy.search }
    return null
  }, [navGroups, location.pathname, locale, copy])

  const scopeLabel = principal.effectiveScope?.label

  return (
    <TooltipProvider delayDuration={0}>
      <SidebarProvider>
        <a
          href="#main-content"
          className="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:text-foreground focus:shadow-sm focus:ring-2 focus:ring-foreground!"
        >
          {copy.skipToContent}
        </a>
        <AppSidebar
          side={locale === 'ar' ? 'right' : 'left'}
          collapsible="icon"
          dir={directionForLocale(locale)}
          mobileTitle={copy.mobileSidebarTitle}
          mobileDescription={copy.mobileSidebarDescription}
        >
          <SidebarHeader className="h-14 shrink-0 justify-center border-b border-sidebar-border px-2 py-1">
            <div className="flex items-center gap-1">
              <SidebarMenu className="min-w-0 flex-1">
                <SidebarMenuItem>
                  <SidebarMenuButton
                    size="lg"
                    asChild
                    data-active={undefined}
                    tooltip={{
                      children: copy.brand,
                      side: tooltipSide(locale),
                    }}
                    className="focus-visible:ring-sidebar-foreground!"
                  >
                    <Link to="/">
                      <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-sidebar-primary font-bold text-sidebar-primary-foreground">
                        {copy.brand.charAt(0)}
                      </span>
                      <span className="grid min-w-0 flex-1 gap-0.5 text-start group-data-[collapsible=icon]:hidden">
                        <span className="truncate text-sm font-semibold">
                          {copy.brand}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                          {copy.brandSubtitle}
                        </span>
                      </span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              </SidebarMenu>
              <SidebarCloseButton label={copy.closeMenu} />
            </div>
          </SidebarHeader>
          <SidebarContent
            id="app-sidebar-navigation"
            role="navigation"
            aria-label={copy.menu}
          >
            {navGroups.map((group) => (
              <SidebarGroup key={group.label}>
                <SidebarGroupLabel asChild>
                  <h2>{group.label}</h2>
                </SidebarGroupLabel>
                <SidebarGroupContent>
                  <SidebarMenu>
                    {group.entries.map((entry) => (
                      <SidebarMenuItem key={entry.path}>
                        <SidebarNavLink
                          path={entry.path}
                          label={entry.label}
                          icon={entry.icon}
                          active={isActive(entry.path)}
                        />
                      </SidebarMenuItem>
                    ))}
                  </SidebarMenu>
                </SidebarGroupContent>
              </SidebarGroup>
            ))}
          </SidebarContent>
          <SidebarFooter className="border-t border-sidebar-border">
            <SidebarMenu>
              <SidebarMenuItem>
                <ContextualHelpTrigger
                  locale={locale}
                  onOpen={() => setHelpOpen(true)}
                />
              </SidebarMenuItem>
              <SidebarMenuItem>
                <SidebarMenuButton
                  size="lg"
                  asChild
                  isActive={isActive('/me')}
                  data-active={isActive('/me') || undefined}
                  tooltip={{
                    children: accountMenuCopy[locale].myAccount,
                    side: tooltipSide(locale),
                  }}
                  className="focus-visible:ring-sidebar-foreground!"
                >
                  <Link
                    to="/me"
                    aria-current={isActive('/me') ? 'page' : undefined}
                  >
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-sidebar-accent text-sidebar-accent-foreground">
                      <UserRound aria-hidden="true" />
                    </span>
                    <span className="grid min-w-0 flex-1 gap-0.5 text-start group-data-[collapsible=icon]:hidden">
                      <span className="truncate text-sm font-medium">
                        {accountMenuCopy[locale].myAccount}
                      </span>
                      {scopeLabel && (
                        <span className="truncate text-xs text-muted-foreground">
                          {scopeLabel}
                        </span>
                      )}
                    </span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarFooter>
          <SidebarRail
            aria-label={copy.toggleSidebar}
            title={copy.toggleSidebar}
          />
        </AppSidebar>
        <SidebarInset className="min-w-0">
          <header
            aria-label={copy.pageHeader}
            className="sticky top-0 z-10 flex h-14 min-w-0 shrink-0 items-center gap-1.5 border-b border-border bg-background px-4 sm:gap-2"
          >
            <ShellSidebarTrigger label={copy.toggleSidebar} />
            {headerContext && (
              <div className="flex min-w-0 flex-1 flex-col justify-center overflow-hidden">
                <div className="flex min-w-0 items-baseline gap-1.5 overflow-hidden">
                  {headerContext.groupLabel && (
                    <>
                      <span className="hidden truncate text-sm text-muted-foreground md:inline">
                        {headerContext.groupLabel}
                      </span>
                      <span
                        aria-hidden="true"
                        className="hidden text-xs text-muted-foreground/60 md:inline"
                      >
                        /
                      </span>
                    </>
                  )}
                  <span className="truncate text-sm font-medium">
                    {headerContext.entryLabel}
                  </span>
                </div>
                {scopeLabel && (
                  <span
                    className="truncate text-xs text-muted-foreground lg:hidden"
                    title={scopeLabel}
                  >
                    {copy.scope}: {scopeLabel}
                  </span>
                )}
              </div>
            )}
            <div className="ms-auto flex min-w-0 shrink-0 items-center gap-1 sm:gap-1.5">
              <Button
                variant="outline"
                onClick={() => setCommandOpen(true)}
                className="hidden w-44 justify-start text-muted-foreground focus-visible:ring-foreground! lg:inline-flex"
              >
                <Search aria-hidden="true" />
                <span className="truncate">{copy.search}</span>
                <kbd
                  dir="ltr"
                  aria-hidden="true"
                  className="ms-auto rounded-md border bg-muted px-1.5 font-mono text-xs font-medium text-foreground"
                >
                  {searchShortcut}
                </kbd>
              </Button>
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setCommandOpen(true)}
                aria-label={copy.search}
                className="max-md:size-11 focus-visible:ring-foreground! lg:hidden"
              >
                <Search aria-hidden="true" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                asChild
                aria-label={copy.notifications}
                aria-current={
                  location.pathname === '/notifications' ? 'page' : undefined
                }
                className="max-md:size-11 focus-visible:ring-foreground!"
              >
                <Link to="/notifications">
                  <Bell aria-hidden="true" />
                </Link>
              </Button>
              <Button
                variant="ghost"
                size="sm"
                aria-label={locale === 'ar' ? 'English' : 'العربية'}
                onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}
                className="hidden text-sm focus-visible:ring-foreground! lg:inline-flex"
              >
                <Languages aria-hidden="true" />
                <span>{locale === 'ar' ? 'English' : 'العربية'}</span>
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={copy.theme}
                    className="hidden focus-visible:ring-foreground! lg:inline-flex"
                  >
                    {resolved === 'dark' ? (
                      <Moon aria-hidden="true" />
                    ) : (
                      <Sun aria-hidden="true" />
                    )}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuLabel>{copy.theme}</DropdownMenuLabel>
                  <DropdownMenuRadioGroup
                    value={theme}
                    onValueChange={(value) =>
                      setTheme(value as 'light' | 'dark' | 'system')
                    }
                  >
                    <DropdownMenuRadioItem value="light" className="min-h-11">
                      {copy.light}
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="dark" className="min-h-11">
                      {copy.dark}
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="system" className="min-h-11">
                      {copy.system}
                    </DropdownMenuRadioItem>
                  </DropdownMenuRadioGroup>
                </DropdownMenuContent>
              </DropdownMenu>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label={copy.account}
                    className="max-md:size-11 focus-visible:ring-foreground!"
                  >
                    <User aria-hidden="true" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  {scopeLabel && (
                    <>
                      <DropdownMenuLabel className="min-w-0">
                        <span className="block">{copy.scope}</span>
                        <span
                          className="block truncate text-sm text-foreground"
                          title={scopeLabel}
                        >
                          {scopeLabel}
                        </span>
                      </DropdownMenuLabel>
                      <DropdownMenuSeparator />
                    </>
                  )}
                  <DropdownMenuItem asChild className="min-h-11">
                    <Link to="/me">
                      <UserRound aria-hidden="true" />
                      {accountMenuCopy[locale].myAccount}
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuItem asChild className="min-h-11">
                    <Link to="/notifications">
                      <Bell aria-hidden="true" />
                      {accountMenuCopy[locale].notifications}
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuSeparator className="lg:hidden" />
                  <DropdownMenuItem
                    className="min-h-11 lg:hidden"
                    onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}
                  >
                    <Languages aria-hidden="true" />
                    {locale === 'ar' ? 'English' : 'العربية'}
                  </DropdownMenuItem>
                  <DropdownMenuLabel className="lg:hidden">
                    {copy.theme}
                  </DropdownMenuLabel>
                  <DropdownMenuRadioGroup
                    value={theme}
                    onValueChange={(value) =>
                      setTheme(value as 'light' | 'dark' | 'system')
                    }
                    className="lg:hidden"
                  >
                    <DropdownMenuRadioItem value="light" className="min-h-11">
                      {copy.light}
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="dark" className="min-h-11">
                      {copy.dark}
                    </DropdownMenuRadioItem>
                    <DropdownMenuRadioItem value="system" className="min-h-11">
                      {copy.system}
                    </DropdownMenuRadioItem>
                  </DropdownMenuRadioGroup>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem className="min-h-11" onClick={onLogout}>
                    <LogOut aria-hidden="true" />
                    {copy.logout}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </header>
          <div
            id="main-content"
            tabIndex={-1}
            className="flex min-w-0 flex-1 scroll-mt-14 flex-col gap-4 p-4 outline-none sm:p-6"
          >
            <ScreenHelpProvider onChange={setScreenHelp}>
              <Outlet />
            </ScreenHelpProvider>
          </div>
        </SidebarInset>
        <CommandMenu
          locale={locale}
          navigationEntries={commandNavigationEntries}
          open={commandOpen}
          onOpenChange={setCommandOpen}
        />
        <ContextualHelp
          locale={locale}
          pathname={location.pathname}
          scopeLabel={scopeLabel}
          open={helpOpen}
          onOpenChange={setHelpOpen}
          screenHelp={
            screenHelp?.pathname === location.pathname ? screenHelp.help : null
          }
        />
      </SidebarProvider>
    </TooltipProvider>
  )
}
