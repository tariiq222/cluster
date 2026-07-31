import { useState } from 'react'
import { usePrincipal } from '../../app/principal-context'
import { useLocale } from '../../app/session-context'
import { EmptyState, Page, PageHeader, Tabs } from '../../ui'
import { ReportsScreen } from './ReportsScreen'
import { DashboardsScreen } from './DashboardsScreen'
import { AuditScreen } from '../audit/AuditScreen'

const copy = {
  ar: {
    title: 'التقارير والمراقبة',
    description: 'التقارير ولوحات المؤشرات وسجل التدقيق ضمن نطاقك.',
    reportsTab: 'التقارير',
    dashboardsTab: 'لوحات المؤشرات',
    auditTab: 'سجل التدقيق',
    tabsLabel: 'مساحة التقارير والمراقبة',
    denied: 'غير مصرح لك بالوصول إلى هذه الصفحة.',
  },
  en: {
    title: 'Reports & monitoring',
    description: 'Reports, dashboards, and the audit ledger within your scope.',
    reportsTab: 'Reports',
    dashboardsTab: 'Dashboards',
    auditTab: 'Audit ledger',
    tabsLabel: 'Reports and monitoring workspace',
    denied: 'You are not authorized to view this page.',
  },
} as const

type WorkspaceTab = 'reports' | 'dashboards' | 'audit'

export function ReportsMonitoringScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const t = copy[locale]

  const canReports = principal.capabilities?.includes('reporting.read') ?? false
  const canDashboards = principal.capabilities?.includes('reporting.dashboard') ?? false
  const canAudit = principal.capabilities?.includes('audit.event.read') ?? false

  const availableTabs: WorkspaceTab[] = [
    ...(canReports ? (['reports'] as const) : []),
    ...(canDashboards ? (['dashboards'] as const) : []),
    ...(canAudit ? (['audit'] as const) : []),
  ]

  const [activeTab, setActiveTab] = useState<WorkspaceTab | null>(null)
  const currentTab = activeTab && availableTabs.includes(activeTab) ? activeTab : (availableTabs[0] ?? null)

  if (availableTabs.length === 0) {
    return (
      <Page aria-labelledby="reports-monitoring-title">
        <PageHeader id="reports-monitoring-title" title={t.title} description={t.description} />
        <EmptyState title={t.denied} />
      </Page>
    )
  }

  const tabDefs: Array<{ key: WorkspaceTab; label: string }> = [
    ...(canReports ? [{ key: 'reports' as const, label: t.reportsTab }] : []),
    ...(canDashboards ? [{ key: 'dashboards' as const, label: t.dashboardsTab }] : []),
    ...(canAudit ? [{ key: 'audit' as const, label: t.auditTab }] : []),
  ]

  return (
    <Page aria-labelledby="reports-monitoring-title">
      <PageHeader id="reports-monitoring-title" title={t.title} description={t.description} />
      <Tabs
        label={t.tabsLabel}
        tabs={tabDefs.map((tab) => ({
          key: tab.key,
          label: tab.label,
          active: tab.key === currentTab,
          onClick: () => setActiveTab(tab.key),
        }))}
      />
      {currentTab === 'reports' ? <ReportsScreen /> : null}
      {currentTab === 'dashboards' ? <DashboardsScreen /> : null}
      {currentTab === 'audit' ? <AuditScreen /> : null}
    </Page>
  )
}
