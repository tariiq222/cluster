import { useState } from 'react'
import { useLocale } from '../../app/session-context'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { meCopy } from './me-copy'
import { SecurityTab } from './tabs/SecurityTab'
import { AccessTab } from './tabs/AccessTab'

type TabKey = 'security' | 'access'

export function MeScreen() {
  const locale = useLocale()
  const text = meCopy[locale]
  const [tab, setTab] = useState<TabKey>('security')

  const items: WorkspaceTabItem[] = [
    { value: 'security', label: text.tabSecurity, content: <SecurityTab /> },
    { value: 'access', label: text.tabAccess, content: <AccessTab /> },
  ]

  return (
    <PageLayout>
      <PageHeader title={text.title} description={text.intro} />
      <WorkspaceTabs
        label={text.tabsLabel}
        value={tab}
        onValueChange={(value) => setTab(value as TabKey)}
        items={items}
      />
    </PageLayout>
  )
}
