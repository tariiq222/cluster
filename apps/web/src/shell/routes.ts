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
  | { name: 'access-context' }
  | { name: 'personal-security' }
  | { name: 'access-explanation'; decisionId?: string }
  | { name: 'workflow-day2' }
  | { name: 'tasks' }
  | { name: 'work-definitions' }
  | { name: 'workflow-admin' }
  | { name: 'procedure-authoring' }
  | { name: 'procedure-office-review' }
  | { name: 'procedure-guide'; procedureId?: string }
  | { name: 'approval-inbox' }
  | { name: 'my-requests' }
  | { name: 'new-procedure-request' }
  | { name: 'search' }
  | { name: 'reports' }
  | { name: 'coverage' }
  | { name: 'api-docs' }
  | { name: 'notifications' }
  | { name: 'not-found' }

const UUID_V7_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/**
 * Workspaces group the routes that share one tabbed screen, so the sidebar keeps the
 * group highlighted while the user moves between its tabs.
 *
 * The map is a total `Record` over every route name on purpose: adding a route to
 * `AppRoute` without classifying it here is a compile error rather than a silently
 * broken active-state in the navigation.
 */
export type RouteWorkspace = 'organization' | 'access' | 'workflow'

const ROUTE_WORKSPACE: Record<AppRoute['name'], RouteWorkspace | null> = {
  'organization': 'organization',
  'organization-structure': 'organization',
  'people-assignments': 'organization',
  'temporary-assignments': 'organization',
  'organization-import': 'organization',
  'identity-accounts': 'access',
  'authorization': 'access',
  'access-context': 'access',
  'access-explanation': 'access',
  'workflow-day2': 'workflow',
  'work-definitions': 'workflow',
  'workflow-admin': 'workflow',
  'procedure-authoring': 'workflow',
  'procedure-office-review': 'workflow',
  'procedure-guide': 'workflow',
  'approval-inbox': 'workflow',
  'my-requests': 'workflow',
  'new-procedure-request': 'workflow',
  'list': null,
  'documents': null,
  'document-detail': null,
  'create': null,
  'detail': null,
  'tasks': null,
  'search': null,
  'reports': null,
  'coverage': null,
  'api-docs': null,
  'notifications': null,
  'personal-security': null,
  'not-found': null,
}

export function workspaceOfRoute(route: AppRoute): RouteWorkspace | null {
  return ROUTE_WORKSPACE[route.name]
}

/** Whether a navigation entry pointing at `target` should render as the active one. */
export function isRouteActive(current: AppRoute, target: AppRoute): boolean {
  const currentWorkspace = workspaceOfRoute(current)
  const targetWorkspace = workspaceOfRoute(target)
  if (currentWorkspace && targetWorkspace) return currentWorkspace === targetWorkspace
  if (current.name !== target.name) return false
  if (current.name === 'authorization' && target.name === 'authorization') {
    return current.resource === target.resource
  }
  return true
}

export const primaryRoutes = [
  { route: { name: 'list' } as const, path: '/' },
  { route: { name: 'documents' } as const, path: '/documents' },
  { route: { name: 'create' } as const, path: '/work-records/new' },
  { route: { name: 'organization' } as const, path: '/admin/organization' },
  { route: { name: 'organization-structure' } as const, path: '/admin/organization/structure' },
  { route: { name: 'people-assignments' } as const, path: '/admin/organization/people' },
  { route: { name: 'temporary-assignments' } as const, path: '/admin/organization/temporary-assignments' },
  { route: { name: 'identity-accounts' } as const, path: '/admin/identity/accounts' },
  { route: { name: 'organization-import' } as const, path: '/admin/imports/organization' },
  { route: { name: 'authorization', resource: 'roles' } as const, path: '/admin/authorization/roles' },
  { route: { name: 'authorization', resource: 'capabilities' } as const, path: '/admin/authorization/capabilities' },
  { route: { name: 'authorization', resource: 'role-assignments' } as const, path: '/admin/authorization/role-assignments' },
  { route: { name: 'authorization', resource: 'delegations' } as const, path: '/admin/authorization/delegations' },
  { route: { name: 'authorization', resource: 'classification-policies' } as const, path: '/admin/authorization/classification-policies' },
  { route: { name: 'authorization', resource: 'field-access-templates' } as const, path: '/admin/authorization/field-access-templates' },
  { route: { name: 'authorization', resource: 'supervisory' } as const, path: '/admin/relationships/supervisory' },
  { route: { name: 'access-explanation' } as const, path: '/admin/authorization/explain' },
  { route: { name: 'access-context' } as const, path: '/me/access' },
  { route: { name: 'personal-security' } as const, path: '/me/security' },
  { route: { name: 'workflow-day2' } as const, path: '/admin/workflow/day2' },
  { route: { name: 'tasks' } as const, path: '/tasks' },
  { route: { name: 'work-definitions' } as const, path: '/admin/work-definitions' },
  { route: { name: 'workflow-admin' } as const, path: '/admin/workflow' },
  { route: { name: 'procedure-authoring' } as const, path: '/admin/procedures/authoring' },
  { route: { name: 'procedure-office-review' } as const, path: '/admin/procedures/review' },
  { route: { name: 'procedure-guide' } as const, path: '/procedures' },
  { route: { name: 'approval-inbox' } as const, path: '/approvals' },
  { route: { name: 'my-requests' } as const, path: '/my-requests' },
  { route: { name: 'new-procedure-request' } as const, path: '/procedures/new' },
  { route: { name: 'search' } as const, path: '/search' },
  { route: { name: 'reports' } as const, path: '/reports' },
  { route: { name: 'coverage' } as const, path: '/coverage' },
  { route: { name: 'notifications' } as const, path: '/notifications' },
  { route: { name: 'api-docs' } as const, path: '/api-docs' },
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
    case 'access-context': return '/me/access'
    case 'personal-security': return '/me/security'
    case 'access-explanation': return route.decisionId ? `/admin/authorization/explain/${route.decisionId}` : '/admin/authorization/explain'
    case 'workflow-day2': return '/admin/workflow/day2'
    case 'tasks': return '/tasks'
    case 'work-definitions': return '/admin/work-definitions'
    case 'workflow-admin': return '/admin/workflow'
    case 'procedure-authoring': return '/admin/procedures/authoring'
    case 'procedure-office-review': return '/admin/procedures/review'
    case 'procedure-guide':
      return route.procedureId ? `/procedures/${route.procedureId}` : '/procedures'
    case 'approval-inbox': return '/approvals'
    case 'my-requests': return '/my-requests'
    case 'new-procedure-request': return '/procedures/new'
    case 'search': return '/search'
    case 'reports': return '/reports'
    case 'coverage': return '/coverage'
    case 'api-docs': return '/api-docs'
    case 'notifications': return '/notifications'
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
  const authorizationMatch = pathname.match(/^\/admin\/authorization\/(roles|capabilities|role-assignments|delegations|classification-policies|field-access-templates)$/)
  if (authorizationMatch) return { name: 'authorization', resource: authorizationMatch[1] as 'roles' | 'capabilities' | 'role-assignments' | 'delegations' | 'classification-policies' | 'field-access-templates' }
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
  if (pathname === '/coverage') return { name: 'coverage' }
  if (pathname === '/api-docs') return { name: 'api-docs' }
  if (pathname === '/notifications') return { name: 'notifications' }
  const explanationMatch = pathname.match(/^\/admin\/authorization\/explain(?:\/([^/]+))?$/)
  if (explanationMatch) return { name: 'access-explanation', decisionId: explanationMatch[1] }
  const importMatch = pathname.match(/^\/admin\/imports\/organization\/([^/]+)$/)
  if (importMatch && UUID_V7_PATTERN.test(importMatch[1])) {
    return { name: 'organization-import', jobId: importMatch[1] }
  }

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
export function capabilityForRoute(route: AppRoute): string | null {
  switch (route.name) {
    case 'list':
    case 'documents':
    case 'document-detail':
    case 'create':
    case 'detail':
    case 'access-context':
    case 'personal-security':
    case 'coverage':
    case 'api-docs':
    case 'notifications':
    case 'search':
    case 'not-found':
    case 'procedure-guide':
      return null
    case 'organization':
    case 'organization-structure':
    case 'people-assignments':
    case 'temporary-assignments':
    case 'organization-import':
      return 'organization.unit.read'
    case 'identity-accounts':
      return 'identity.account.read'
    case 'authorization':
      switch (route.resource) {
        case 'roles':
        case 'capabilities':
          return 'authorization.role.read'
        case 'role-assignments':
          return 'authorization.assignment.read'
        case 'delegations':
          return 'authorization.delegation.read'
        case 'classification-policies':
        case 'field-access-templates':
          return 'authorization.policy.read'
        case 'supervisory':
          return 'authorization.assignment.read'
      }
      return null
    case 'access-explanation':
      return 'authorization.decision.read'
    case 'workflow-day2':
    case 'work-definitions':
    case 'workflow-admin':
      return 'work_definition.read'
    case 'procedure-authoring':
      return 'workflow.author'
    case 'procedure-office-review':
      return 'workflow.approve'
    case 'approval-inbox':
    case 'my-requests':
      return 'workflow.read'
    case 'new-procedure-request':
      return 'workflow.author'
    case 'tasks':
      return 'tasks.read'
    case 'reports':
      return 'reporting.list'
  }
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
  const required = capabilityForRoute(route)
  if (required === null) return true
  if (capabilities === null) return false
  return capabilities.includes(required)
}
