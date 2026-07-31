import { type AccountPermissionsTabKey, accountPermissionsTabs } from '../accounts-permissions/canMutateAdminResource'
import { AccountsPermissionsWorkspace } from '../accounts-permissions/AccountsPermissionsWorkspace'
import { type AppRoute } from '../../shell/routes'

type Locale = 'ar' | 'en'


export type AccessSectionKey =
  | 'accounts'
  | 'roles-permissions'
  | 'role-assignments'
  | 'policies-scopes'
  | 'decision-inspector'

export type AccessWorkspaceProps = {
  locale: Locale
  activeRoute: AppRoute
  navigate: (path: string) => void
  scopeReady?: boolean
  scopeEpoch?: number
  capabilities?: readonly string[]
}

/**
 * Maps a route to its owning access workspace section. The five sections
 * collapse the prior eight governance/diagnostic tabs into a single local
 * navigation level per workspace.
 */
export function accessSectionForRoute(route: AppRoute): AccessSectionKey {
  if (route.name === 'identity-accounts') return 'accounts'
  if (
    route.name === 'authorization' &&
    (route.resource === 'roles' || route.resource === 'capabilities')
  ) {
    return 'roles-permissions'
  }
  if (route.name === 'authorization' && route.resource === 'role-assignments') {
    return 'role-assignments'
  }
  if (route.name === 'authorization' || route.name === 'access-scopes') {
    return 'policies-scopes'
  }
  return 'decision-inspector'
}

function activeTabForRoute(route: AppRoute): AccountPermissionsTabKey {
  const routeTab = accessSectionForRoute(route)
  const queryTab = new URLSearchParams(window.location.search).get('tab')

  return queryTab !== null && accountPermissionsTabs.includes(queryTab as AccountPermissionsTabKey)
    ? queryTab as AccountPermissionsTabKey
    : routeTab
}

export function AccessWorkspace({ locale, activeRoute, navigate, capabilities }: AccessWorkspaceProps) {
  const activeTab = activeTabForRoute(activeRoute)

  return (
    <AccountsPermissionsWorkspace
      locale={locale}
      activeTab={activeTab}
      capabilities={capabilities ?? []}
      navigate={navigate}
      decisionId={activeRoute.name === 'access-explanation' ? activeRoute.decisionId : undefined}
    />
  )
}
