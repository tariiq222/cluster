// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import type { Session } from '../../api'
import { listWorkflowInstances } from './workflow-api'
import { MyRequests } from './MyRequests'

vi.mock('./workflow-api', () => ({
  listWorkflowInstances: vi.fn(),
  getWorkflowInstance: vi.fn(),
}))

const listWorkflowInstancesMock = vi.mocked(listWorkflowInstances)
const session = { access_token: 'test-token', user_id: 'user-1' } as unknown as Session

describe('MyRequests screen', () => {
  beforeEach(() => listWorkflowInstancesMock.mockReset())
  afterEach(() => { cleanup(); vi.restoreAllMocks() })

  it('renders the Arabic heading and empty state for the current user', async () => {
    listWorkflowInstancesMock.mockResolvedValueOnce({ items: [], total: 0 })
    render(<MyRequests locale="ar" session={session} />)
    expect(await screen.findByRole('heading', { name: 'طلباتي' })).toBeTruthy()
    expect(screen.getByText('لا توجد طلبات مرسلة بعد')).toBeTruthy()
  })
})
