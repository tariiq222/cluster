import type { ReactElement, ReactNode } from 'react'
import {
  BarChart3,
  BookOpenText,
  Building2,
  ClipboardCheck,
  ClipboardList,
  FileSpreadsheet,
  FileText,
  GitBranch,
  Home,
  KeyRound,
  LayoutDashboard,
  LockKeyhole,
  Network,
  ShieldCheck,
  TabletSmartphone,
  UserCog,
  Users,
  Workflow,
} from 'lucide-react'

import { text, type Locale } from '../app/copy'
import { pathFromRoute, type AppRoute } from './routes'

/**
 * The navigation registry defines every sidebar entry once. Each entry owns its
 * label, icon, route, and capability policy. Groups are computed by filtering
 * entries against the active principal's capabilities so empty groups never
 * advertise a feature that is withheld.
 *
 * The sidebar renders this registry directly; the dashboard and detail routes
 * use `pathFromRoute` so URLs stay in one place. Adding an entry to
 * `NAVIGATION_ENTRIES` without specifying a policy is a compile error.
 */

export type NavigationGroupKey =
  | 'my-work'
  | 'organization-workforce'
  | 'processes-workflow'
  | 'accounts-access'
  | 'reports-insights'
  | 'internal'

/**
 * Restrict the label pool to copy entries that are plain strings in both
 * locales; copy.ts also contains templated helpers (e.g. `markNotificationRead`)
 * which must not be referenced as sidebar labels.
 */
type StringCopyKeys<T> = { [K in keyof T]: T[K] extends string ? K : never }[keyof T]
export type NavigationLabelKey = StringCopyKeys<(typeof text)['ar']> & StringCopyKeys<(typeof text)['en']>

export type NavigationPolicy =
  | { kind: 'authenticated' }
  | { kind: 'anyOf'; capabilities: readonly string[] }

export type NavigationEntry = {
  key: string
  route: AppRoute
  group: NavigationGroupKey
  labelKey: NavigationLabelKey
  icon: ReactNode
  policy: NavigationPolicy
}

export type UserMenuEntry = {
  key: string
  route: AppRoute
  labelKey: NavigationLabelKey
}

const ICONS = {
  home: <Home aria-hidden="true" />,
  approvals: <ClipboardList aria-hidden="true" />,
  requests: <ClipboardList aria-hidden="true" />,
  tasks: <ClipboardList aria-hidden="true" />,
  procedures: <FileText aria-hidden="true" />,
  documents: <FileText aria-hidden="true" />,
  organization: <Building2 aria-hidden="true" />,
  organizationStructure: <Network aria-hidden="true" />,
  people: <Users aria-hidden="true" />,
  assignments: <FileSpreadsheet aria-hidden="true" />,
  imports: <FileSpreadsheet aria-hidden="true" />,
  workDefinitions: <Workflow aria-hidden="true" />,
  workflowAdmin: <Workflow aria-hidden="true" />,
  procedureReview: <ClipboardCheck aria-hidden="true" />,
  identityAccounts: <Users aria-hidden="true" />,
  roles: <ShieldCheck aria-hidden="true" />,
  roleAssignments: <UserCog aria-hidden="true" />,
  accessScopes: <Network aria-hidden="true" />,
  delegations: <GitBranch aria-hidden="true" />,
  classificationPolicies: <LockKeyhole aria-hidden="true" />,
  supervisory: <Network aria-hidden="true" />,
  accessExplanation: <ClipboardCheck aria-hidden="true" />,
  reports: <BarChart3 aria-hidden="true" />,
  dashboards: <LayoutDashboard aria-hidden="true" />,
  coverage: <TabletSmartphone aria-hidden="true" />,
  apiDocs: <BookOpenText aria-hidden="true" />,
  capabilities: <KeyRound aria-hidden="true" />,
} as const

const anyOf = (capabilities: readonly string[]): NavigationPolicy => ({ kind: 'anyOf', capabilities })

/**
 * One entry per Target Route Map row. Keep this list total so the sidebar never
 * silently omits a governed destination.
 */
export const NAVIGATION_ENTRIES: readonly NavigationEntry[] = [
  // My work
  { key: 'home', route: { name: 'list' }, group: 'my-work', labelKey: 'home', icon: ICONS.home, policy: { kind: 'authenticated' } },
  { key: 'approvals', route: { name: 'approval-inbox' }, group: 'my-work', labelKey: 'myApprovals', icon: ICONS.approvals, policy: anyOf(['workflow.decide', 'workflow.reassign', 'workflow.escalate']) },
  { key: 'my-requests', route: { name: 'my-requests' }, group: 'my-work', labelKey: 'myRequests', icon: ICONS.requests, policy: anyOf(['workflow.read', 'workflow.list']) },
  { key: 'tasks', route: { name: 'tasks' }, group: 'my-work', labelKey: 'myTasks', icon: ICONS.tasks, policy: anyOf(['tasks.read', 'tasks.list']) },
  { key: 'procedures', route: { name: 'procedure-guide' }, group: 'my-work', labelKey: 'procedures', icon: ICONS.procedures, policy: anyOf(['work_definition.read', 'work_definition.list']) },
  { key: 'documents', route: { name: 'documents' }, group: 'my-work', labelKey: 'documents', icon: ICONS.documents, policy: anyOf(['documents.read', 'documents.list']) },

  // Organization and workforce
  { key: 'organization', route: { name: 'organization' }, group: 'organization-workforce', labelKey: 'organizationFacilities', icon: ICONS.organization, policy: anyOf(['organization.facility.read', 'organization.unit.read']) },
  { key: 'people-assignments', route: { name: 'people-assignments' }, group: 'organization-workforce', labelKey: 'peopleAssignments', icon: ICONS.people, policy: anyOf(['organization.person.read']) },
  { key: 'temporary-assignments', route: { name: 'temporary-assignments' }, group: 'organization-workforce', labelKey: 'temporaryAssignments', icon: ICONS.assignments, policy: anyOf(['organization.temporary-assignment.read']) },
  { key: 'organization-import', route: { name: 'organization-import' }, group: 'organization-workforce', labelKey: 'importReview', icon: ICONS.imports, policy: anyOf(['organization.import.read']) },
  { key: 'supervisory', route: { name: 'authorization', resource: 'supervisory' }, group: 'organization-workforce', labelKey: 'supervisoryRelationships', icon: ICONS.supervisory, policy: anyOf(['organization.unit.read']) },

  // Processes and workflow
  { key: 'work-definitions', route: { name: 'work-definitions' }, group: 'processes-workflow', labelKey: 'workDefinitions', icon: ICONS.workDefinitions, policy: anyOf(['work_definition.read', 'work_definition.list']) },
  { key: 'workflow-admin', route: { name: 'workflow-admin' }, group: 'processes-workflow', labelKey: 'workflowAdmin', icon: ICONS.workflowAdmin, policy: anyOf(['workflow.read', 'workflow.list', 'workflow.manage']) },
  { key: 'procedure-office-review', route: { name: 'procedure-office-review' }, group: 'processes-workflow', labelKey: 'procedureOfficeReview', icon: ICONS.procedureReview, policy: anyOf(['workflow.approve', 'work_definition.publish']) },

  // Accounts and access
  { key: 'identity-accounts', route: { name: 'identity-accounts' }, group: 'accounts-access', labelKey: 'identityAccounts', icon: ICONS.identityAccounts, policy: anyOf(['identity.account.read']) },
  { key: 'roles', route: { name: 'authorization', resource: 'roles' }, group: 'accounts-access', labelKey: 'roles', icon: ICONS.roles, policy: anyOf(['authorization.role.read', 'authorization.capability.read']) },
  { key: 'role-assignments', route: { name: 'authorization', resource: 'role-assignments' }, group: 'accounts-access', labelKey: 'roleAssignments', icon: ICONS.roleAssignments, policy: anyOf(['authorization.assignment.read']) },
  { key: 'access-scopes', route: { name: 'access-scopes' }, group: 'accounts-access', labelKey: 'accessScopes', icon: ICONS.accessScopes, policy: anyOf(['authorization.assignment.read']) },
  { key: 'delegations', route: { name: 'authorization', resource: 'delegations' }, group: 'accounts-access', labelKey: 'delegations', icon: ICONS.delegations, policy: anyOf(['authorization.delegation.read']) },
  { key: 'classification-policies', route: { name: 'authorization', resource: 'classification-policies' }, group: 'accounts-access', labelKey: 'classificationPolicies', icon: ICONS.classificationPolicies, policy: anyOf(['authorization.policy.read']) },
  { key: 'field-access-templates', route: { name: 'authorization', resource: 'field-access-templates' }, group: 'accounts-access', labelKey: 'fieldAccessTemplates', icon: ICONS.classificationPolicies, policy: anyOf(['authorization.policy.read']) },

  // Reporting and indicators
  { key: 'reports', route: { name: 'reports' }, group: 'reports-insights', labelKey: 'reportsScreen', icon: ICONS.reports, policy: anyOf(['reporting.list']) },
  { key: 'dashboards', route: { name: 'dashboards' }, group: 'reports-insights', labelKey: 'dashboardsScreen', icon: ICONS.dashboards, policy: anyOf(['reporting.dashboard']) },

  // Internal tools (only platform owners / developers)
  { key: 'access-explanation', route: { name: 'access-explanation' }, group: 'internal', labelKey: 'accessExplanation', icon: ICONS.accessExplanation, policy: anyOf(['authorization.decision.read']) },
  { key: 'coverage', route: { name: 'coverage' }, group: 'internal', labelKey: 'coverage', icon: ICONS.coverage, policy: anyOf(['authorization.audit.read']) },
  { key: 'api-docs', route: { name: 'api-docs' }, group: 'internal', labelKey: 'apiReference', icon: ICONS.apiDocs, policy: anyOf(['authorization.audit.read']) },
]

/**
 * User menu entries (top-right). Only the principal's own screens live here.
 */
export const USER_MENU_ENTRIES: readonly UserMenuEntry[] = [
  { key: 'personal-security', route: { name: 'personal-security' }, labelKey: 'personalSecurity' },
  { key: 'access-context', route: { name: 'access-context' }, labelKey: 'personalAccess' },
]

/**
 * Decide whether an entry should be visible for the given principal. While the
 * principal context is still loading (`capabilities === null`) every gated
 * entry is hidden so the sidebar never advertises what is withheld.
 */
export function isNavigationEntryVisible(
  entry: NavigationEntry,
  capabilities: readonly string[] | null,
): boolean {
  if (entry.policy.kind === 'authenticated') return true
  if (capabilities === null) return false
  return entry.policy.capabilities.some((code) => capabilities.includes(code))
}

export type NavigationGroup = {
  key: NavigationGroupKey
  labelKey: NavigationLabelKey
  icon: ReactElement
  items: Array<{
    key: string
    label: string
    path: string
    icon: ReactNode
  }>
}

const GROUP_LABELS: Record<NavigationGroupKey, NavigationLabelKey> = {
  'my-work': 'myWork',
  'organization-workforce': 'organizationAndWorkforce',
  'processes-workflow': 'processesAndWorkflow',
  'accounts-access': 'accountsAndAccess',
  'reports-insights': 'reportsAndIndicators',
  'internal': 'internalTools',
}

const GROUP_ICONS: Record<NavigationGroupKey, ReactElement> = {
  'my-work': ICONS.home,
  'organization-workforce': ICONS.organization,
  'processes-workflow': ICONS.workflowAdmin,
  'accounts-access': ICONS.roles,
  'reports-insights': ICONS.dashboards,
  'internal': ICONS.coverage,
}

const GROUP_ORDER: readonly NavigationGroupKey[] = [
  'my-work',
  'organization-workforce',
  'processes-workflow',
  'accounts-access',
  'reports-insights',
  'internal',
]

/**
 * Build the sidebar groups from the navigation registry, hiding gated entries
 * and removing any group that becomes empty. Returns a stable order independent
 * of the registry iteration.
 */
export function buildNavigationGroups(args: {
  locale: Locale
  capabilities: readonly string[] | null
}): NavigationGroup[] {
  const visible = NAVIGATION_ENTRIES.filter((entry) => isNavigationEntryVisible(entry, args.capabilities))
  const grouped = new Map<NavigationGroupKey, NavigationGroup>()
  for (const key of GROUP_ORDER) {
    grouped.set(key, { key, labelKey: GROUP_LABELS[key], icon: GROUP_ICONS[key], items: [] })
  }
  for (const entry of visible) {
    const group = grouped.get(entry.group)
    if (!group) continue
    group.items.push({
      key: entry.key,
      label: text[args.locale][entry.labelKey],
      path: pathFromRoute(entry.route),
      icon: entry.icon,
    })
  }
  return GROUP_ORDER
    .map((key) => grouped.get(key))
    .filter((group): group is NavigationGroup => group !== undefined && group.items.length > 0)
}

export function buildUserMenuEntries(locale: Locale): Array<{ key: string; path: string; label: string }> {
  return USER_MENU_ENTRIES.map((entry) => ({
    key: entry.key,
    path: pathFromRoute(entry.route),
    label: text[locale][entry.labelKey],
  }))
}
