export type RouteName =
  | 'home'
  | 'tasks'
  | 'task-detail'
  | 'task-create'
  | 'documents'
  | 'document-detail'
  | 'organization'
  | 'accounts-permissions'
  | 'reports-monitoring'
  | 'platform-management'
  | 'notifications'
  | 'search'
  | 'personal-security'
  | 'access-context'
  | 'audit'
  | 'reports'
  | 'dashboards'
  | 'api-docs'
  | 'not-found'

export type AppRoute =
  | { name: 'home' }
  | { name: 'tasks' }
  | { name: 'task-detail'; taskId: string }
  | { name: 'task-create' }
  | { name: 'documents' }
  | { name: 'document-detail'; documentId: string }
  | { name: 'organization' }
  | { name: 'accounts-permissions' }
  | { name: 'reports-monitoring' }
  | { name: 'platform-management' }
  | { name: 'notifications' }
  | { name: 'search'; query?: string }
  | { name: 'personal-security' }
  | { name: 'access-context' }
  | { name: 'audit' }
  | { name: 'reports' }
  | { name: 'dashboards' }
  | { name: 'api-docs' }
  | { name: 'not-found' }

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

export function pathFromRoute(route: AppRoute): string {
  switch (route.name) {
    case 'home': return '/'
    case 'tasks': return '/tasks'
    case 'task-detail': return `/tasks/${route.taskId}`
    case 'task-create': return '/tasks/new'
    case 'documents': return '/documents'
    case 'document-detail': return `/documents/${route.documentId}`
    case 'organization': return '/organization'
    case 'accounts-permissions': return '/accounts-permissions'
    case 'reports-monitoring': return '/reports-monitoring'
    case 'platform-management': return '/platform-management'
    case 'notifications': return '/notifications'
    case 'search': return route.query ? `/search?q=${encodeURIComponent(route.query)}` : '/search'
    case 'personal-security': return '/me/security'
    case 'access-context': return '/me/access'
    case 'audit': return '/audit'
    case 'reports': return '/reports'
    case 'dashboards': return '/dashboards'
    case 'api-docs': return '/api-docs'
    case 'not-found': return '/not-found'
  }
}

export function routeFromPath(pathname: string): AppRoute {
  const segments = pathname.split('/').filter(Boolean)
  if (segments.length === 0) return { name: 'home' }
  switch (segments[0]) {
    case 'tasks':
      if (segments.length === 1) return { name: 'tasks' }
      if (segments[1] === 'new') return { name: 'task-create' }
      return segments[1] && UUID_V7.test(segments[1]) ? { name: 'task-detail', taskId: segments[1] } : { name: 'not-found' }
    case 'documents':
      if (segments.length === 1) return { name: 'documents' }
      return segments[1] && UUID_V7.test(segments[1]) ? { name: 'document-detail', documentId: segments[1] } : { name: 'not-found' }
    case 'organization': return { name: 'organization' }
    case 'accounts-permissions': return { name: 'accounts-permissions' }
    case 'reports-monitoring': return { name: 'reports-monitoring' }
    case 'platform-management': return { name: 'platform-management' }
    case 'notifications': return { name: 'notifications' }
    case 'search': return { name: 'search' }
    case 'me':
      if (segments[1] === 'security') return { name: 'personal-security' }
      if (segments[1] === 'access') return { name: 'access-context' }
      return { name: 'not-found' }
    case 'audit': return { name: 'audit' }
    case 'reports': return { name: 'reports' }
    case 'dashboards': return { name: 'dashboards' }
    case 'api-docs': return { name: 'api-docs' }
    default: return { name: 'not-found' }
  }
}
