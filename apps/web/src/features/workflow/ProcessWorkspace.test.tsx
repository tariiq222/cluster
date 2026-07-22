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
  expires_at: '2026-07-22T00:00:00.000Z',
  user: { id: 'user-1', username: 'test-user' },
}

describe('ProcessWorkspace', () => {
  it('renders the five stable workflow destinations without promoting the day 2 compatibility route', () => {
    render(
      <ProcessWorkspace
        locale="ar"
        session={session}
        activeRouteName="tasks"
        navigate={vi.fn()}
      />,
    )

    const navigation = screen.getByRole('navigation', { name: 'أقسام سير العمل والطلبات' })
    const links = within(navigation).getAllByRole('link')

    expect(links).toHaveLength(5)
    expect(screen.getByRole('link', { name: 'طلباتي' })).toHaveAttribute('href', '/requests')
    expect(screen.getByRole('link', { name: 'بانتظار إجراء مني' })).toHaveAttribute('href', '/tasks')
    expect(screen.getByRole('link', { name: 'إدارة الطلبات' })).toHaveAttribute('href', '/admin/requests')
    expect(screen.getByRole('link', { name: 'أنواع الطلبات' })).toHaveAttribute('href', '/admin/work-definitions')
    expect(screen.getByRole('link', { name: 'مسارات الموافقة' })).toHaveAttribute('href', '/admin/workflow')
    expect(screen.queryByRole('link', { name: /day 2/i })).toBeNull()
    expect(screen.getByText('Tasks screen')).toBeInTheDocument()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })
})
