import { describe, expect, it } from 'vitest'
import type { AppRoute } from './routes'
import {
  capabilitiesForRoute,
  DEFERRED_CAPABILITIES,
  isRouteActive,
  isRouteVisible,
  pathFromRoute,
  routeFromPath,
  workspaceOfRoute,
} from './routes'

describe('W1.2 shell route registry', () => {
  it('round-trips document list and detail routes', () => {
    const documentId = '018f6f7d-0c00-7000-8000-000000000801'
    expect(routeFromPath('/documents')).toEqual({ name: 'documents' })
    expect(routeFromPath(`/documents/${documentId}`)).toEqual({
      name: 'document-detail',
      documentId,
    })
    expect(pathFromRoute({ name: 'document-detail', documentId })).toBe(
      `/documents/${documentId}`,
    )
    expect(routeFromPath('/documents/not-a-uuid')).toEqual({
      name: 'not-found',
    })
  })

  it('resolves direct work-record routes', () => {
    expect(routeFromPath('/')).toEqual({ name: 'list' })
    expect(routeFromPath('/work-records')).toEqual({ name: 'list' })
    expect(routeFromPath('/work-records/new')).toEqual({ name: 'create' })
    expect(routeFromPath('/admin/organization')).toEqual({
      name: 'organization',
    })
    expect(routeFromPath('/admin/organization/structure')).toEqual({
      name: 'organization-structure',
    })
    expect(routeFromPath('/admin/organization/people')).toEqual({
      name: 'people-assignments',
    })
    expect(routeFromPath('/admin/organization/temporary-assignments')).toEqual({
      name: 'temporary-assignments',
    })
    expect(routeFromPath('/admin/identity/accounts')).toEqual({
      name: 'identity-accounts',
    })
    expect(routeFromPath('/me/security')).toEqual({ name: 'personal-security' })
    expect(pathFromRoute({ name: 'personal-security' })).toBe('/me/security')
    expect(routeFromPath('/tasks')).toEqual({ name: 'tasks' })
    expect(routeFromPath('/admin/work-definitions')).toEqual({
      name: 'work-definitions',
    })
    expect(routeFromPath('/admin/workflow')).toEqual({ name: 'workflow-admin' })
    expect(routeFromPath('/search')).toEqual({ name: 'search' })
    expect(routeFromPath('/reports')).toEqual({ name: 'reports' })
    expect(routeFromPath('/coverage')).toEqual({ name: 'coverage' })
    expect(routeFromPath('/notifications')).toEqual({ name: 'notifications' })
    expect(routeFromPath('/api-docs')).toEqual({ name: 'api-docs' })
    expect(routeFromPath('/admin/imports/organization')).toEqual({
      name: 'organization-import',
    })
    expect(
      routeFromPath(
        '/admin/imports/organization/018f6f7d-0c00-7000-8000-000000000107',
      ),
    ).toEqual({
      name: 'organization-import',
      jobId: '018f6f7d-0c00-7000-8000-000000000107',
    })
    expect(
      routeFromPath('/work-records/018f6f7d-0c00-7000-8000-000000000001'),
    ).toEqual({
      name: 'detail',
      recordId: '018f6f7d-0c00-7000-8000-000000000001',
    })
  })

  it('fails closed for unknown or malformed routes', () => {
    expect(routeFromPath('/organization/unknown')).toEqual({
      name: 'not-found',
    })
    expect(routeFromPath('/work-records/not-a-uuid')).toEqual({
      name: 'not-found',
    })
  })

  it('serializes routes back to paths', () => {
    expect(pathFromRoute({ name: 'list' })).toBe('/')
    expect(
      pathFromRoute({
        name: 'detail',
        recordId: '018f6f7d-0c00-7000-8000-000000000001',
      }),
    ).toBe('/work-records/018f6f7d-0c00-7000-8000-000000000001')
    expect(
      pathFromRoute({
        name: 'organization-import',
        jobId: '018f6f7d-0c00-7000-8000-000000000107',
      }),
    ).toBe('/admin/imports/organization/018f6f7d-0c00-7000-8000-000000000107')
    expect(
      pathFromRoute({ name: 'authorization', resource: 'supervisory' }),
    ).toBe('/admin/relationships/supervisory')
    expect(pathFromRoute({ name: 'coverage' })).toBe('/coverage')
    expect(
      pathFromRoute({
        name: 'access-explanation',
        decisionId: '018f6f7d-0c00-7000-8000-000000000107',
      }),
    ).toBe('/admin/authorization/explain/018f6f7d-0c00-7000-8000-000000000107')
  })

  it('resolves procedure lifecycle routes and the guide deep links', () => {
    expect(routeFromPath('/admin/procedures/authoring')).toEqual({
      name: 'procedure-authoring',
    })
    expect(routeFromPath('/admin/procedures/review')).toEqual({
      name: 'procedure-office-review',
    })
    expect(routeFromPath('/procedures')).toEqual({ name: 'procedure-guide' })
    expect(routeFromPath('/procedures/proc-1')).toEqual({
      name: 'procedure-guide',
      procedureId: 'proc-1',
    })
    expect(routeFromPath('/procedures/proc-1/submit')).toEqual({
      name: 'procedure-guide',
      procedureId: 'proc-1',
    })
    expect(pathFromRoute({ name: 'procedure-authoring' })).toBe(
      '/admin/procedures/authoring',
    )
    expect(pathFromRoute({ name: 'procedure-office-review' })).toBe(
      '/admin/procedures/review',
    )
    expect(pathFromRoute({ name: 'procedure-guide' })).toBe('/procedures')
    expect(
      pathFromRoute({ name: 'procedure-guide', procedureId: 'proc-1' }),
    ).toBe('/procedures/proc-1')
    expect(routeFromPath('/approvals')).toEqual({ name: 'approval-inbox' })
    expect(routeFromPath('/my-requests')).toEqual({ name: 'my-requests' })
    expect(routeFromPath('/procedures/new')).toEqual({
      name: 'new-procedure-request',
    })
    expect(pathFromRoute({ name: 'approval-inbox' })).toBe('/approvals')
    expect(pathFromRoute({ name: 'my-requests' })).toBe('/my-requests')
    expect(pathFromRoute({ name: 'new-procedure-request' })).toBe(
      '/procedures/new',
    )
  })

  it('resolves W1.3 authorization routes and explanation deep links', () => {
    expect(routeFromPath('/admin/authorization/roles')).toEqual({
      name: 'authorization',
      resource: 'roles',
    })
    expect(routeFromPath('/admin/authorization/role-assignments')).toEqual({
      name: 'authorization',
      resource: 'role-assignments',
    })
    expect(routeFromPath('/admin/relationships/supervisory')).toEqual({
      name: 'authorization',
      resource: 'supervisory',
    })
    expect(
      routeFromPath('/admin/authorization/classification-policies'),
    ).toEqual({ name: 'authorization', resource: 'classification-policies' })
    expect(
      routeFromPath('/admin/authorization/field-access-templates'),
    ).toEqual({ name: 'authorization', resource: 'field-access-templates' })
    expect(routeFromPath('/me/access')).toEqual({ name: 'access-context' })
    expect(
      routeFromPath(
        '/admin/authorization/explain/018f6f7d-0c00-7000-8000-000000000107',
      ),
    ).toEqual({
      name: 'access-explanation',
      decisionId: '018f6f7d-0c00-7000-8000-000000000107',
    })
  })

  it('groups workspace tabs so the sidebar entry stays active across them', () => {
    // Only the organization screen still owns tabs (facilities + structure).
    expect(workspaceOfRoute({ name: 'organization' })).toBe('organization')
    expect(workspaceOfRoute({ name: 'organization-structure' })).toBe(
      'organization',
    )
    // Everything that previously shared a tab group is now a standalone page.
    expect(workspaceOfRoute({ name: 'people-assignments' })).toBeNull()
    expect(workspaceOfRoute({ name: 'temporary-assignments' })).toBeNull()
    expect(workspaceOfRoute({ name: 'identity-accounts' })).toBeNull()
    expect(workspaceOfRoute({ name: 'access-context' })).toBeNull()
    expect(workspaceOfRoute({ name: 'reports' })).toBeNull()
    expect(workspaceOfRoute({ name: 'work-definitions' })).toBeNull()

    expect(
      isRouteActive(
        { name: 'organization-structure' },
        { name: 'organization' },
      ),
    ).toBe(true)
    expect(
      isRouteActive(
        { name: 'organization' },
        { name: 'organization-structure' },
      ),
    ).toBe(true)
    expect(
      isRouteActive({ name: 'people-assignments' }, { name: 'organization' }),
    ).toBe(false)
    expect(
      isRouteActive({ name: 'work-definitions' }, { name: 'workflow-day2' }),
    ).toBe(false)
    expect(
      isRouteActive({ name: 'people-assignments' }, { name: 'workflow-day2' }),
    ).toBe(false)
  })

  it('keeps the roles/capabilities tab highlight across its two resources only', () => {
    expect(workspaceOfRoute({ name: 'authorization', resource: 'roles' })).toBe(
      'roles-capabilities',
    )
    expect(
      workspaceOfRoute({ name: 'authorization', resource: 'capabilities' }),
    ).toBe('roles-capabilities')
    expect(
      workspaceOfRoute({ name: 'authorization', resource: 'role-assignments' }),
    ).toBeNull()

    expect(
      isRouteActive(
        { name: 'authorization', resource: 'capabilities' },
        { name: 'authorization', resource: 'roles' },
      ),
    ).toBe(true)
    expect(
      isRouteActive(
        { name: 'authorization', resource: 'roles' },
        { name: 'authorization', resource: 'capabilities' },
      ),
    ).toBe(true)
    expect(
      isRouteActive(
        { name: 'authorization', resource: 'role-assignments' },
        { name: 'authorization', resource: 'roles' },
      ),
    ).toBe(false)
    expect(
      isRouteActive(
        { name: 'authorization', resource: 'delegations' },
        { name: 'authorization', resource: 'roles' },
      ),
    ).toBe(false)
  })

  it('round-trips the direct work, administration, and detail routes', () => {
    const stepId = '01980f50-5f0d-7000-8000-000000000101'
    const instanceId = '01980f50-5f0d-7000-8000-000000000102'
    const taskId = '01980f50-5f0d-7000-8000-000000000103'
    const dashboardId = '01980f50-5f0d-7000-8000-000000000104'

    expect(routeFromPath('/approvals')).toEqual({ name: 'approval-inbox' })
    expect(routeFromPath(`/approvals/${stepId}`)).toEqual({
      name: 'approval-detail',
      stepId,
    })
    expect(pathFromRoute({ name: 'approval-detail', stepId })).toBe(
      `/approvals/${stepId}`,
    )

    expect(routeFromPath(`/my-requests/${instanceId}`)).toEqual({
      name: 'my-request-detail',
      instanceId,
    })
    expect(pathFromRoute({ name: 'my-request-detail', instanceId })).toBe(
      `/my-requests/${instanceId}`,
    )

    expect(routeFromPath(`/tasks/${taskId}`)).toEqual({
      name: 'task-detail',
      taskId,
    })
    expect(pathFromRoute({ name: 'task-detail', taskId })).toBe(
      `/tasks/${taskId}`,
    )

    expect(routeFromPath('/dashboards')).toEqual({ name: 'dashboards' })
    expect(routeFromPath(`/dashboards/${dashboardId}`)).toEqual({
      name: 'dashboards',
      dashboardId,
    })
    expect(pathFromRoute({ name: 'dashboards' })).toBe('/dashboards')
    expect(pathFromRoute({ name: 'dashboards', dashboardId })).toBe(
      `/dashboards/${dashboardId}`,
    )

    expect(routeFromPath('/admin/authorization/access-scopes')).toEqual({
      name: 'access-scopes',
    })
    expect(pathFromRoute({ name: 'access-scopes' })).toBe(
      '/admin/authorization/access-scopes',
    )

    expect(routeFromPath('/admin/workflow/day2')).toEqual({
      name: 'workflow-day2',
    })
    expect(routeFromPath('/admin/procedures/review')).toEqual({
      name: 'procedure-office-review',
    })
    expect(routeFromPath('/admin/procedures/authoring')).toEqual({
      name: 'procedure-authoring',
    })
  })

  it('refuses malformed detail ids and keeps detail links inside the page', () => {
    expect(routeFromPath('/approvals/not-a-uuid')).toEqual({
      name: 'not-found',
    })
    expect(routeFromPath('/my-requests/not-a-uuid')).toEqual({
      name: 'not-found',
    })
    expect(routeFromPath('/tasks/not-a-uuid')).toEqual({ name: 'not-found' })
  })

  it('keeps unrelated routes and sibling authorization resources distinct', () => {
    expect(isRouteActive({ name: 'reports' }, { name: 'reports' })).toBe(true)
    expect(isRouteActive({ name: 'reports' }, { name: 'tasks' })).toBe(false)
    expect(
      isRouteActive(
        { name: 'authorization', resource: 'roles' },
        { name: 'authorization', resource: 'roles' },
      ),
    ).toBe(true)
  })

  it('gates search with the exact server catalog capability', () => {
    const route: AppRoute = { name: 'search' }
    expect(capabilitiesForRoute(route)).toEqual(['search.query'])
    expect(isRouteVisible(route, [])).toBe(false)
    expect(isRouteVisible(route, ['search.query'])).toBe(true)
  })

  it('round-trips and gates the Audit workspace with read capability only', () => {
    const route: AppRoute = { name: 'audit' }
    expect(routeFromPath('/audit')).toEqual(route)
    expect(pathFromRoute(route)).toBe('/audit')
    expect(capabilitiesForRoute(route)).toEqual(['audit.event.read'])
    expect(isRouteVisible(route, [])).toBe(false)
    expect(isRouteVisible(route, ['audit.event.read'])).toBe(true)
    expect(
      isRouteVisible(route, ['audit.event.export', 'audit.integrity.verify']),
    ).toBe(false)
  })

  it('keeps platform read visibility separate from mutation capabilities', () => {
    expect(
      capabilitiesForRoute({ name: 'platform-settings', section: 'security' }),
    ).toEqual(['platform_settings.read'])
    expect(
      capabilitiesForRoute({ name: 'platform-settings', section: 'backups' }),
    ).toEqual(['platform_operations.backup.read'])
    expect(
      capabilitiesForRoute({ name: 'platform-settings', section: 'health' }),
    ).toEqual(['platform_operations.health.read'])
    expect(
      capabilitiesForRoute({ name: 'platform-settings', section: 'overview' }),
    ).not.toContain('platform_operations.maintenance.manage')
  })

  it('hides routes whose capabilities are deferred even if the principal holds them', () => {
    const route: AppRoute = { name: 'platform-settings', section: 'logs' }
    expect(capabilitiesForRoute(route)).toEqual([])
    expect(
      isRouteVisible(route, [
        'platform_operations.logs.read',
        'platform_operations.logs.restore',
      ]),
    ).toBe(false)
    expect(DEFERRED_CAPABILITIES['platform_operations.logs.read']).toBe(true)
    expect(DEFERRED_CAPABILITIES['platform_operations.logs.restore']).toBe(true)
  })
})
