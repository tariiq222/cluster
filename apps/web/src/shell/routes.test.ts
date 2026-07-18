import { describe, expect, it } from 'vitest'

import { routeFromPath } from './routes'

describe('W1.2 shell route registry', () => {
  it('resolves direct work-record routes', () => {
    expect(routeFromPath('/')).toEqual({ name: 'list' })
    expect(routeFromPath('/work-records')).toEqual({ name: 'list' })
    expect(routeFromPath('/work-records/new')).toEqual({ name: 'create' })
    expect(routeFromPath('/admin/organization')).toEqual({ name: 'organization' })
    expect(routeFromPath('/admin/organization/structure')).toEqual({ name: 'organization-structure' })
    expect(routeFromPath('/admin/organization/people')).toEqual({ name: 'people-assignments' })
    expect(routeFromPath('/admin/organization/temporary-assignments')).toEqual({ name: 'temporary-assignments' })
    expect(routeFromPath('/admin/identity/accounts')).toEqual({ name: 'identity-accounts' })
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

  it('resolves W1.3 authorization routes and explanation deep links', () => {
    expect(routeFromPath('/admin/authorization/roles')).toEqual({ name: 'authorization', resource: 'roles' })
    expect(routeFromPath('/admin/authorization/role-assignments')).toEqual({ name: 'authorization', resource: 'role-assignments' })
    expect(routeFromPath('/admin/relationships/supervisory')).toEqual({ name: 'authorization', resource: 'supervisory' })
    expect(routeFromPath('/admin/authorization/explain/018f6f7d-0c00-7000-8000-000000000107')).toEqual({ name: 'access-explanation', decisionId: '018f6f7d-0c00-7000-8000-000000000107' })
  })
})
