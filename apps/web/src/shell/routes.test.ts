import { describe, expect, it } from 'vitest'

import { isRouteActive, pathFromRoute, routeFromPath, workspaceOfRoute } from './routes'

describe('W1.2 shell route registry', () => {
  it('round-trips document list and detail routes', () => {
    const documentId = '018f6f7d-0c00-7000-8000-000000000801'
    expect(routeFromPath('/documents')).toEqual({ name: 'documents' })
    expect(routeFromPath(`/documents/${documentId}`)).toEqual({ name: 'document-detail', documentId })
    expect(pathFromRoute({ name: 'document-detail', documentId })).toBe(`/documents/${documentId}`)
    expect(routeFromPath('/documents/not-a-uuid')).toEqual({ name: 'not-found' })
  })

  it('resolves direct work-record routes', () => {
    expect(routeFromPath('/')).toEqual({ name: 'list' })
    expect(routeFromPath('/work-records')).toEqual({ name: 'list' })
    expect(routeFromPath('/work-records/new')).toEqual({ name: 'create' })
    expect(routeFromPath('/admin/organization')).toEqual({ name: 'organization' })
    expect(routeFromPath('/admin/organization/structure')).toEqual({ name: 'organization-structure' })
    expect(routeFromPath('/admin/organization/people')).toEqual({ name: 'people-assignments' })
    expect(routeFromPath('/admin/organization/temporary-assignments')).toEqual({ name: 'temporary-assignments' })
    expect(routeFromPath('/admin/identity/accounts')).toEqual({ name: 'identity-accounts' })
    expect(routeFromPath('/me/security')).toEqual({ name: 'personal-security' })
    expect(pathFromRoute({ name: 'personal-security' })).toBe('/me/security')
    expect(routeFromPath('/tasks')).toEqual({ name: 'tasks' })
    expect(routeFromPath('/admin/work-definitions')).toEqual({ name: 'work-definitions' })
    expect(routeFromPath('/admin/workflow')).toEqual({ name: 'workflow-admin' })
    expect(routeFromPath('/search')).toEqual({ name: 'search' })
    expect(routeFromPath('/reports')).toEqual({ name: 'reports' })
    expect(routeFromPath('/coverage')).toEqual({ name: 'coverage' })
    expect(routeFromPath('/notifications')).toEqual({ name: 'notifications' })
    expect(routeFromPath('/api-docs')).toEqual({ name: 'api-docs' })
    expect(routeFromPath('/admin/imports/organization')).toEqual({ name: 'organization-import' })
    expect(routeFromPath('/admin/imports/organization/018f6f7d-0c00-7000-8000-000000000107')).toEqual({
      name: 'organization-import', jobId: '018f6f7d-0c00-7000-8000-000000000107',
    })
    expect(routeFromPath('/work-records/018f6f7d-0c00-7000-8000-000000000001')).toEqual({
      name: 'detail',
      recordId: '018f6f7d-0c00-7000-8000-000000000001',
    })
  })

  it('fails closed for unknown or malformed routes', () => {
    expect(routeFromPath('/organization/unknown')).toEqual({ name: 'not-found' })
    expect(routeFromPath('/work-records/not-a-uuid')).toEqual({ name: 'not-found' })
  })

  it('serializes routes back to paths', () => {
    expect(pathFromRoute({ name: 'list' })).toBe('/')
    expect(pathFromRoute({ name: 'detail', recordId: '018f6f7d-0c00-7000-8000-000000000001' })).toBe('/work-records/018f6f7d-0c00-7000-8000-000000000001')
    expect(pathFromRoute({ name: 'organization-import', jobId: '018f6f7d-0c00-7000-8000-000000000107' })).toBe('/admin/imports/organization/018f6f7d-0c00-7000-8000-000000000107')
    expect(pathFromRoute({ name: 'authorization', resource: 'supervisory' })).toBe('/admin/relationships/supervisory')
    expect(pathFromRoute({ name: 'coverage' })).toBe('/coverage')
    expect(pathFromRoute({ name: 'access-explanation', decisionId: '018f6f7d-0c00-7000-8000-000000000107' })).toBe('/admin/authorization/explain/018f6f7d-0c00-7000-8000-000000000107')
  })

  it('resolves procedure lifecycle routes and the guide deep links', () => {
    expect(routeFromPath('/admin/procedures/authoring')).toEqual({ name: 'procedure-authoring' })
    expect(routeFromPath('/admin/procedures/review')).toEqual({ name: 'procedure-office-review' })
    expect(routeFromPath('/procedures')).toEqual({ name: 'procedure-guide' })
    expect(routeFromPath('/procedures/proc-1')).toEqual({ name: 'procedure-guide', procedureId: 'proc-1' })
    expect(routeFromPath('/procedures/proc-1/submit')).toEqual({ name: 'procedure-guide', procedureId: 'proc-1' })
    expect(pathFromRoute({ name: 'procedure-authoring' })).toBe('/admin/procedures/authoring')
    expect(pathFromRoute({ name: 'procedure-office-review' })).toBe('/admin/procedures/review')
    expect(pathFromRoute({ name: 'procedure-guide' })).toBe('/procedures')
     expect(pathFromRoute({ name: 'procedure-guide', procedureId: 'proc-1' })).toBe('/procedures/proc-1')
     expect(routeFromPath('/approvals')).toEqual({ name: 'approval-inbox' })
     expect(routeFromPath('/my-requests')).toEqual({ name: 'my-requests' })
     expect(routeFromPath('/procedures/new')).toEqual({ name: 'new-procedure-request' })
     expect(pathFromRoute({ name: 'approval-inbox' })).toBe('/approvals')
     expect(pathFromRoute({ name: 'my-requests' })).toBe('/my-requests')
     expect(pathFromRoute({ name: 'new-procedure-request' })).toBe('/procedures/new')

  })

  it('resolves W1.3 authorization routes and explanation deep links', () => {
    expect(routeFromPath('/admin/authorization/roles')).toEqual({ name: 'authorization', resource: 'roles' })
    expect(routeFromPath('/admin/authorization/role-assignments')).toEqual({ name: 'authorization', resource: 'role-assignments' })
    expect(routeFromPath('/admin/relationships/supervisory')).toEqual({ name: 'authorization', resource: 'supervisory' })
    expect(routeFromPath('/admin/authorization/classification-policies')).toEqual({ name: 'authorization', resource: 'classification-policies' })
    expect(routeFromPath('/admin/authorization/field-access-templates')).toEqual({ name: 'authorization', resource: 'field-access-templates' })
    expect(routeFromPath('/me/access')).toEqual({ name: 'access-context' })
    expect(routeFromPath('/admin/authorization/explain/018f6f7d-0c00-7000-8000-000000000107')).toEqual({ name: 'access-explanation', decisionId: '018f6f7d-0c00-7000-8000-000000000107' })
  })

  it('groups workspace tabs so the sidebar entry stays active across them', () => {
    expect(workspaceOfRoute({ name: 'people-assignments' })).toBe('organization')
    expect(workspaceOfRoute({ name: 'access-context' })).toBe('access')
    expect(workspaceOfRoute({ name: 'reports' })).toBeNull()

    expect(isRouteActive({ name: 'temporary-assignments' }, { name: 'organization' })).toBe(true)
    expect(isRouteActive({ name: 'work-definitions' }, { name: 'workflow-day2' })).toBe(true)
    expect(isRouteActive({ name: 'people-assignments' }, { name: 'workflow-day2' })).toBe(false)
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
})
