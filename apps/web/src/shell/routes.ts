export type AppRoute =
  | { name: 'list' }
  | { name: 'documents' }
  | { name: 'document-detail'; documentId: string }
  | { name: 'create' }
  | { name: 'detail'; recordId: string }
  | { name: 'organization' }
  | { name: 'organization-structure' }
  | { name: 'people-assignments' }
  | { name: 'temporary-assignments' }
  | { name: 'identity-accounts' }
  | { name: 'organization-import'; jobId?: string }
  | { name: 'authorization'; resource: 'roles' | 'capabilities' | 'role-assignments' | 'delegations' | 'supervisory' }
  | { name: 'authorization'; resource: 'classification-policies' | 'field-access-templates' }
  | { name: 'access-scopes' }
  | { name: 'access-context' }
  | { name: 'personal-security' }
  | { name: 'access-explanation'; decisionId?: string }
  | { name: 'workflow-day2' }
  | { name: 'tasks' }
  | { name: 'task-detail'; taskId: string }
  | { name: 'work-definitions' }
  | { name: 'workflow-admin' }
  | { name: 'procedure-authoring' }
  | { name: 'procedure-office-review' }
  | { name: 'procedure-guide'; procedureId?: string }
  | { name: 'approval-inbox' }
  | { name: 'approval-detail'; stepId: string }
  | { name: 'my-requests' }
  | { name: 'my-request-detail'; instanceId: string }
  | { name: 'new-procedure-request' }
  | { name: 'search' }
  | { name: 'reports' }
  | { name: 'dashboards'; dashboardId?: string }
  | { name: 'coverage' }
  | { name: 'api-docs' }
  | { name: 'notifications' }
  | { name: 'platform-settings'; section: PlatformSettingsSection }
  | { name: 'not-found' }

export const PLATFORM_SETTINGS_SECTIONS = ['overview', 'security', 'calendars', 'backups', 'logs', 'health', 'maintenance'] as const
export type PlatformSettingsSection = (typeof PLATFORM_SETTINGS_SECTIONS)[number]
export const PLATFORM_SETTINGS_OVERVIEW_CAPABILITIES = [
  'platform_settings.read',
  'platform_settings.calendar.read',
  'platform_operations.backup.read',
  'platform_operations.logs.read',
  'platform_operations.health.read',
  'platform_operations.maintenance.manage',
] as const

const UUID_V7_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/**
 * Workspaces group the routes that share one tabbed screen, so the sidebar keeps the
 * group highlighted while the user moves between its tabs.
 *
 * Per the dashboard/navigation redesign, only two screens still own tabs:
 * `/admin/organization` (facilities + structure) and `/admin/authorization/roles`
 * (roles + capabilities). Everything else is a standalone page so the navigation
 * never advertises a tab hierarchy that is not actually rendered.
 *
 * The map is a total `Record` over every route name on purpose: adding a route to
 * `AppRoute` without classifying it here is a compile error rather than a silently
 * broken active-state in the navigation.
 */
export type RouteWorkspace = 'organization' | 'roles-capabilities' | 'platform-settings'

const ROUTE_WORKSPACE: Record<AppRoute['name'], RouteWorkspace | null> = {
  'organization': 'organization',
  'organization-structure': 'organization',
  'people-assignments': null,
  'temporary-assignments': null,
  'organization-import': null,
  'identity-accounts': null,
  'authorization': null,
  'access-scopes': null,
  'access-context': null,
  'access-explanation': null,
  'workflow-day2': null,
  'work-definitions': null,
  'workflow-admin': null,
  'procedure-authoring': null,
  'procedure-office-review': null,
  'procedure-guide': null,
  'approval-inbox': null,
  'approval-detail': null,
  'my-requests': null,
  'my-request-detail': null,
  'new-procedure-request': null,
  'list': null,
  'documents': null,
  'document-detail': null,
  'create': null,
  'detail': null,
  'tasks': null,
  'task-detail': null,
  'search': null,
  'reports': null,
  'dashboards': null,
  'coverage': null,
  'api-docs': null,
  'notifications': null,
  'platform-settings': 'platform-settings',
  'personal-security': null,
  'not-found': null,
}

export function workspaceOfRoute(route: AppRoute): RouteWorkspace | null {
  if (route.name === 'authorization' && (route.resource === 'roles' || route.resource === 'capabilities')) {
    return 'roles-capabilities'
  }
  return ROUTE_WORKSPACE[route.name]
}

/** Whether a navigation entry pointing at `target` should render as the active one. */
export function isRouteActive(current: AppRoute, target: AppRoute): boolean {
  const currentWorkspace = workspaceOfRoute(current)
  const targetWorkspace = workspaceOfRoute(target)
  if (currentWorkspace && targetWorkspace) {
    if (currentWorkspace !== targetWorkspace) return false
    return true
  }
  if (current.name !== target.name) return false
  if (current.name === 'authorization' && target.name === 'authorization') {
    return current.resource === target.resource
  }
  return true
}

export const primaryRoutes = [
  { route: { name: 'list' } as const, path: '/' },
  { route: { name: 'approval-inbox' } as const, path: '/approvals' },
  { route: { name: 'my-requests' } as const, path: '/my-requests' },
  { route: { name: 'tasks' } as const, path: '/tasks' },
  { route: { name: 'procedure-guide' } as const, path: '/procedures' },
  { route: { name: 'documents' } as const, path: '/documents' },
  { route: { name: 'organization' } as const, path: '/admin/organization' },
  { route: { name: 'organization-structure' } as const, path: '/admin/organization/structure' },
  { route: { name: 'people-assignments' } as const, path: '/admin/organization/people' },
  { route: { name: 'temporary-assignments' } as const, path: '/admin/organization/temporary-assignments' },
  { route: { name: 'organization-import' } as const, path: '/admin/imports/organization' },
  { route: { name: 'work-definitions' } as const, path: '/admin/work-definitions' },
  { route: { name: 'workflow-admin' } as const, path: '/admin/workflow' },
  { route: { name: 'procedure-office-review' } as const, path: '/admin/procedures/review' },
  { route: { name: 'identity-accounts' } as const, path: '/admin/identity/accounts' },
  { route: { name: 'authorization', resource: 'roles' } as const, path: '/admin/authorization/roles' },
  { route: { name: 'authorization', resource: 'capabilities' } as const, path: '/admin/authorization/capabilities' },
  { route: { name: 'authorization', resource: 'role-assignments' } as const, path: '/admin/authorization/role-assignments' },
  { route: { name: 'access-scopes' } as const, path: '/admin/authorization/access-scopes' },
  { route: { name: 'authorization', resource: 'delegations' } as const, path: '/admin/authorization/delegations' },
  { route: { name: 'authorization', resource: 'classification-policies' } as const, path: '/admin/authorization/classification-policies' },
  { route: { name: 'authorization', resource: 'field-access-templates' } as const, path: '/admin/authorization/field-access-templates' },
  { route: { name: 'authorization', resource: 'supervisory' } as const, path: '/admin/relationships/supervisory' },
  { route: { name: 'access-explanation' } as const, path: '/admin/authorization/explain' },
  { route: { name: 'reports' } as const, path: '/reports' },
  { route: { name: 'dashboards' } as const, path: '/dashboards' },
  { route: { name: 'coverage' } as const, path: '/coverage' },
  { route: { name: 'api-docs' } as const, path: '/api-docs' },
  { route: { name: 'access-context' } as const, path: '/me/access' },
  { route: { name: 'personal-security' } as const, path: '/me/security' },
  { route: { name: 'create' } as const, path: '/work-records/new' },
  { route: { name: 'workflow-day2' } as const, path: '/admin/workflow/day2' },
  { route: { name: 'procedure-authoring' } as const, path: '/admin/procedures/authoring' },
  { route: { name: 'new-procedure-request' } as const, path: '/procedures/new' },
  { route: { name: 'search' } as const, path: '/search' },
  { route: { name: 'notifications' } as const, path: '/notifications' },
  { route: { name: 'platform-settings', section: 'overview' } as const, path: '/admin/platform' },
] as const

export function pathFromRoute(route: AppRoute): string {
  switch (route.name) {
    case 'list': return '/'
    case 'documents': return '/documents'
    case 'document-detail': return `/documents/${route.documentId}`
    case 'create': return '/work-records/new'
    case 'detail': return `/work-records/${route.recordId}`
    case 'organization': return '/admin/organization'
    case 'organization-structure': return '/admin/organization/structure'
    case 'people-assignments': return '/admin/organization/people'
    case 'temporary-assignments': return '/admin/organization/temporary-assignments'
    case 'identity-accounts': return '/admin/identity/accounts'
    case 'organization-import': return route.jobId ? `/admin/imports/organization/${route.jobId}` : '/admin/imports/organization'
    case 'authorization':
      return route.resource === 'supervisory'
        ? '/admin/relationships/supervisory'
        : `/admin/authorization/${route.resource}`
    case 'access-scopes': return '/admin/authorization/access-scopes'
    case 'access-context': return '/me/access'
    case 'personal-security': return '/me/security'
    case 'access-explanation': return route.decisionId ? `/admin/authorization/explain/${route.decisionId}` : '/admin/authorization/explain'
    case 'workflow-day2': return '/admin/workflow/day2'
    case 'tasks': return '/tasks'
    case 'task-detail': return `/tasks/${route.taskId}`
    case 'work-definitions': return '/admin/work-definitions'
    case 'workflow-admin': return '/admin/workflow'
    case 'procedure-authoring': return '/admin/procedures/authoring'
    case 'procedure-office-review': return '/admin/procedures/review'
    case 'procedure-guide':
      return route.procedureId ? `/procedures/${route.procedureId}` : '/procedures'
    case 'approval-inbox': return '/approvals'
    case 'approval-detail': return `/approvals/${route.stepId}`
    case 'my-requests': return '/my-requests'
    case 'my-request-detail': return `/my-requests/${route.instanceId}`
    case 'new-procedure-request': return '/procedures/new'
    case 'search': return '/search'
    case 'reports': return '/reports'
    case 'dashboards': return route.dashboardId ? `/dashboards/${route.dashboardId}` : '/dashboards'
    case 'coverage': return '/coverage'
    case 'api-docs': return '/api-docs'
    case 'notifications': return '/notifications'
    case 'platform-settings': return route.section === 'overview' ? '/admin/platform' : `/admin/platform/${route.section}`
    case 'not-found': return '/404'
  }
}

export function routeFromPath(pathname: string): AppRoute {
  if (pathname === '/' || pathname === '/work-records') {
    return { name: 'list' }
  }
  if (pathname === '/documents') return { name: 'documents' }
  const documentMatch = pathname.match(/^\/documents\/([^/]+)$/)
  if (documentMatch && UUID_V7_PATTERN.test(documentMatch[1])) return { name: 'document-detail', documentId: documentMatch[1] }
  if (pathname === '/work-records/new') {
    return { name: 'create' }
  }
  if (pathname === '/admin/organization') {
    return { name: 'organization' }
  }
  if (pathname === '/admin/organization/structure') {
    return { name: 'organization-structure' }
  }
  if (pathname === '/admin/organization/people') {
    return { name: 'people-assignments' }
  }
  if (pathname === '/admin/organization/temporary-assignments') {
    return { name: 'temporary-assignments' }
  }
  if (pathname === '/admin/identity/accounts') {
    return { name: 'identity-accounts' }
  }
  if (pathname === '/admin/imports/organization') {
    return { name: 'organization-import' }
  }
  const authorizationMatch = pathname.match(/^\/admin\/authorization\/(roles|capabilities|role-assignments|delegations|classification-policies|field-access-templates|access-scopes)$/)
  if (authorizationMatch) {
    const resource = authorizationMatch[1]
    if (resource === 'access-scopes') return { name: 'access-scopes' }
    return { name: 'authorization', resource: resource as 'roles' | 'capabilities' | 'role-assignments' | 'delegations' | 'classification-policies' | 'field-access-templates' }
  }
  if (pathname === '/admin/relationships/supervisory') return { name: 'authorization', resource: 'supervisory' }
  if (pathname === '/me/access') return { name: 'access-context' }
  if (pathname === '/me/security') return { name: 'personal-security' }
  if (pathname === '/admin/workflow/day2') return { name: 'workflow-day2' }
  if (pathname === '/tasks') return { name: 'tasks' }
  if (pathname === '/admin/work-definitions') return { name: 'work-definitions' }
  if (pathname === '/admin/workflow') return { name: 'workflow-admin' }
  if (pathname === '/admin/procedures/authoring') return { name: 'procedure-authoring' }
  if (pathname === '/admin/procedures/review') return { name: 'procedure-office-review' }
  if (pathname === '/procedures') return { name: 'procedure-guide' }
  if (pathname === '/procedures/submit') return { name: 'procedure-guide', procedureId: 'submit' }
  if (pathname === '/procedures/new') return { name: 'new-procedure-request' }
  const procedureSubmitMatch = pathname.match(/^\/procedures\/([^/]+)\/submit$/)
  if (procedureSubmitMatch) return { name: 'procedure-guide', procedureId: procedureSubmitMatch[1] }
  const procedureMatch = pathname.match(/^\/procedures\/([^/]+)$/)
  if (procedureMatch) return { name: 'procedure-guide', procedureId: procedureMatch[1] }
  if (pathname === '/approvals') return { name: 'approval-inbox' }
  if (pathname === '/my-requests') return { name: 'my-requests' }
  if (pathname === '/search') return { name: 'search' }
  if (pathname === '/reports') return { name: 'reports' }
  if (pathname === '/dashboards') return { name: 'dashboards' }
  if (pathname === '/coverage') return { name: 'coverage' }
  if (pathname === '/api-docs') return { name: 'api-docs' }
  if (pathname === '/notifications') return { name: 'notifications' }
  if (pathname === '/admin/platform') return { name: 'platform-settings', section: 'overview' }
  const platformSettingsMatch = pathname.match(/^\/admin\/platform\/(security|calendars|backups|logs|health|maintenance)$/)
  if (platformSettingsMatch) return { name: 'platform-settings', section: platformSettingsMatch[1] as Exclude<PlatformSettingsSection, 'overview'> }
  const explanationMatch = pathname.match(/^\/admin\/authorization\/explain(?:\/([^/]+))?$/)
  if (explanationMatch) return { name: 'access-explanation', decisionId: explanationMatch[1] }
  const importMatch = pathname.match(/^\/admin\/imports\/organization\/([^/]+)$/)
  if (importMatch && UUID_V7_PATTERN.test(importMatch[1])) {
    return { name: 'organization-import', jobId: importMatch[1] }
  }
  const approvalDetailMatch = pathname.match(/^\/approvals\/([^/]+)$/)
  if (approvalDetailMatch && UUID_V7_PATTERN.test(approvalDetailMatch[1])) {
    return { name: 'approval-detail', stepId: approvalDetailMatch[1] }
  }
  const myRequestDetailMatch = pathname.match(/^\/my-requests\/([^/]+)$/)
  if (myRequestDetailMatch && UUID_V7_PATTERN.test(myRequestDetailMatch[1])) {
    return { name: 'my-request-detail', instanceId: myRequestDetailMatch[1] }
  }
  const taskDetailMatch = pathname.match(/^\/tasks\/([^/]+)$/)
  if (taskDetailMatch && UUID_V7_PATTERN.test(taskDetailMatch[1])) {
    return { name: 'task-detail', taskId: taskDetailMatch[1] }
  }
  const dashboardDetailMatch = pathname.match(/^\/dashboards\/([^/]+)$/)
  if (dashboardDetailMatch) return { name: 'dashboards', dashboardId: dashboardDetailMatch[1] }

  const match = pathname.match(/^\/work-records\/([^/]+)$/)
  if (match && UUID_V7_PATTERN.test(match[1])) {
    return { name: 'detail', recordId: match[1] }
  }

  return { name: 'not-found' }
}

/**
 * The capability code, if any, that gates a route. `null` means the route is
 * open to any authenticated principal; the sidebar uses the value to decide
 * whether to render the entry. The map is exhaustive on purpose: adding a new
 * `AppRoute` without classifying it here is a compile error rather than a
 * silently unclassified sidebar entry.
 */
export function capabilitiesForRoute(route: AppRoute): readonly string[] | null {
  switch (route.name) {
    case 'list':
    case 'access-context':
    case 'personal-security':
    case 'notifications':
    case 'search':
    case 'not-found':
      return null
    case 'documents':
    case 'document-detail':
      return ['documents.read', 'documents.list']
    case 'create':
      return ['work_record.create']
    case 'detail':
      return ['work_record.read']
    case 'organization':
      return ['organization.facility.read', 'organization.unit.read']
    case 'organization-structure':
      return ['organization.unit.read']
    case 'people-assignments':
      return ['organization.person.read']
    case 'temporary-assignments':
      return ['organization.temporary-assignment.read']
    case 'organization-import':
      return ['organization.import.read']
    case 'identity-accounts':
      return ['identity.account.read']
    case 'authorization':
      switch (route.resource) {
        case 'roles':
          return ['authorization.role.read', 'authorization.capability.read']
        case 'capabilities':
          return ['authorization.capability.read']
        case 'role-assignments':
          return ['authorization.assignment.read']
        case 'supervisory':
          return ['organization.unit.read']
        case 'delegations':
          return ['authorization.delegation.read']
        case 'classification-policies':
        case 'field-access-templates':
          return ['authorization.policy.read']
      }
      return null
    case 'access-scopes':
      return ['authorization.assignment.read']
    case 'access-explanation':
      return ['authorization.decision.read']
    case 'workflow-day2':
      return ['workflow.manage']
    case 'work-definitions':
      return ['work_definition.read', 'work_definition.list']
    case 'workflow-admin':
      return ['workflow.read', 'workflow.list', 'workflow.manage']
    case 'procedure-authoring':
      return ['workflow.author']
    case 'procedure-office-review':
      return ['workflow.approve', 'work_definition.publish']
    case 'procedure-guide':
      return ['work_definition.read', 'work_definition.list']
    case 'approval-inbox':
    case 'approval-detail':
      return ['workflow.decide', 'workflow.reassign', 'workflow.escalate']
    case 'my-requests':
    case 'my-request-detail':
      return ['workflow.read', 'workflow.list']
    case 'new-procedure-request':
      return ['workflow.author']
    case 'tasks':
    case 'task-detail':
      return ['tasks.read', 'tasks.list']
    case 'reports':
      return ['reporting.list']
    case 'dashboards':
      return ['reporting.dashboard']
    case 'coverage':
    case 'api-docs':
      return ['authorization.audit.read']
    case 'platform-settings':
      return route.section === 'overview'
        ? PLATFORM_SETTINGS_OVERVIEW_CAPABILITIES
        : route.section === 'security'
          ? ['platform_settings.read']
          : route.section === 'calendars'
            ? ['platform_settings.calendar.read']
            : route.section === 'backups'
              ? ['platform_operations.backup.read']
              : route.section === 'logs'
                ? ['platform_operations.logs.read']
                : route.section === 'health'
                  ? ['platform_operations.health.read']
                  : ['platform_operations.maintenance.manage']
  }
}

/** The first route capability, retained for concise focused assertions. */
export function capabilityForRoute(route: AppRoute): string | null {
  return capabilitiesForRoute(route)?.[0] ?? null
}

/**
 * Decides whether a sidebar entry pointing at `route` should render for a
 * principal with the given capabilities.
 *
 * `null` means the principal context is still loading; gated routes stay
 * hidden until the context resolves so we never advertise what is withheld.
 * An empty array means the principal context resolved with no capabilities;
 * open routes stay visible and gated routes stay hidden.
 */
export function isRouteVisible(
  route: AppRoute,
  capabilities: readonly string[] | null,
): boolean {
  const required = capabilitiesForRoute(route)
  if (required === null) return true
  if (capabilities === null) return false
  return required.some((capability) => capabilities.includes(capability))
}
