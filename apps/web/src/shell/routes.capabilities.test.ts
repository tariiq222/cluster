import { describe, expect, it } from 'vitest'

import {
  capabilityForRoute,
  isRouteVisible,
  primaryRoutes,
  type AppRoute,
} from './routes'

const ENABLED = { work_management: true, tasks: true }
const visible = (route: AppRoute, capabilities: readonly string[] | null) =>
  isRouteVisible(route, capabilities, ENABLED)

describe('navigation capability gating', () => {
  it('offers the screens that carry no capability of their own to anyone', () => {
    const open: AppRoute[] = [
      { name: 'list' },
      { name: 'access-context' },
      { name: 'notifications' },
    ]
    for (const route of open) {
      expect(capabilityForRoute(route)).toBeNull()
      expect(isRouteVisible(route, [])).toBe(true)
      expect(isRouteVisible(route, null)).toBe(true)
    }
  })

  it('fails closed for direct internal and dashboard URLs until their own capability is resolved', () => {
    expect(capabilityForRoute({ name: 'coverage' })).toBe(
      'authorization.audit.read',
    )
    expect(capabilityForRoute({ name: 'api-docs' })).toBe(
      'authorization.audit.read',
    )
    expect(capabilityForRoute({ name: 'dashboards' })).toBe(
      'reporting.dashboard',
    )
    expect(capabilityForRoute({ name: 'access-explanation' })).toBe(
      'authorization.decision.read',
    )

    for (const route of [
      { name: 'coverage' },
      { name: 'api-docs' },
      { name: 'dashboards' },
      { name: 'access-explanation' },
    ] as const) {
      expect(isRouteVisible(route, null)).toBe(false)
      expect(isRouteVisible(route, [])).toBe(false)
    }
    expect(
      isRouteVisible({ name: 'coverage' }, ['authorization.audit.read']),
    ).toBe(true)
    expect(
      isRouteVisible({ name: 'dashboards' }, ['reporting.dashboard']),
    ).toBe(true)
    expect(
      isRouteVisible({ name: 'access-explanation' }, [
        'authorization.decision.read',
      ]),
    ).toBe(true)
  })

  it('withholds a gated screen from a principal that does not hold its capability', () => {
    const accounts: AppRoute = { name: 'identity-accounts' }
    expect(isRouteVisible(accounts, ['work_record.read', 'tasks.read'])).toBe(
      false,
    )
    expect(isRouteVisible(accounts, ['identity.account.read'])).toBe(true)
  })

  it('withholds every gated screen while the principal context is still loading', () => {
    expect(isRouteVisible({ name: 'reports' }, null)).toBe(false)
    expect(isRouteVisible({ name: 'organization' }, null)).toBe(false)
    expect(isRouteVisible({ name: 'reports' }, ['reporting.list'])).toBe(true)
  })

  it('gates the procedure routes on their navigation-target capabilities', () => {
    expect(capabilityForRoute({ name: 'procedure-authoring' })).toBe(
      'workflow.author',
    )
    expect(capabilityForRoute({ name: 'procedure-office-review' })).toBe(
      'workflow.approve',
    )
    expect(
      visible({ name: 'procedure-authoring' }, ['workflow.author']),
    ).toBe(true)
    expect(
      visible({ name: 'procedure-authoring' }, ['workflow.approve']),
    ).toBe(false)
    expect(
      visible({ name: 'procedure-office-review' }, ['workflow.approve']),
    ).toBe(true)
    expect(
      visible({ name: 'procedure-office-review' }, ['workflow.author']),
    ).toBe(false)
    expect(visible({ name: 'procedure-guide' }, [])).toBe(false)
    expect(visible({ name: 'procedure-guide' }, null)).toBe(false)
    expect(
      visible({ name: 'procedure-guide' }, ['work_definition.list']),
    ).toBe(true)
  })

  it('gates Stage 4 request routes on workflow capabilities', () => {
    expect(capabilityForRoute({ name: 'approval-inbox' })).toBe(
      'workflow.decide',
    )
    expect(
      capabilityForRoute({
        name: 'approval-detail',
        stepId: '01980f50-5f0d-7000-8000-000000000101',
      }),
    ).toBe('workflow.decide')
    expect(capabilityForRoute({ name: 'my-requests' })).toBe('workflow.read')
    expect(
      capabilityForRoute({
        name: 'my-request-detail',
        instanceId: '01980f50-5f0d-7000-8000-000000000102',
      }),
    ).toBe('workflow.read')
    expect(capabilityForRoute({ name: 'new-procedure-request' })).toBe(
      'workflow.author',
    )

    expect(
      visible({ name: 'approval-inbox' }, ['workflow.decide']),
    ).toBe(true)
    expect(visible({ name: 'approval-inbox' }, ['workflow.read'])).toBe(
      false,
    )
    expect(
      visible(
        {
          name: 'approval-detail',
          stepId: '01980f50-5f0d-7000-8000-000000000101',
        },
        ['workflow.decide'],
      ),
    ).toBe(true)
    expect(visible({ name: 'my-requests' }, ['workflow.read'])).toBe(
      true,
    )
    expect(
      visible(
        {
          name: 'my-request-detail',
          instanceId: '01980f50-5f0d-7000-8000-000000000102',
        },
        ['workflow.read'],
      ),
    ).toBe(true)
    expect(
      visible({ name: 'new-procedure-request' }, ['workflow.author']),
    ).toBe(true)
    expect(
      visible({ name: 'new-procedure-request' }, ['workflow.read']),
    ).toBe(false)
  })

  it('keeps the legacy day-two workflow screen behind workflow management', () => {
    expect(capabilityForRoute({ name: 'workflow-day2' })).toBe(
      'workflow.manage',
    )
    expect(
      isRouteVisible({ name: 'workflow-day2' }, ['work_definition.read']),
    ).toBe(false)
    expect(isRouteVisible({ name: 'workflow-day2' }, ['workflow.manage'])).toBe(
      true,
    )
  })

  it('gates each authorization tab on its own resource capability', () => {
    expect(
      capabilityForRoute({ name: 'authorization', resource: 'roles' }),
    ).toBe('authorization.role.read')
    expect(
      capabilityForRoute({ name: 'authorization', resource: 'capabilities' }),
    ).toBe('authorization.capability.read')
    expect(
      capabilityForRoute({ name: 'authorization', resource: 'delegations' }),
    ).toBe('authorization.delegation.read')
    expect(
      capabilityForRoute({
        name: 'authorization',
        resource: 'field-access-templates',
      }),
    ).toBe('authorization.policy.read')

    const holdsRolesOnly = ['authorization.role.read']
    expect(
      isRouteVisible(
        { name: 'authorization', resource: 'roles' },
        holdsRolesOnly,
      ),
    ).toBe(true)
    expect(
      isRouteVisible(
        { name: 'authorization', resource: 'capabilities' },
        holdsRolesOnly,
      ),
    ).toBe(false)
    expect(
      isRouteVisible({ name: 'authorization', resource: 'capabilities' }, [
        'authorization.capability.read',
      ]),
    ).toBe(true)
    expect(
      isRouteVisible(
        { name: 'authorization', resource: 'delegations' },
        holdsRolesOnly,
      ),
    ).toBe(false)
  })

  it('classifies every navigable route, so no entry reaches the sidebar unclassified', () => {
    for (const { route } of primaryRoutes) {
      expect(() => capabilityForRoute(route)).not.toThrow()
      const capability = capabilityForRoute(route)
      expect(capability === null || capability.length > 0).toBe(true)
    }
  })

  it('names capabilities in the module.resource.action shape the catalog uses', () => {
    for (const { route } of primaryRoutes) {
      const capability = capabilityForRoute(route)
      if (capability === null) continue
      expect(capability).toMatch(/^[a-z][a-z0-9_]*(\.[a-z0-9_-]+)+$/)
    }
  })

  it('hides work-management routes when the work_management feature is disabled', () => {
    const disabled = { work_management: false, tasks: true }
    const workManagementRoutes: AppRoute[] = [
      { name: 'approval-inbox' },
      { name: 'my-requests' },
      { name: 'work-definitions' },
      { name: 'workflow-admin' },
      { name: 'procedure-guide' },
      { name: 'procedure-authoring' },
      { name: 'procedure-office-review' },
      { name: 'new-procedure-request' },
      { name: 'create' },
      { name: 'detail', recordId: '01980f50-5f0d-7000-8000-000000000001' },
    ]
    // Capability check passes — the gate is what blocks these routes while disabled.
    const allCapabilities = ['workflow.decide', 'workflow.author', 'workflow.approve', 'workflow.read', 'workflow.list', 'workflow.reassign', 'workflow.escalate', 'work_record.create', 'work_record.read', 'work_definition.read', 'work_definition.list']
    for (const route of workManagementRoutes) {
      expect(isRouteVisible(route, allCapabilities, disabled)).toBe(false)
      expect(isRouteVisible(route, allCapabilities, null)).toBe(false)
      expect(isRouteVisible(route, allCapabilities, { work_management: true, tasks: true })).toBe(true)
    }
  })

  it('keeps tasks and documents routes visible regardless of work_management', () => {
    const disabled = { work_management: false, tasks: true }
    expect(isRouteVisible({ name: 'tasks' }, ['tasks.read'], disabled)).toBe(true)
    expect(isRouteVisible({ name: 'task-detail', taskId: '01980f50-5f0d-7000-8000-000000000103' }, ['tasks.read'], disabled)).toBe(true)
    expect(isRouteVisible({ name: 'documents' }, ['documents.read'], disabled)).toBe(true)
    expect(isRouteVisible({ name: 'list' }, [], disabled)).toBe(true)
  })
})
