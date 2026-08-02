import { usePrincipal } from '../../app/principal-context'
import { useLocale } from '../../app/session-context'
import { useWorkspaceTab } from '../../app/use-workspace-tab'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { DeniedState } from '@/components/states'
import { reportsCopy } from './reports-copy'
import { useTrackedExports } from './export-tracker'
import { ReportsScreen } from './ReportsScreen'
import { DashboardsScreen } from './DashboardsScreen'
import { AuditScreen } from '../audit/AuditScreen'
import { ExportsTab } from './ExportsTab'

type WorkspaceTab = 'reports' | 'dashboards' | 'audit' | 'exports'

/*
 * The Reports & monitoring workspace. Tabs are filtered by principal
 * capability BEFORE render — a destination the principal cannot access is
 * absent, never admitted-then-denied. The Exports tab appears when a
 * report/audit export or download capability is present, or as soon as an
 * export is tracked during this session. The page renders exactly one H1;
 * every child tab renders H2 or lower.
 */
export function ReportsMonitoringScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const t = reportsCopy[locale]
  const capabilities = principal.capabilities ?? []
  const trackedExports = useTrackedExports()

  const canReports = capabilities.includes('reporting.read')
  const canDashboards = capabilities.includes('reporting.dashboard')
  const canAudit = capabilities.includes('audit.event.read')
  const canCreateExports = [
    'reporting.export',
    'reporting.download',
    'audit.event.export',
  ].some((capability) => capabilities.includes(capability))
  const canExports = canCreateExports || trackedExports.length > 0

  const tabs: Array<{ key: WorkspaceTab; label: string; visible: boolean }> = [
    { key: 'reports', label: t.tabReports, visible: canReports },
    { key: 'dashboards', label: t.tabDashboards, visible: canDashboards },
    { key: 'audit', label: t.tabAudit, visible: canAudit },
    { key: 'exports', label: t.tabExports, visible: canExports },
  ]
  const visible = tabs.filter((entry) => entry.visible)

  const [tab, setTab] = useWorkspaceTab<WorkspaceTab>('tab', 'reports')
  const activeTab = visible.some((entry) => entry.key === tab)
    ? tab
    : (visible[0]?.key ?? 'reports')

  const items: WorkspaceTabItem[] = []
  if (canReports) {
    items.push({ value: 'reports', label: t.tabReports, content: <ReportsScreen /> })
  }
  if (canDashboards) {
    items.push({ value: 'dashboards', label: t.tabDashboards, content: <DashboardsScreen /> })
  }
  if (canAudit) {
    items.push({ value: 'audit', label: t.tabAudit, content: <AuditScreen /> })
  }
  if (canExports) {
    items.push({ value: 'exports', label: t.tabExports, content: <ExportsTab /> })
  }

  return (
    <PageLayout>
      <PageHeader title={t.title} description={t.description} />
      {visible.length === 0 ? (
        <DeniedState locale={locale} />
      ) : (
        <WorkspaceTabs
          label={t.tabsLabel}
          value={activeTab}
          onValueChange={(value) => setTab(value as WorkspaceTab)}
          items={items}
        />
      )}
    </PageLayout>
  )
}
