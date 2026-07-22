// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import type { Session } from '../../api'
import { listWorkflowInstances } from './workflow-api'
import { ApprovalInbox } from './ApprovalInbox'

vi.mock('./workflow-api', () => ({
  listWorkflowInstances: vi.fn(),
  getWorkflowInstance: vi.fn(),
  recordWorkflowDecision: vi.fn(),
}))

const listWorkflowInstancesMock = vi.mocked(listWorkflowInstances)
const session = { access_token: 'test-token', user_id: 'user-1' } as unknown as Session

describe('ApprovalInbox screen', () => {
  beforeEach(() => listWorkflowInstancesMock.mockReset())
  afterEach(() => { cleanup(); vi.restoreAllMocks() })

  it('renders the Arabic heading and empty state', async () => {
    listWorkflowInstancesMock.mockResolvedValueOnce({ items: [], total: 0 })
    render(<ApprovalInbox locale="ar" session={session} />)
    expect(await screen.findByRole('heading', { name: 'اعتماداتي' })).toBeTruthy()
    expect(screen.getByText('لا توجد اعتمادات تنتظر قرارك')).toBeTruthy()
  })
})
