import { useState } from 'react'
import { useLocale } from '../../app/session-context'
import { usePrincipal } from '../../app/principal-context'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { accountsCopy } from './accounts-copy'
import { AccountsTab } from './tabs/AccountsTab'
import { RolesTab } from './tabs/RolesTab'
import { InspectorTab } from './tabs/InspectorTab'

type TabKey = 'accounts' | 'roles' | 'inspector'

export function AccountsPermissionsScreen() {
  const locale = useLocale()
  const principal = usePrincipal()
  const text = accountsCopy[locale]
  const [tab, setTab] = useState<TabKey>('accounts')
  const capabilities = principal.capabilities ?? []
  const canAccounts = capabilities.includes('identity.account.read')
  const canRoles = capabilities.includes('authorization.role.read')
  const canInspector = capabilities.includes('authorization.decision.read')

  const tabs: Array<{ key: TabKey; label: string; visible: boolean }> = [
    { key: 'accounts', label: text.tabAccounts, visible: canAccounts },
    { key: 'roles', label: text.tabRoles, visible: canRoles },
    { key: 'inspector', label: text.tabInspector, visible: canInspector },
  ]
  const visible = tabs.filter((entry) => entry.visible)

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{text.title}</h1>
        <p className="text-muted-foreground text-sm">{text.intro}</p>
      </div>
      {visible.length === 0 ? (
        <p className="text-muted-foreground text-sm">{text.unavailable}</p>
      ) : (
        <Tabs value={tab} onValueChange={(value) => setTab(value as TabKey)}>
          <TabsList>
            {visible.map((entry) => (
              <TabsTrigger key={entry.key} value={entry.key}>
                {entry.label}
              </TabsTrigger>
            ))}
          </TabsList>
          <TabsContent value="accounts">
            {canAccounts ? <AccountsTab /> : null}
          </TabsContent>
          <TabsContent value="roles">
            {canRoles ? <RolesTab /> : null}
          </TabsContent>
          <TabsContent value="inspector">
            {canInspector ? <InspectorTab /> : null}
          </TabsContent>
        </Tabs>
      )}
    </div>
  )
}
