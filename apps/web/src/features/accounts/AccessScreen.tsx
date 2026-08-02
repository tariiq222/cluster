import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocale } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { PageHeader, PageLayout } from '@/components/page-layout'
import { WorkspaceTabs, type WorkspaceTabItem } from '@/components/workspace-tabs'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { stateFromError } from '../../api/http'
import * as access from '../../api/access'
import { accountsCopy } from './accounts-copy'
import { AccountsTab } from './tabs/AccountsTab'
import { RolesTab } from './tabs/RolesTab'
import { DiagnosticsTab } from './tabs/DiagnosticsTab'
import { BootstrapTab } from './tabs/BootstrapTab'

type TabKey = 'accounts' | 'roles' | 'diagnostics' | 'bootstrap'

/*
 * The diagnostics panel additionally owns horizontal overflow: a Badge
 * (primitive `w-fit shrink-0`) sizes to its flex base (max-content)
 * inside the reason-code strip, so a long code cannot wrap and would
 * otherwise extend the document past a narrow viewport. `overflow-x-auto`
 * contains it at the panel edge (the DataTable pattern) while keeping
 * the code reachable by horizontal scroll.
 *
 * Every other panel inherits the shared WorkspaceTabs focus-visible
 * ring (and the `min-w-0 max-w-full` width clamps / `pt-6` rhythm) from
 * the wrapper, so they need no panel-level class plumbing here.
 */
const DIAGNOSTICS_PANEL_CLASS = 'overflow-x-auto'

export function AccessScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const text = accountsCopy[locale]
  const capabilities = principal.capabilities ?? []
  const canAccounts = capabilities.includes('identity.account.read')
  const canRoles = [
    'authorization.role.read',
    'authorization.capability.read',
    'authorization.assignment.read',
  ].some((capability) => capabilities.includes(capability))
  const canDiagnostics = capabilities.includes('authorization.decision.read')
  const canBootstrap = capabilities.includes('authorization.bootstrap.complete')

  /*
   * Bootstrap state is fetched only when the capability exists. The tab is
   * shown only while the normalized status is still `bootstrap_pending`, so
   * a completed bootstrap never renders it.
   */
  const bootstrapQuery = useQuery({
    queryKey: ['authorization-bootstrap'] as const,
    queryFn: () => access.fetchBootstrapState(),
    enabled: canBootstrap,
  })
  const bootstrapPending =
    canBootstrap &&
    bootstrapQuery.isSuccess &&
    bootstrapQuery.data.status === 'bootstrap_pending'

  const tabs: Array<{ key: TabKey; label: string; visible: boolean }> = [
    { key: 'accounts', label: text.tabAccounts, visible: canAccounts },
    { key: 'roles', label: text.tabRoles, visible: canRoles },
    { key: 'diagnostics', label: text.tabDiagnostics, visible: canDiagnostics },
    { key: 'bootstrap', label: text.tabBootstrap, visible: bootstrapPending },
  ]
  const visible = tabs.filter((entry) => entry.visible)

  const [tab, setTab] = useState<TabKey>('accounts')
  const activeTab = visible.some((entry) => entry.key === tab)
    ? tab
    : (visible[0]?.key ?? 'accounts')

  const onlyBootstrapPossible =
    canBootstrap &&
    !canAccounts &&
    !canRoles &&
    !canDiagnostics

  /*
   * Only the bootstrap tab is possible while its state is still loading:
   * render the shared loading state instead of guessing at tabs. The
   * shared LoadingState announces the locale-resolved loading copy so a
   * screen reader does not sit silent during the bootstrap fetch.
   */
  if (onlyBootstrapPossible && bootstrapQuery.isLoading) {
    return (
      <PageLayout>
        <PageHeader title={text.title} description={text.intro} />
        <LoadingState rows={2} announce={text.loading} />
      </PageLayout>
    )
  }

  /*
   * A bootstrap fetch failure must not masquerade as "denied": 403/404 keep
   * the shared non-disclosing copy, but network/generic failures surface a
   * real retry.
   */
  if (onlyBootstrapPossible && bootstrapQuery.isError) {
    const derived = stateFromError(bootstrapQuery.error)
    return (
      <PageLayout>
        <PageHeader title={text.title} description={text.intro} />
        {derived === 'forbidden' || derived === 'not-found' ? (
          <DeniedState locale={locale} />
        ) : (
          <ErrorState
            locale={locale}
            onRetry={() => void bootstrapQuery.refetch()}
          />
        )}
      </PageLayout>
    )
  }

  const items: WorkspaceTabItem[] = []
  if (canAccounts) {
    items.push({
      value: 'accounts',
      label: text.tabAccounts,
      content: <AccountsTab />,
    })
  }
  if (canRoles) {
    items.push({
      value: 'roles',
      label: text.tabRoles,
      content: <RolesTab />,
    })
  }
  if (canDiagnostics) {
    items.push({
      value: 'diagnostics',
      label: text.tabDiagnostics,
      content: <DiagnosticsTab />,
      contentClassName: DIAGNOSTICS_PANEL_CLASS,
    })
  }
  if (bootstrapPending && bootstrapQuery.data) {
    items.push({
      value: 'bootstrap',
      label: text.tabBootstrap,
      content: (
        <BootstrapTab
          bootstrap={bootstrapQuery.data}
          onRefresh={() => void bootstrapQuery.refetch()}
        />
      ),
    })
  }

  return (
    <PageLayout>
      <PageHeader title={text.title} description={text.intro} />
      {visible.length === 0 ? (
        <DeniedState locale={locale} />
      ) : (
        <WorkspaceTabs
          label={text.tabsLabel}
          value={activeTab}
          onValueChange={(value) => setTab(value as TabKey)}
          items={items}
          navTestId="access-tab-nav"
        />
      )}
    </PageLayout>
  )
}
