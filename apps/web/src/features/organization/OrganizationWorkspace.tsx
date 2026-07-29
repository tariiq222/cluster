import {
  Building2,
  Network,
  Users,
} from 'lucide-react'
import type { ReactNode } from 'react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { text, type Locale } from '../../app/copy'
import { PageHeader } from '../../ui'
import { pathFromRoute, type AppRoute } from '../../shell/routes'
import { OrganizationOverview } from './OrganizationOverview'
import { OrganizationStructure } from './OrganizationStructure'
import { PeopleAssignments } from './PeopleAssignments'
import { ImportReview } from '../imports/ImportReview'
import { AuthorizationAdmin } from '../authorization/AuthorizationAdmin'

export type OrganizationSection = 'organization' | 'employees' | 'supervisory'

export type OrganizationWorkspaceProps = {
  locale: Locale
  activeRoute: AppRoute
  navigate: (path: string) => void
  capabilities: readonly string[] | null
}

const ORGANIZATION_FACILITY_CAPABILITY = 'organization.facility.read'
const ORGANIZATION_UNIT_CAPABILITY = 'organization.unit.read'
const ORGANIZATION_PERSON_CAPABILITY = 'organization.person.read'
const SUPERVISORY_CAPABILITY = 'organization.unit.read'

const sectionForRoute = (route: AppRoute): OrganizationSection => {
  if (route.name === 'people-assignments' || route.name === 'organization-import') return 'employees'
  if (route.name === 'authorization' && route.resource === 'supervisory') return 'supervisory'
  return 'organization'
}

export function OrganizationWorkspace({
  locale,
  activeRoute,
  navigate,
  capabilities,
}: OrganizationWorkspaceProps) {
  const labels = locale === 'ar'
    ? {
        tabs: 'أقسام إدارة المنشآت والموظفين',
        organization: 'المنشآت والهيكل التنظيمي',
        employees: 'الموظفون والتكليفات الوظيفية',
        supervisory: 'العلاقات الإشرافية',
      }
    : {
        tabs: 'Organization sections',
        organization: 'Facilities and structure',
        employees: 'Employees and job assignments',
        supervisory: 'Supervisory relationships',
      }

  const canReadOrganization =
    (capabilities?.includes(ORGANIZATION_FACILITY_CAPABILITY) ?? false) ||
    (capabilities?.includes(ORGANIZATION_UNIT_CAPABILITY) ?? false)
  const canReadEmployees = capabilities?.includes(ORGANIZATION_PERSON_CAPABILITY) ?? false
  const canReadSupervisory = capabilities?.includes(SUPERVISORY_CAPABILITY) ?? false

  const section = sectionForRoute(activeRoute)

  const allTabs = [
    {
      key: 'organization' as const,
      label: labels.organization,
      path: pathFromRoute({ name: 'organization' }),
      icon: <Building2 aria-hidden="true" />,
      visible: canReadOrganization,
    },
    {
      key: 'employees' as const,
      label: labels.employees,
      path: pathFromRoute({ name: 'people-assignments' }),
      icon: <Users aria-hidden="true" />,
      visible: canReadEmployees,
    },
    {
      key: 'supervisory' as const,
      label: labels.supervisory,
      path: pathFromRoute({ name: 'authorization', resource: 'supervisory' }),
      icon: <Network aria-hidden="true" />,
      visible: canReadSupervisory,
    },
  ]
  const visibleTabs = allTabs.filter((tab) => tab.visible)

  const activeSection: OrganizationSection = visibleTabs.some((tab) => tab.key === section)
    ? section
    : (visibleTabs[0]?.key ?? section)

  const tabsForRender = visibleTabs.map((tab) => ({
    key: tab.key,
    label: tab.label,
    path: tab.path,
    icon: tab.icon,
    active: tab.key === activeSection,
  }))

  let screen: ReactNode
  if (activeSection === 'organization') {
    if (activeRoute.name === 'organization-structure') {
      screen = <OrganizationStructure />
    } else {
      screen = <OrganizationOverview />
    }
  } else if (activeSection === 'employees') {
    if (activeRoute.name === 'organization-import') {
      screen = (
        <ImportReview
          jobId={'jobId' in activeRoute ? activeRoute.jobId : undefined}
          onJobOpen={(nextJobId) =>
            navigate(pathFromRoute({ name: 'organization-import', jobId: nextJobId }))
          }
        />
      )
    } else {
      screen = (
        <PeopleAssignments
          onImport={() => navigate(pathFromRoute({ name: 'organization-import' }))}
        />
      )
    }
  } else {
    screen = <AuthorizationAdmin resource="supervisory" capabilities={capabilities ?? []} />
  }

  return (
    <div className="organization-workspace">
      <PageHeader
        id="organization-workspace-heading"
        title={text[locale].organizationAndWorkforce}
        description={text[locale].manageFacilitiesStructurePeopleAnd}
      />
      <WorkspaceTabs label={labels.tabs} tabs={tabsForRender} onNavigate={navigate} />
      {screen}
    </div>
  )
}