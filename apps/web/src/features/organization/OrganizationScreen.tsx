import { useLocale } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { useWorkspaceTab } from '../../app/use-workspace-tab'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { organizationCopy } from './organization-copy'
import { ClusterTab } from './tabs/ClusterTab'
import { FacilitiesTab } from './tabs/FacilitiesTab'
import { StructureTab } from './tabs/StructureTab'
import { PositionsTab } from './tabs/PositionsTab'
import { JobTitlesTab } from './tabs/JobTitlesTab'
import { PeopleTab } from './tabs/PeopleTab'
import { AssignmentsTab } from './tabs/AssignmentsTab'
import { TemporaryAssignmentsTab } from './tabs/TemporaryAssignmentsTab'
import { SupervisoryTab } from './tabs/SupervisoryTab'

type TabKey =
  | 'structure'
  | 'facilities'
  | 'positions'
  | 'job-titles'
  | 'people'
  | 'assignments'
  | 'temporary'
  | 'supervisory'
  | 'cluster'

interface TabDef {
  key: TabKey
  labelKey: 'structureTab' | 'facilitiesTab' | 'positionsTab' | 'jobTitlesTab' | 'peopleTab' | 'assignmentsTab' | 'temporaryTab' | 'supervisoryTab' | 'clusterTab'
  capability: string
  render: () => React.ReactNode
}

export function OrganizationScreen() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const principal = usePrincipal()
  const capabilities = principal.capabilities ?? []
  const can = (cap: string) => capabilities.includes(cap)

  const tabs: TabDef[] = [
    { key: 'structure', labelKey: 'structureTab', capability: 'organization.unit.read', render: () => <StructureTab /> },
    { key: 'facilities', labelKey: 'facilitiesTab', capability: 'organization.facility.read', render: () => <FacilitiesTab /> },
    { key: 'positions', labelKey: 'positionsTab', capability: 'organization.position.read', render: () => <PositionsTab /> },
    { key: 'job-titles', labelKey: 'jobTitlesTab', capability: 'organization.position.read', render: () => <JobTitlesTab /> },
    { key: 'people', labelKey: 'peopleTab', capability: 'organization.person.read', render: () => <PeopleTab /> },
    { key: 'assignments', labelKey: 'assignmentsTab', capability: 'organization.assignment.read', render: () => <AssignmentsTab /> },
    { key: 'temporary', labelKey: 'temporaryTab', capability: 'organization.temporary-assignment.read', render: () => <TemporaryAssignmentsTab /> },
    { key: 'supervisory', labelKey: 'supervisoryTab', capability: 'organization.unit.read', render: () => <SupervisoryTab /> },
    { key: 'cluster', labelKey: 'clusterTab', capability: 'organization.cluster.read', render: () => <ClusterTab /> },
  ]
  const visible = tabs.filter((tab) => can(tab.capability))
  const fallback: TabKey = visible[0]?.key ?? 'structure'
  const [requested, setActive] = useWorkspaceTab<TabKey>('tab', fallback)
  // The URL may name a tab the principal cannot read — fall back to the
  // first visible tab instead of rendering a non-disclosing empty pane.
  const active = visible.some((tab) => tab.key === requested) ? requested : fallback

  const items: WorkspaceTabItem[] = visible.map((tab) => ({
    value: tab.key,
    label: text[tab.labelKey],
    content: tab.render(),
  }))

  return (
    <PageLayout>
      <PageHeader title={text.title} description={text.intro} />

      {visible.length === 0 ? (
        <p className="text-muted-foreground text-sm">{text.unavailable}</p>
      ) : (
        <WorkspaceTabs
          label={text.tabsLabel}
          value={active}
          onValueChange={(next) => setActive(next as TabKey)}
          items={items}
          testId="organization-tabs"
        />
      )}
    </PageLayout>
  )
}
