// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, within } from '@testing-library/react'

import { ProcessWorkspace } from './ProcessWorkspace'

vi.mock('./Day2Workflow', () => ({
  Day2Workflow: () => <div>Day 2 compatibility view</div>,
}))

vi.mock('../r1/R1Screens', () => ({
  TasksScreen: () => <div>Tasks screen</div>,
  WorkDefinitionsScreen: () => <div>Request types screen</div>,
  WorkflowAdminScreen: () => <div>Approval paths screen</div>,
}))

vi.mock('./RequestListScreens', () => ({
  MyRequestsScreen: () => <div>My requests screen</div>,
  RequestManagementScreen: () => <div>Request management screen</div>,
}))

const session = {
  access_token: 'test-token',
  csrf_token: 'test-token',
  user_id: 'user-1',
  expires_at: '2026-07-22T00:00:00.000Z',
  restricted: false,
  principal: { user_id: 'user-1' },
  user: { id: 'user-1', username: 'test-user' },
}

describe('ProcessWorkspace', () => {
  it('renders the workspace navigation with the three stable sections and renders the active screen', () => {
    render(
      <ProcessWorkspace
        locale="ar"
        session={session}
        activeRouteName="workflow-day2"
        navigate={vi.fn()}
      />,
    )

    const navigation = screen.getByRole('navigation', { name: 'أقسام الإجراءات وسير العمل' })
    const links = within(navigation).getAllByRole('link')

    expect(links).toHaveLength(3)
    const first = links[0]
    const second = links[1]
    const third = links[2]
    if (!first || !second || !third) {
      throw new Error('ProcessWorkspace navigation should expose exactly three stable links')
    }
    expect(first.getAttribute('href')).toBe('/admin/workflow/day2')
    expect(second.getAttribute('href')).toBe('/admin/work-definitions')
    expect(third.getAttribute('href')).toBe('/admin/workflow')
    expect(screen.getByText('Day 2 compatibility view')).toBeTruthy()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })
})
