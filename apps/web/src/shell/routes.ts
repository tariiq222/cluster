export type AppRoute =
  | { name: 'list' }
  | { name: 'create' }
  | { name: 'detail'; recordId: string }
  | { name: 'organization' }
  | { name: 'organization-structure' }
  | { name: 'people-assignments' }
  | { name: 'temporary-assignments' }
  | { name: 'identity-accounts' }
  | { name: 'organization-import'; jobId?: string }
  | { name: 'not-found' }

const UUID_V7_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

export const primaryRoutes = [
  { route: { name: 'list' } as const, path: '/' },
  { route: { name: 'create' } as const, path: '/work-records/new' },
  { route: { name: 'organization' } as const, path: '/admin/organization' },
  { route: { name: 'organization-structure' } as const, path: '/admin/organization/structure' },
  { route: { name: 'people-assignments' } as const, path: '/admin/organization/people' },
  { route: { name: 'temporary-assignments' } as const, path: '/admin/organization/temporary-assignments' },
  { route: { name: 'identity-accounts' } as const, path: '/admin/identity/accounts' },
  { route: { name: 'organization-import' } as const, path: '/admin/imports/organization' },
]

export function routeFromPath(pathname: string): AppRoute {
  if (pathname === '/' || pathname === '/work-records') {
    return { name: 'list' }
  }
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
