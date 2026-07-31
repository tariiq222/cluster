import { describe, expect, it } from 'vitest'
import { routeFromPath, pathFromRoute } from './routes'

describe('routes', () => {
  it('maps home and top-level routes', () => {
    expect(routeFromPath('/')).toEqual({ name: 'home' })
    expect(routeFromPath('/tasks')).toEqual({ name: 'tasks' })
    expect(routeFromPath('/documents')).toEqual({ name: 'documents' })
    expect(routeFromPath('/organization')).toEqual({ name: 'organization' })
    expect(routeFromPath('/accounts-permissions')).toEqual({ name: 'accounts-permissions' })
    expect(routeFromPath('/reports-monitoring')).toEqual({ name: 'reports-monitoring' })
    expect(routeFromPath('/platform-management')).toEqual({ name: 'platform-management' })
    expect(routeFromPath('/notifications')).toEqual({ name: 'notifications' })
    expect(routeFromPath('/audit')).toEqual({ name: 'audit' })
    expect(routeFromPath('/reports')).toEqual({ name: 'reports' })
    expect(routeFromPath('/dashboards')).toEqual({ name: 'dashboards' })
    expect(routeFromPath('/api-docs')).toEqual({ name: 'api-docs' })
  })

  it('maps detail routes only for UUIDv7 ids', () => {
    const valid = '0197f0e0-0000-7000-8000-000000000001'
    expect(routeFromPath(`/tasks/${valid}`)).toEqual({ name: 'task-detail', taskId: valid })
    expect(routeFromPath(`/documents/${valid}`)).toEqual({ name: 'document-detail', documentId: valid })
    expect(routeFromPath('/tasks/not-a-uuid')).toEqual({ name: 'not-found' })
    expect(routeFromPath('/documents/123')).toEqual({ name: 'not-found' })
  })

  it('maps personal routes and not-found', () => {
    expect(routeFromPath('/me/security')).toEqual({ name: 'personal-security' })
    expect(routeFromPath('/me/access')).toEqual({ name: 'access-context' })
    expect(routeFromPath('/unknown')).toEqual({ name: 'not-found' })
  })

  it('round-trips pathFromRoute', () => {
    const valid = '0197f0e0-0000-7000-8000-000000000001'
    expect(routeFromPath(pathFromRoute({ name: 'home' }))).toEqual({ name: 'home' })
    expect(routeFromPath(pathFromRoute({ name: 'task-detail', taskId: valid }))).toEqual({ name: 'task-detail', taskId: valid })
  })
})
