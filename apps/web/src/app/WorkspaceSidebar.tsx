import { useMemo } from 'react'

import { type SidebarNavigationItem } from './AppShell'
import { type Locale } from './copy'
import {
  buildPrimaryNavigationItems,
} from '../shell/navigation'
import {
  isRouteActive,
  type AppRoute,
} from '../shell/routes'

export type WorkspaceSidebarProps = {
  locale: Locale
  route: AppRoute
  capabilities: readonly string[] | null
  features: { work_management: boolean; tasks: boolean } | null
  onNavigate: (path: string) => void
}

/**
 * Derives the flat sidebar navigation items for the current route and capability
 * set. The shell pipes the returned items into `AppShell`'s `navigationItems`
 * prop. Personal screens stay in the user menu.
 */
export function useWorkspaceNavigation({
  locale,
  route,
  capabilities,
  features,
  onNavigate,
}: WorkspaceSidebarProps): SidebarNavigationItem[] {
  return useMemo(() => {
    return buildPrimaryNavigationItems({ locale, capabilities, features }).map((item) => {
      const target = item.path
      const active = isRouteActive(route, item.route)
      return {
        key: item.key,
        label: item.label,
        path: item.path,
        icon: item.icon,
        active,
        onSelect: () => onNavigate(target),
      }
    })
  }, [capabilities, features, locale, onNavigate, route])
}
