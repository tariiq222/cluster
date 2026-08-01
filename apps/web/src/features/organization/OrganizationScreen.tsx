import { useState } from 'react'
import { useLocale } from '../../app/session-context'
import { Page, PageHeader, Tabs } from '../../ui'
import { organizationCopy } from './organization-copy'
import { OverviewTab } from './tabs/OverviewTab'
import { StructureTab } from './tabs/StructureTab'
import { PeopleTab } from './tabs/PeopleTab'

type TabKey = 'overview' | 'structure' | 'people'

export function OrganizationScreen() {
  const locale = useLocale()
  const text = organizationCopy[locale]
  const [tab, setTab] = useState<TabKey>('overview')

  return (
    <Page>
      <PageHeader
        id="organization-screen-heading"
        title={text.title}
        description={text.intro}
      />
      <Tabs
        label={text.tabsLabel}
        tabs={[
          {
            key: 'overview',
            label: text.overviewTab,
            active: tab === 'overview',
            onClick: () => setTab('overview'),
          },
          {
            key: 'structure',
            label: text.structureTab,
            active: tab === 'structure',
            onClick: () => setTab('structure'),
          },
          {
            key: 'people',
            label: text.peopleTab,
            active: tab === 'people',
            onClick: () => setTab('people'),
          },
        ]}
      />
      {tab === 'overview' ? <OverviewTab /> : null}
      {tab === 'structure' ? <StructureTab /> : null}
      {tab === 'people' ? <PeopleTab /> : null}
    </Page>
  )
}
