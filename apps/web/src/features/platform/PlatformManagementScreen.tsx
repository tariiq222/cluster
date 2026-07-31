import { useState } from 'react'
import { usePrincipal } from '../../app/principal-context'
import { useLocale } from '../../app/session-context'
import { EmptyState, Page, PageHeader, Tabs } from '../../ui'
import { platformCopy, t } from './platform-copy'
import { OverviewSection } from './sections/OverviewSection'
import { SecuritySettingsSection } from './sections/SecuritySettingsSection'
import { CalendarsSection } from './sections/CalendarsSection'
import { BackupsSection } from './sections/BackupsSection'
import { LogsSection } from './sections/LogsSection'
import { HealthSection } from './sections/HealthSection'
import { MaintenanceSection } from './sections/MaintenanceSection'

type PlatformSectionKey = 'overview' | 'security' | 'calendars' | 'backups' | 'logs' | 'health' | 'maintenance'

interface SectionDefinition {
  key: PlatformSectionKey
  capabilities: readonly string[]
}

const SECTIONS: readonly SectionDefinition[] = [
  { key: 'overview', capabilities: ['platform_operations.health.read'] },
  { key: 'security', capabilities: ['platform_settings.read', 'platform_settings.manage'] },
  { key: 'calendars', capabilities: ['platform_settings.calendar.read', 'platform_settings.calendar.manage'] },
  { key: 'backups', capabilities: ['platform_operations.backup.read', 'platform_operations.backup.run'] },
  { key: 'logs', capabilities: ['platform_operations.logs.read'] },
  { key: 'health', capabilities: ['platform_operations.health.read'] },
  { key: 'maintenance', capabilities: ['platform_operations.maintenance.manage'] },
]

function sectionCapabilities(key: PlatformSectionKey): readonly string[] {
  return SECTIONS.find((section) => section.key === key)?.capabilities ?? []
}

function hasAnyCapability(capabilities: readonly string[] | null, required: readonly string[]): boolean {
  if (capabilities === null) return false
  return required.some((capability) => capabilities.includes(capability))
}

function sectionLabel(key: PlatformSectionKey, locale: 'ar' | 'en'): string {
  switch (key) {
    case 'overview': return t(platformCopy.tabs.overview, locale)
    case 'security': return t(platformCopy.tabs.security, locale)
    case 'calendars': return t(platformCopy.tabs.calendars, locale)
    case 'backups': return t(platformCopy.tabs.backups, locale)
    case 'logs': return t(platformCopy.tabs.logs, locale)
    case 'health': return t(platformCopy.tabs.health, locale)
    case 'maintenance': return t(platformCopy.tabs.maintenance, locale)
  }
}

export function PlatformManagementScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const capabilities = principal.capabilities
  const [activeSection, setActiveSection] = useState<PlatformSectionKey>('overview')

  const tabs = SECTIONS.map((section) => ({
    key: section.key,
    label: sectionLabel(section.key, locale),
    active: section.key === activeSection,
    onClick: () => setActiveSection(section.key),
  }))

  const admitted = hasAnyCapability(capabilities, sectionCapabilities(activeSection))

  const renderSection = (key: PlatformSectionKey) => {
    switch (key) {
      case 'overview': return <OverviewSection />
      case 'security': return <SecuritySettingsSection />
      case 'calendars': return <CalendarsSection />
      case 'backups': return <BackupsSection />
      case 'logs': return <LogsSection />
      case 'health': return <HealthSection />
      case 'maintenance': return <MaintenanceSection />
    }
  }

  return (
    <Page>
      <PageHeader
        id="platform-management-title"
        title={t(platformCopy.title, locale)}
        description={t(platformCopy.description, locale)}
      />
      <Tabs tabs={tabs} label={t(platformCopy.title, locale)} />
      {admitted ? (
        renderSection(activeSection)
      ) : (
        <EmptyState
          title={t(platformCopy.unavailable, locale)}
          body={t(platformCopy.unavailableBody, locale)}
        />
      )}
    </Page>
  )
}
