import { useMemo } from 'react'

import { type SidebarNavigationGroup } from './AppShell'
import { text, type Locale } from './copy'
import {
  buildNavigationGroups,
} from '../shell/navigation'
import {
  pathFromRoute,
  type AppRoute,
} from '../shell/routes'

/**
 * Two routes that share their primary tab (organization / authorization) keep both
 * tabs highlighted together, so the sidebar stays stable across the wildcard nav.
 */
function isRouteInNavigationActive(current: AppRoute, targetPath: string): boolean {
  if (pathFromRoute(current) === targetPath) return true
  if (current.name === 'organization' && (targetPath === '/admin/organization/structure' || targetPath === '/admin/organization')) return true
  if (current.name === 'organization-structure' && (targetPath === '/admin/organization/structure' || targetPath === '/admin/organization')) return true
  if (current.name === 'authorization' && (current.resource === 'roles' || current.resource === 'capabilities')
    && (targetPath === '/admin/authorization/roles' || targetPath === '/admin/authorization/capabilities')) return true
  return false
}

export type WorkspaceSidebarProps = {
  locale: Locale
  route: AppRoute
  capabilities: readonly string[] | null
  features: { work_management: boolean; tasks: boolean } | null
  onNavigate: (path: string) => void
}

/**
 * Derives the sidebar navigation groups for the current route and capability set.
 * The shell pipes the returned groups into `AppShell`'s `navigationGroups` prop.
 */
export function useWorkspaceSidebar({
  locale,
  route,
  capabilities,
  features,
  onNavigate,
}: WorkspaceSidebarProps): SidebarNavigationGroup[] {
  const copy = text[locale]
  return useMemo(() => {
    const built = buildNavigationGroups({ locale, capabilities, features })
    return built.map((group) => ({
      key: group.key,
      label: copy[group.labelKey],
      icon: group.icon,
      items: group.items.map((item) => {
        const target = item.path
        const active = isRouteInNavigationActive(route, target)
        return {
          key: item.key,
          label: item.label,
          path: item.path,
          icon: item.icon,
          active,
          onSelect: () => onNavigate(target),
        }
      }),
    }))
  }, [capabilities, copy, features, locale, onNavigate, route])
}
