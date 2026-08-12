import { useLocale } from '../../app/session-context'
import { useWorkspaceTab } from '../../app/use-workspace-tab'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { meCopy } from './me-copy'
import { SecurityTab } from './tabs/SecurityTab'
import { AccessTab } from './tabs/AccessTab'

type TabKey = 'security' | 'access'

/*
 * The accepted tab values for this workspace. The hook's optional
 * `isValid` predicate uses this list to reject a stale or attacker-
 * controlled `?tab=` value, so a URL like `/me?tab=foo` falls back to
 * the default tab instead of pinning a key that no panel exists for.
 */
const TAB_KEYS: readonly TabKey[] = ['security', 'access']
const isTabKey = (value: string): value is TabKey =>
  (TAB_KEYS as readonly string[]).includes(value)

export function MeScreen() {
  const locale = useLocale()
  const text = meCopy[locale]
  const [tab, setTab] = useWorkspaceTab<TabKey>('tab', 'security', isTabKey)

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
