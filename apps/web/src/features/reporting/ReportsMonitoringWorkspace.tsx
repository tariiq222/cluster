import type { Locale } from '../../app/copy'
import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import type { Session } from '../../api'
import { pathFromRoute, type AppRoute } from '../../shell/routes'
import { AuditWorkspace } from '../audit/AuditWorkspace'
import { ReportsScreen } from '../r1/R1Screens'
import { DashboardsScreen } from './DashboardsScreen'

export type ReportsMonitoringRoute = Extract<
  AppRoute,
  { name: 'reports' | 'dashboards' | 'audit' }
>

export type ReportsMonitoringWorkspaceProps = {
  locale: Locale
  route: ReportsMonitoringRoute
  session: Session
  capabilities: readonly string[]
  scopeId?: string | null
  revision: number
  navigate: (path: string) => void
}

const sections = [
  {
    key: 'reports',
    route: { name: 'reports' } as const,
    capability: 'reporting.list',
    ar: 'التقارير',
    en: 'Reports',
  },
  {
    key: 'dashboards',
    route: { name: 'dashboards' } as const,
    capability: 'reporting.dashboard',
    ar: 'لوحات المؤشرات',
    en: 'Dashboards',
  },
  {
    key: 'audit',
    route: { name: 'audit' } as const,
    capability: 'audit.event.read',
    ar: 'سجل التدقيق',
    en: 'Audit ledger',
  },
] as const

const navigationLabels = {
  ar: 'أقسام التقارير والمتابعة',
  en: 'Reports and monitoring sections',
} as const

export function ReportsMonitoringWorkspace({
  locale,
  route,
  session,
  capabilities,
  scopeId,
  revision,
  navigate,
}: ReportsMonitoringWorkspaceProps) {
  const tabs = sections
    .filter((section) => capabilities.includes(section.capability))
    .map((section) => ({
      key: section.key,
      label: section[locale],
      path: pathFromRoute(section.route),
      active: route.name === section.route.name,
    }))

  return (
    <div className="reports-monitoring-workspace">
      <WorkspaceTabs
        label={navigationLabels[locale]}
        tabs={tabs}
        onNavigate={navigate}
      />
      {route.name === 'reports' ? (
        <ReportsScreen capabilities={capabilities} />
      ) : route.name === 'dashboards' ? (
        <DashboardsScreen
          locale={locale}
          dashboardId={route.dashboardId}
          scopeId={scopeId}
          revision={revision}
        />
      ) : (
        <AuditWorkspace
          locale={locale}
          token={session.access_token}
          capabilities={capabilities}
        />
      )}
    </div>
  )
}
