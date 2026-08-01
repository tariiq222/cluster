import { useMemo, useState } from 'react'
import { Link, Outlet, useLocation } from 'react-router-dom'
import {
  BarChart3,
  Building2,
  ClipboardList,
  FileText,
  Home,
  Inbox,
  Languages,
  ListTodo,
  LogOut,
  Moon,
  Search,
  Settings,
  ShieldCheck,
  Sun,
  User,
  type LucideIcon,
} from 'lucide-react'
import { usePrincipal } from './principal-context'
import { useLocale, useSetLocale } from './session-context'
import { useTheme } from '@/components/theme-provider'
import { CommandMenu } from '@/components/command-menu'
import { shellCopy } from '../i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
} from '@/components/ui/sidebar'

export interface NavEntry {
  path: string
  label: string
  icon: LucideIcon
}

export function AppShell({ onLogout }: { onLogout: () => void }) {
  const locale = useLocale()
  const setLocale = useSetLocale()
  const copy = shellCopy[locale]
  const principal = usePrincipal()
  const location = useLocation()
  const { resolved, setTheme } = useTheme()
  const [commandOpen, setCommandOpen] = useState(false)

  const capabilities = useMemo(() => principal.capabilities ?? [], [principal.capabilities])
  const features = useMemo(() => principal.features ?? { work_management: false, tasks: false }, [principal.features])

  const navEntries: NavEntry[] = useMemo(() => {
    const can = (cap: string) => capabilities.includes(cap)
    const entries: NavEntry[] = [
      { path: '/', label: copy.home, icon: Home },
      ...(features.tasks && can('tasks.list') ? [{ path: '/tasks', label: copy.tasks, icon: ListTodo }] : []),
      ...(can('documents.list') ? [{ path: '/documents', label: copy.documents, icon: FileText }] : []),
      ...(features.work_management && can('work_management.record.read')
        ? [{ path: '/work-records', label: copy.workRecords, icon: ClipboardList }]
        : []),
      ...(features.work_management && can('workflow.step.read')
        ? [{ path: '/inbox', label: copy.inbox, icon: Inbox }]
        : []),
      ...(can('organization.cluster.read') || can('organization.facility.read')
        ? [{ path: '/organization', label: copy.organization, icon: Building2 }]
        : []),
      ...(can('identity.account.read') || can('authorization.role.read')
        ? [{ path: '/access', label: copy.accountsPermissions, icon: ShieldCheck }]
        : []),
      ...(can('reporting.read') || can('audit.event.read')
        ? [{ path: '/reports', label: copy.reportsMonitoring, icon: BarChart3 }]
        : []),
      ...(can('platform_settings.read') || can('platform_operations.health.read')
        ? [{ path: '/platform', label: copy.platformManagement, icon: Settings }]
        : []),
    ]
    return entries
  }, [capabilities, features, copy])

  const isActive = (path: string) => location.pathname === path || location.pathname.startsWith(`${path}/`)

  return (
    <SidebarProvider>
      <Sidebar side={locale === 'ar' ? 'right' : 'left'}>
        <SidebarHeader>
          <SidebarMenu>
            <SidebarMenuItem>
              <SidebarMenuButton size="lg" asChild>
                <Link to="/">
                  <span className="text-sidebar-primary-foreground flex size-8 items-center justify-center rounded-md bg-primary font-bold">
                    {copy.brand.charAt(0)}
                  </span>
                  <span className="font-semibold">{copy.brand}</span>
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarHeader>
        <SidebarContent role="navigation" aria-label={copy.menu}>
          <SidebarGroup>
            <SidebarGroupContent>
              <SidebarMenu>
                {navEntries.map((entry) => (
                  <SidebarMenuItem key={entry.path}>
                    <SidebarMenuButton asChild isActive={isActive(entry.path)}>
                      <Link to={entry.path}>
                        <entry.icon aria-hidden="true" />
                        <span>{entry.label}</span>
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                ))}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        </SidebarContent>
        <SidebarRail />
      </Sidebar>
      <SidebarInset>
        <header className="flex h-14 items-center gap-2 border-b px-4">
          <SidebarTrigger className="-ms-2" />
          <div className="ms-auto flex items-center gap-2">
            {principal.effectiveScope && <Badge variant="outline" className="hidden md:inline-flex">{principal.effectiveScope.label}</Badge>}
            <Button variant="ghost" size="sm" onClick={() => setCommandOpen(true)} aria-label={`${copy.search} (⌘K)`}>
              <Search aria-hidden="true" />
              <span className="hidden lg:inline">⌘K</span>
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}>
              <Languages aria-hidden="true" />
              {locale === 'ar' ? 'English' : 'العربية'}
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={copy.theme}>
                  {resolved === 'dark' ? <Moon aria-hidden="true" /> : <Sun aria-hidden="true" />}
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setTheme('light')}>{copy.light}</DropdownMenuItem>
                <DropdownMenuItem onClick={() => setTheme('dark')}>{copy.dark}</DropdownMenuItem>
                <DropdownMenuItem onClick={() => setTheme('system')}>{copy.system}</DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label={copy.account}>
                  <User aria-hidden="true" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={onLogout}>
                  <LogOut aria-hidden="true" />
                  {copy.logout}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>
        <main className="flex flex-1 flex-col gap-4 p-4">
          <Outlet />
        </main>
      </SidebarInset>
      <CommandMenu locale={locale} open={commandOpen} onOpenChange={setCommandOpen} />
    </SidebarProvider>
  )
}
