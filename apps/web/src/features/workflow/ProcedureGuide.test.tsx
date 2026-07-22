// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import type { Session } from '../../api'
import { listWorkflowDefinitions, listWorkflowVersions } from '../../api/r1'
import { ProcedureGuide } from './ProcedureGuide'

vi.mock('../../api/r1', () => ({
  listWorkflowDefinitions: vi.fn(),
  listWorkflowVersions: vi.fn(),
  transitionWorkflowVersion: vi.fn(),
}))

const listWorkflowDefinitionsMock = vi.mocked(listWorkflowDefinitions)
const listWorkflowVersionsMock = vi.mocked(listWorkflowVersions)

const session = {
  access_token: 'test-token',
  expires_at: '2026-07-22T00:00:00.000Z',
  user: { id: 'user-1', username: 'test-user' },
} as unknown as Session

describe('ProcedureGuide screen', () => {
  beforeEach(() => {
    listWorkflowDefinitionsMock.mockReset()
    listWorkflowVersionsMock.mockReset()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('renders the Arabic heading, description, and empty state when no published procedures exist', async () => {
    listWorkflowDefinitionsMock.mockResolvedValueOnce({ items: [], total: 0 })

    render(<ProcedureGuide locale="ar" session={session} />)

    expect(await screen.findByRole('heading', { name: 'دليل الإجراءات المنشورة' })).toBeTruthy()
    expect(screen.getByText('الإجراءات المنشورة المتاحة للقراءة وبدء الاستخدام.')).toBeTruthy()
    expect(screen.getByText('لا توجد إجراءات منشورة بعد')).toBeTruthy()
    expect(screen.getByText(/تظهر هنا الإجراءات بعد نشرها من مكتب إدارة العمليات/)).toBeTruthy()
  })

  it('lists published procedures with a deep link to the submission form', async () => {
    listWorkflowDefinitionsMock.mockResolvedValueOnce({
      items: [{ id: 'proc-1', name: 'طلب إجازة', code: 'leave_request' }],
      total: 1,
    })
    listWorkflowVersionsMock.mockResolvedValueOnce({
      items: [
        {
          id: 'ver-1',
          version_number: 1,
          definition_state: 'published',
          usage_description: 'يستخدم لطلب إجازة سنوية.',
        },
      ],
      total: 1,
    })

    render(<ProcedureGuide locale="ar" session={session} />)

    expect(await screen.findByRole('heading', { name: 'طلب إجازة' })).toBeTruthy()
    expect(screen.getAllByText('منشور').length).toBeGreaterThan(0)
    const deepLink = screen.getByRole('link', { name: /فتح نموذج التقديم/ })
    expect(deepLink.getAttribute('href')).toBe('/procedures/proc-1/submit')
  })
})
