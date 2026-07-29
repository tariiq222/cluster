import { describe, expect, it } from 'vitest'
import { authorizationTransitionForStatus, canMutateAuthorizationResource, mapAuthorizationRows, parsePolicyDocument, resourceCreateType, validateAdminForm } from './AuthorizationAdmin'

describe('AuthorizationAdmin pure helpers', () => {
  it('maps server rows without inventing authorization policy', () => {
    expect(mapAuthorizationRows([{ id: 'r1', code: 'records.read', name: 'Read', status: 'active', lock_version: 3 }])).toEqual([
      { id: 'r1', code: 'records.read', name: 'Read', status: 'active', lockVersion: 3 },
    ])
  })

  it('maps only server-supported creation resource types', () => {
    expect(resourceCreateType('role-assignments')).toBe('role_assignment')
    expect(resourceCreateType('field-access-templates')).toBe('field_access_template')
  })

  it('validates required code and JSON policy documents', () => {
    expect(validateAdminForm({ name: 'Missing code' })).toBe('code')
    expect(validateAdminForm({ code: 'role', policyDocument: '{bad' })).toBe('policy')
    expect(validateAdminForm({ code: 'role', name: 'Role', policyDocument: '{"effect":"deny"}' })).toBeNull()
    expect(parsePolicyDocument('{"effect":"deny"}')).toEqual({ effect: 'deny' })
  })

  it('maps governed role-assignment status changes to reasoned transitions', () => {
    expect(authorizationTransitionForStatus('role-assignments', 'active', 'revoked')).toBe('revoke')
    expect(authorizationTransitionForStatus('role-assignments', 'active', 'expired')).toBe('expire')
    expect(authorizationTransitionForStatus('roles', 'active', 'revoked')).toBeNull()
    expect(authorizationTransitionForStatus('role-assignments', 'active', 'inactive')).toBeNull()
  })
  it('requires the assignment manage capability to mutate role assignments and nothing else', () => {
    expect(canMutateAuthorizationResource('role-assignments', ['authorization.assignment.read'])).toBe(false)
    expect(canMutateAuthorizationResource('role-assignments', ['authorization.assignment.manage'])).toBe(true)
    expect(canMutateAuthorizationResource('classification-policies', ['authorization.policy.manage'])).toBe(false)
    expect(canMutateAuthorizationResource('supervisory', ['organization.unit.read'])).toBe(false)
  })

})
