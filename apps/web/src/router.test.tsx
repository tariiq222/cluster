// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { routePaths } from './router'

describe('route tree', () => {
  it('registers the consolidated workspaces', () => {
    for (const path of ['/', '/tasks', '/documents', '/organization', '/organization/import',
                        '/access', '/reports', '/platform', '/me', '/search', '/notifications']) {
      expect(routePaths()).toContain(path)
    }
  })

  it('does not register planned workspaces whose API is unimplemented', () => {
    for (const path of ['/governance', '/risk', '/strategy', '/portfolio', '/workflow/operations-office']) {
      expect(routePaths()).not.toContain(path)
    }
  })

  it('does not register the retired per-resource organization routes', () => {
    for (const path of ['/accounts-permissions', '/reports-monitoring', '/platform-management',
                        '/audit', '/dashboards', '/imports', '/me/security', '/me/access']) {
      expect(routePaths()).not.toContain(path)
    }
  })
})
