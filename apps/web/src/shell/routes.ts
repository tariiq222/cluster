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
  | { name: 'access-explanation'; decisionId?: string }
  | { name: 'workflow-day2' }
  | { name: 'tasks' }
  | { name: 'work-definitions' }
  | { name: 'workflow-admin' }
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
  { route: { name: 'workflow-day2' } as const, path: '/admin/workflow/day2' },
  { route: { name: 'tasks' } as const, path: '/tasks' },
  { route: { name: 'work-definitions' } as const, path: '/admin/work-definitions' },
  { route: { name: 'workflow-admin' } as const, path: '/admin/workflow' },
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
    case 'access-explanation': return route.decisionId ? `/admin/authorization/explain/${route.decisionId}` : '/admin/authorization/explain'
    case 'workflow-day2': return '/admin/workflow/day2'
    case 'tasks': return '/tasks'
    case 'work-definitions': return '/admin/work-definitions'
    case 'workflow-admin': return '/admin/workflow'
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
  if (pathname === '/admin/workflow/day2') return { name: 'workflow-day2' }
  if (pathname === '/tasks') return { name: 'tasks' }
  if (pathname === '/admin/work-definitions') return { name: 'work-definitions' }
  if (pathname === '/admin/workflow') return { name: 'workflow-admin' }
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
