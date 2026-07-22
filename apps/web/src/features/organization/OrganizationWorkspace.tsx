import {
  ArrowRightLeft,
  Building2,
  FileSpreadsheet,
  Network,
  Users,
} from 'lucide-react'
import type { ReactNode } from 'react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { text, type Locale } from '../../app/copy'
import { PageHeader } from '../../ui'
import { OrganizationOverview } from './OrganizationOverview'
import { OrganizationStructure } from './OrganizationStructure'
import { PeopleAssignments } from './PeopleAssignments'
import { TemporaryAssignments } from './TemporaryAssignments'
import { ImportReview } from '../imports/ImportReview'

export type OrganizationWorkspaceRoute =
  | 'organization'
  | 'organization-structure'
  | 'people-assignments'
  | 'temporary-assignments'
  | 'organization-import'

export type OrganizationWorkspaceProps = {
  locale: Locale
  activeRouteName: OrganizationWorkspaceRoute
  jobId?: string
  navigate: (path: string) => void
}

const tabPaths: Record<OrganizationWorkspaceRoute, string> = {
  organization: '/admin/organization',
  'organization-structure': '/admin/organization/structure',
  'people-assignments': '/admin/organization/people',
  'temporary-assignments': '/admin/organization/temporary-assignments',
  'organization-import': '/admin/imports/organization',
}

export function OrganizationWorkspace({
  locale,
  activeRouteName,
  jobId,
  navigate,
}: OrganizationWorkspaceProps) {
  const labels = locale === 'ar'
    ? {
        tabs: 'أقسام المنظمة',
        overview: 'نظرة عامة',
        structure: 'شجرة الهيكل',
        people: 'الأشخاص',
        assignments: 'التكليفات',
        imports: 'الاستيراد',
      }
    : {
        tabs: 'Organization sections',
        overview: 'Overview',
        structure: 'Structure tree',
        people: 'People',
        assignments: 'Assignments',
        imports: 'Imports',
      }

  const tabs = [
    { key: 'organization', label: labels.overview, path: tabPaths.organization, icon: <Building2 aria-hidden="true" /> },
    { key: 'organization-structure', label: labels.structure, path: tabPaths['organization-structure'], icon: <Network aria-hidden="true" /> },
    { key: 'people-assignments', label: labels.people, path: tabPaths['people-assignments'], icon: <Users aria-hidden="true" /> },
    { key: 'temporary-assignments', label: labels.assignments, path: tabPaths['temporary-assignments'], icon: <ArrowRightLeft aria-hidden="true" /> },
    { key: 'organization-import', label: labels.imports, path: tabPaths['organization-import'], icon: <FileSpreadsheet aria-hidden="true" /> },
  ].map((tab) => ({ ...tab, active: tab.key === activeRouteName }))

  let screen: ReactNode
  switch (activeRouteName) {
    case 'organization-structure':
      screen = <OrganizationStructure />
      break
    case 'people-assignments':
      screen = <PeopleAssignments />
      break
    case 'temporary-assignments':
      screen = <TemporaryAssignments />
      break
    case 'organization-import':
      screen = (
        <ImportReview
          jobId={jobId}
          onJobOpen={(nextJobId) => navigate(`${tabPaths['organization-import']}/${nextJobId}`)}
        />
      )
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
      <WorkspaceTabs label={labels.tabs} tabs={tabs} onNavigate={navigate} />
      {screen}
    </div>
  )
}
