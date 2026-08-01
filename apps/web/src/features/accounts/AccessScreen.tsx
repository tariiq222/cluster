import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocale } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { DeniedState, ErrorState, LoadingState } from '@/components/states'
import { stateFromError } from '../../api/http'
import * as access from '../../api/access'
import { accountsCopy } from './accounts-copy'
import { AccountsTab } from './tabs/AccountsTab'
import { RolesTab } from './tabs/RolesTab'
import { DiagnosticsTab } from './tabs/DiagnosticsTab'
import { BootstrapTab } from './tabs/BootstrapTab'

type TabKey = 'accounts' | 'roles' | 'diagnostics' | 'bootstrap'

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
   * render the shared loading state instead of guessing at tabs.
   */
  if (onlyBootstrapPossible && bootstrapQuery.isLoading) {
    return (
      <div className="space-y-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{text.title}</h1>
          <p className="text-muted-foreground text-sm">{text.intro}</p>
        </div>
        <LoadingState rows={2} />
      </div>
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
      <div className="space-y-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{text.title}</h1>
          <p className="text-muted-foreground text-sm">{text.intro}</p>
        </div>
        {derived === 'forbidden' || derived === 'not-found' ? (
          <DeniedState locale={locale} />
        ) : (
          <ErrorState
            locale={locale}
            onRetry={() => void bootstrapQuery.refetch()}
          />
        )}
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{text.title}</h1>
        <p className="text-muted-foreground text-sm">{text.intro}</p>
      </div>
      {visible.length === 0 ? (
        <DeniedState locale={locale} />
      ) : (
        <Tabs value={activeTab} onValueChange={(value) => setTab(value as TabKey)}>
          <nav aria-label={text.tabsLabel}>
            <TabsList>
              {visible.map((entry) => (
                <TabsTrigger key={entry.key} value={entry.key}>
                  {entry.label}
                </TabsTrigger>
              ))}
            </TabsList>
          </nav>
          <TabsContent value="accounts">
            {canAccounts ? <AccountsTab /> : null}
          </TabsContent>
          <TabsContent value="roles">
            {canRoles ? <RolesTab /> : null}
          </TabsContent>
          <TabsContent value="diagnostics">
            {canDiagnostics ? <DiagnosticsTab /> : null}
          </TabsContent>
          <TabsContent value="bootstrap">
            {bootstrapPending && bootstrapQuery.data ? (
              <BootstrapTab
                bootstrap={bootstrapQuery.data}
                onRefresh={() => void bootstrapQuery.refetch()}
              />
            ) : null}
          </TabsContent>
        </Tabs>
      )}
    </div>
  )
}
