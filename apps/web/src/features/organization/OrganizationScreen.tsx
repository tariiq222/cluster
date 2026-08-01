import { useState } from 'react'
import { useLocale } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
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

interface TabDef {
  key: string
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
    { key: 'job-titles', labelKey: 'jobTitlesTab', capability: 'organization.job_title.read', render: () => <JobTitlesTab /> },
    { key: 'people', labelKey: 'peopleTab', capability: 'organization.person.read', render: () => <PeopleTab /> },
    { key: 'assignments', labelKey: 'assignmentsTab', capability: 'organization.assignment.read', render: () => <AssignmentsTab /> },
    { key: 'temporary', labelKey: 'temporaryTab', capability: 'organization.temporary_assignment.read', render: () => <TemporaryAssignmentsTab /> },
    { key: 'supervisory', labelKey: 'supervisoryTab', capability: 'organization.supervisory.read', render: () => <SupervisoryTab /> },
    { key: 'cluster', labelKey: 'clusterTab', capability: 'organization.cluster.read', render: () => <ClusterTab /> },
  ]
  const visible = tabs.filter((tab) => can(tab.capability))
  const [active, setActive] = useState(visible[0]?.key ?? 'structure')

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{text.title}</h1>
        <p className="text-muted-foreground text-sm">{text.intro}</p>
      </div>

      {visible.length === 0 ? (
        <p className="text-muted-foreground text-sm">{text.unavailable}</p>
      ) : (
        <Tabs value={active} onValueChange={setActive}>
          <TabsList className="flex-wrap">
            {visible.map((tab) => (
              <TabsTrigger key={tab.key} value={tab.key}>
                {text[tab.labelKey]}
              </TabsTrigger>
            ))}
          </TabsList>
          {visible.map((tab) => (
            <TabsContent key={tab.key} value={tab.key}>
              {tab.render()}
            </TabsContent>
          ))}
        </Tabs>
      )}
    </div>
  )
}
