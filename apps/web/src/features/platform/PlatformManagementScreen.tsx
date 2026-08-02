import { usePrincipal } from '../../app/principal-context'
import { useLocale } from '../../app/session-context'
import { useWorkspaceTab } from '../../app/use-workspace-tab'
import type { Locale } from '../../i18n'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { DeniedState } from '@/components/states'
import { platformCopy, t } from './platform-copy'
import { OverviewSection } from './sections/OverviewSection'
import { SecuritySettingsSection } from './sections/SecuritySettingsSection'
import { CalendarsSection } from './sections/CalendarsSection'
import { HealthSection } from './sections/HealthSection'
import { BackupsSection } from './sections/BackupsSection'
import { RestoreSection } from './sections/RestoreSection'
import { MaintenanceSection } from './sections/MaintenanceSection'
import { LogsSection } from './sections/LogsSection'
import { AlertsSection } from './sections/AlertsSection'

type PlatformGroupKey = 'overview' | 'settings' | 'monitoring' | 'continuity' | 'maintenance'
type LegacyPlatformSectionKey =
  | 'security'
  | 'calendars'
  | 'health'
  | 'backups'
  | 'restore'
  | 'logs'
  | 'alerts'
type PlatformTabValue = PlatformGroupKey | LegacyPlatformSectionKey

type PlatformChildKey =
  | 'overview'
  | 'security'
  | 'calendars'
  | 'alerts'
  | 'health'
  | 'logs'
  | 'backups'
  | 'restore'
  | 'maintenance'

interface ChildDefinition {
  key: PlatformChildKey
  capabilities: readonly string[]
}

interface GroupDefinition {
  key: PlatformGroupKey
  children: readonly ChildDefinition[]
}

const GROUPS: readonly GroupDefinition[] = [
  {
    key: 'overview',
    children: [{ key: 'overview', capabilities: ['platform_operations.health.read'] }],
  },
  {
    key: 'settings',
    children: [
      { key: 'security', capabilities: ['platform_settings.read', 'platform_settings.manage'] },
      { key: 'calendars', capabilities: ['platform_settings.calendar.read', 'platform_settings.calendar.manage'] },
      { key: 'alerts', capabilities: ['platform_operations.alerts.manage'] },
    ],
  },
  {
    key: 'monitoring',
    children: [
      { key: 'health', capabilities: ['platform_operations.health.read'] },
      { key: 'logs', capabilities: ['platform_operations.logs.read'] },
    ],
  },
  {
    key: 'continuity',
    children: [
      { key: 'backups', capabilities: ['platform_operations.backup.read', 'platform_operations.backup.run'] },
      { key: 'restore', capabilities: ['platform_operations.restore.request', 'platform_operations.restore.confirm'] },
    ],
  },
  {
    key: 'maintenance',
    children: [{ key: 'maintenance', capabilities: ['platform_operations.maintenance.manage'] }],
  },
]

const LEGACY_GROUPS: Readonly<Record<PlatformTabValue, PlatformGroupKey>> = {
  overview: 'overview',
  settings: 'settings',
  security: 'settings',
  calendars: 'settings',
  alerts: 'settings',
  monitoring: 'monitoring',
  health: 'monitoring',
  logs: 'monitoring',
  continuity: 'continuity',
  backups: 'continuity',
  restore: 'continuity',
  maintenance: 'maintenance',
}

function groupLabel(key: PlatformGroupKey, locale: Locale): string {
  return t(platformCopy.tabs[key], locale)
}

function hasAnyCapability(capabilities: readonly string[] | null, required: readonly string[]): boolean {
  if (capabilities === null) return false
  return required.some((capability) => capabilities.includes(capability))
}

function renderChild(key: PlatformChildKey) {
  switch (key) {
    case 'overview': return <OverviewSection />
    case 'security': return <SecuritySettingsSection />
    case 'calendars': return <CalendarsSection />
    case 'alerts': return <AlertsSection />
    case 'health': return <HealthSection />
    case 'logs': return <LogsSection />
    case 'backups': return <BackupsSection />
    case 'restore': return <RestoreSection />
    case 'maintenance': return <MaintenanceSection />
  }
}

function renderGroup(group: GroupDefinition, capabilities: readonly string[] | null) {
  const visibleChildren = group.children.filter((child) => hasAnyCapability(capabilities, child.capabilities))

  if (visibleChildren.length === 1) {
    return renderChild(visibleChildren[0]!.key)
  }

  return (
    <div className="space-y-10">
      {visibleChildren.map((child) => (
        <div
          key={child.key}
          className={child.key === 'restore' ? 'border-t border-border pt-10' : undefined}
        >
          {renderChild(child.key)}
        </div>
      ))}
    </div>
  )
}

export function PlatformManagementScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const capabilities = principal.capabilities
  const [tabValue, setTabValue] = useWorkspaceTab<PlatformTabValue>('tab', 'overview')

  const visible = GROUPS.filter((group) =>
    group.children.some((child) => hasAnyCapability(capabilities, child.capabilities)),
  )
  const requestedGroup = LEGACY_GROUPS[tabValue]
  const activeTab = visible.some((group) => group.key === requestedGroup)
    ? requestedGroup
    : (visible[0]?.key ?? 'overview')

  const items: WorkspaceTabItem[] = visible.map((group) => ({
    value: group.key,
    label: groupLabel(group.key, locale),
    content: renderGroup(group, capabilities),
  }))

  return (
    <PageLayout>
      <PageHeader
        title={t(platformCopy.title, locale)}
        description={t(platformCopy.description, locale)}
        headingId="platform-management-title"
      />
      {visible.length === 0 ? (
        <DeniedState locale={locale} />
      ) : (
        <WorkspaceTabs
          label={t(platformCopy.title, locale)}
          value={activeTab}
          onValueChange={(value) => setTabValue(value as PlatformGroupKey)}
          items={items}
        />
      )}
    </PageLayout>
  )
}
