import {
  Building2,
  Network,
} from 'lucide-react'
import type { ReactNode } from 'react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { text, type Locale } from '../../app/copy'
import { PageHeader } from '../../ui'
import { OrganizationOverview } from './OrganizationOverview'
import { OrganizationStructure } from './OrganizationStructure'

export type OrganizationWorkspaceRoute =
  | 'organization'
  | 'organization-structure'

export type OrganizationWorkspaceProps = {
  locale: Locale
  activeRouteName: OrganizationWorkspaceRoute
  navigate: (path: string) => void
  capabilities: readonly string[] | null
}

const tabPaths: Record<OrganizationWorkspaceRoute, string> = {
  organization: '/admin/organization',
  'organization-structure': '/admin/organization/structure',
}

export function OrganizationWorkspace({
  locale,
  activeRouteName,
  navigate,
  capabilities,
}: OrganizationWorkspaceProps) {
  const labels = locale === 'ar'
      ? {
        tabs: 'أقسام إدارة المنشآت والموظفين',
        overview: 'الملخص',
        structure: 'الهيكل التنظيمي',
      }
    : {
        tabs: 'Organization sections',
        overview: 'Summary',
        structure: 'Organization structure',
      }

  const canReadOverview = capabilities?.includes('organization.facility.read') ?? false
  const canReadStructure = capabilities?.includes('organization.unit.read') ?? false
  const tabs = [
    { key: 'organization', label: labels.overview, path: tabPaths.organization, icon: <Building2 aria-hidden="true" /> },
    { key: 'organization-structure', label: labels.structure, path: tabPaths['organization-structure'], icon: <Network aria-hidden="true" /> },
  ]
    .filter((tab) => (tab.key === 'organization' ? canReadOverview : canReadStructure))

  const activeTab = tabs.some((tab) => tab.key === activeRouteName)
    ? activeRouteName
    : tabs[0]?.key ?? activeRouteName
  const visibleTabs = tabs.map((tab) => ({ ...tab, active: tab.key === activeTab }))

  let screen: ReactNode
  switch (activeTab) {
    case 'organization-structure':
      screen = <OrganizationStructure />
      break
    case 'organization':
    default:
      screen = <OrganizationOverview />
      break
  }

  return (
    <div className="organization-workspace">
      <PageHeader
        id="organization-workspace-heading"
        title={text[locale].organizationAndWorkforce}
        description={text[locale].manageFacilitiesStructurePeopleAnd}
      />
      <WorkspaceTabs label={labels.tabs} tabs={visibleTabs} onNavigate={navigate} />
      {screen}
    </div>
  )
}
