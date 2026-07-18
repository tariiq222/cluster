export type AppRoute =
  | { name: 'list' }
  | { name: 'create' }
  | { name: 'detail'; recordId: string }
  | { name: 'organization' }
  | { name: 'not-found' }

const UUID_V7_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

export const primaryRoutes = [
  { route: { name: 'list' } as const, path: '/' },
  { route: { name: 'create' } as const, path: '/work-records/new' },
  { route: { name: 'organization' } as const, path: '/admin/organization' },
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

  const match = pathname.match(/^\/work-records\/([^/]+)$/)
  if (match && UUID_V7_PATTERN.test(match[1])) {
    return { name: 'detail', recordId: match[1] }
  }

  return { name: 'not-found' }
}
