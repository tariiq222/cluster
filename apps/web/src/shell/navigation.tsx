import type { ReactNode } from 'react'
import {
  BarChart3,
  BookOpenText,
  Building2,
  ClipboardList,
  FileText,
  Users,
  Home,
} from 'lucide-react'

import { text, type Locale } from '../app/copy'
import {
  pathFromRoute,
  PLATFORM_SETTINGS_OVERVIEW_CAPABILITIES,
  WORK_MANAGEMENT_ROUTE_NAMES,
  type AppRoute,
  type FeatureProjection,
} from './routes'

/**
 * The primary navigation registry defines every sidebar entry once. Each entry
 * owns its label, icon, route, and capability policy. The shell renders the
 * visible entries directly as a flat list; the dashboard and detail routes use
 * `pathFromRoute` so URLs stay in one place. Adding an entry without
 * specifying a policy is a compile error.
 */

/**
 * Restrict the label pool to copy entries that are plain strings in both
 * locales; copy.ts also contains templated helpers (e.g. `markNotificationRead`)
 * which must not be referenced as sidebar labels.
 */
type StringCopyKeys<T> = {
  [K in keyof T]: T[K] extends string ? K : never
}[keyof T]
export type NavigationLabelKey = StringCopyKeys<(typeof text)['ar']> &
  StringCopyKeys<(typeof text)['en']>

export type NavigationPolicy =
  { kind: 'authenticated' } | { kind: 'anyOf'; capabilities: readonly string[] }

export type PrimaryNavigationKey =
  | 'home'
  | 'tasks'
  | 'documents'
  | 'organization'
  | 'accounts-permissions'
  | 'reports-monitoring'
  | 'platform-management'

export type PrimaryNavigationEntry = {
  key: PrimaryNavigationKey
  route: AppRoute
  labelKey: NavigationLabelKey
  icon: ReactNode
  policy: NavigationPolicy
}

export type PrimaryNavigationItem = {
  key: PrimaryNavigationKey
  label: string
  path: string
  icon: ReactNode
  route: AppRoute
}

export type UserMenuEntry = {
  key: string
  route: AppRoute
  labelKey: NavigationLabelKey
}

const ICONS = {
  home: <Home aria-hidden="true" />,
  tasks: <ClipboardList aria-hidden="true" />,
  documents: <FileText aria-hidden="true" />,
  organization: <Building2 aria-hidden="true" />,
  roles: <Users aria-hidden="true" />,
  reports: <BarChart3 aria-hidden="true" />,
  apiDocs: <BookOpenText aria-hidden="true" />,
} as const

const anyOf = (capabilities: readonly string[]): NavigationPolicy => ({
  kind: 'anyOf',
  capabilities,
})

/**
 * The seven primary destinations rendered in the sidebar. The order is
 * authoritative: the brief freezes it from Home through Platform management
 * so the user menu stays the only place personal access surfaces.
 */
export const PRIMARY_NAVIGATION_ENTRIES: readonly PrimaryNavigationEntry[] = [
  {
    key: 'home',
    route: { name: 'list' },
    labelKey: 'home',
    icon: ICONS.home,
    policy: { kind: 'authenticated' },
  },
  {
    key: 'tasks',
    route: { name: 'tasks' },
    labelKey: 'myTasks',
    icon: ICONS.tasks,
    policy: anyOf(['tasks.read', 'tasks.list']),
  },
  {
    key: 'documents',
    route: { name: 'documents' },
    labelKey: 'documents',
    icon: ICONS.documents,
    policy: anyOf(['documents.read', 'documents.list']),
  },
  {
    key: 'organization',
    route: { name: 'organization' },
    labelKey: 'organizationAndWorkforce',
    icon: ICONS.organization,
    policy: anyOf([
      'organization.facility.read',
      'organization.unit.read',
      'organization.person.read',
      'organization.import.read',
    ]),
  },
  {
    key: 'accounts-permissions',
    route: { name: 'identity-accounts' },
    labelKey: 'accountsAndAccess',
    icon: ICONS.roles,
    policy: anyOf([
      'identity.account.read',
      'authorization.role.read',
      'authorization.capability.read',
      'authorization.assignment.read',
      'authorization.policy.read',
      'authorization.decision.read',
    ]),
  },
  {
    key: 'reports-monitoring',
    route: { name: 'reports' },
    labelKey: 'reportsAndIndicators',
    icon: ICONS.reports,
    policy: anyOf(['reporting.list', 'reporting.dashboard', 'audit.event.read']),
  },
  {
    key: 'platform-management',
    route: { name: 'platform-settings', section: 'overview' },
    labelKey: 'platformManagement',
    icon: ICONS.apiDocs,
    policy: anyOf([...PLATFORM_SETTINGS_OVERVIEW_CAPABILITIES, 'authorization.audit.read']),
  },
]

/**
 * User menu entries (top-right). Only the principal's own screens live here.
 */
export const USER_MENU_ENTRIES: readonly UserMenuEntry[] = [
  {
    key: 'personal-security',
    route: { name: 'personal-security' },
    labelKey: 'personalSecurity',
  },
  {
    key: 'access-context',
    route: { name: 'access-context' },
    labelKey: 'personalAccess',
  },
]

/**
 * Decide whether an entry should be visible for the given principal. While the
 * principal context is still loading (`capabilities === null`) every gated
 * entry is hidden so the sidebar never advertises what is withheld.
 */
export function isNavigationEntryVisible(
  entry: PrimaryNavigationEntry,
  capabilities: readonly string[] | null,
  features: FeatureProjection | null = null,
): boolean {
  if (WORK_MANAGEMENT_ROUTE_NAMES[entry.route.name]) {
    if (!features || features.work_management !== true) return false
  }
  if (entry.policy.kind === 'authenticated') return true
  if (capabilities === null) return false
  return entry.policy.capabilities.some((code) => capabilities.includes(code))
}

/**
 * Materialize the flat sidebar items for the active principal. Visibility is
 * fail-closed: gated entries stay hidden until their capability is granted
 * AND any feature gate has landed.
 */
export function buildPrimaryNavigationItems(args: {
  locale: Locale
  capabilities: readonly string[] | null
  features: FeatureProjection | null
}): PrimaryNavigationItem[] {
  return PRIMARY_NAVIGATION_ENTRIES
    .filter((entry) => isNavigationEntryVisible(entry, args.capabilities, args.features))
    .map((entry) => ({
      key: entry.key,
      label: text[args.locale][entry.labelKey],
      path: pathFromRoute(entry.route),
      icon: entry.icon,
      route: entry.route,
    }))
}

export function buildUserMenuEntries(
  locale: Locale,
): Array<{ key: string; path: string; label: string }> {
  return USER_MENU_ENTRIES.map((entry) => ({
    key: entry.key,
    path: pathFromRoute(entry.route),
    label: text[locale][entry.labelKey],
  }))
}

