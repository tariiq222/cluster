// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import type { Session } from '../../api'
import { listWorkflowDefinitions, listWorkflowVersions } from '../../api/r1'
import { ProcedureOfficeReview } from './ProcedureOfficeReview'

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

describe('ProcedureOfficeReview screen', () => {
  beforeEach(() => {
    listWorkflowDefinitionsMock.mockReset()
    listWorkflowVersionsMock.mockReset()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('renders the Arabic heading, description, and empty state when no pending versions exist', async () => {
    listWorkflowDefinitionsMock.mockResolvedValueOnce({ items: [], total: 0 })

    render(<ProcedureOfficeReview locale="ar" session={session} />)

    expect(await screen.findByRole('heading', { name: 'اعتمادات الإجراء' })).toBeTruthy()
    expect(screen.getByText('الإصدارات بانتظار مراجعة عضو آخر من مكتب العمليات قبل النشر.')).toBeTruthy()
    expect(screen.getByText('لا توجد إصدارات بانتظار المراجعة')).toBeTruthy()
    expect(screen.getByText(/تظهر هنا الإصدارات بعد أن يرسلها المؤلف/)).toBeTruthy()
  })

  it('shows pending versions with the pending badge and the graph hash confirmation field', async () => {
    listWorkflowDefinitionsMock.mockResolvedValueOnce({
      items: [{ id: 'def-1', name: 'مسار اختبار', code: 'test_path' }],
      total: 1,
    })
    listWorkflowVersionsMock.mockResolvedValueOnce({
      items: [
        {
          id: 'ver-1',
          version_number: 1,
          review_state: 'pending_review',
          submitted_by_user_id: 'user-2',
          submitted_at: '2026-07-22T00:00:00.000Z',
          graph_hash: 'abc123',
        },
      ],
      total: 1,
    })

    render(<ProcedureOfficeReview locale="ar" session={session} />)

    expect(await screen.findByRole('heading', { name: /مسار اختبار/ })).toBeTruthy()
    expect(screen.getAllByText('بانتظار المراجعة').length).toBeGreaterThan(0)
    expect(screen.getByLabelText(/بصمة السلسلة/)).toBeTruthy()
    expect(screen.getByRole('button', { name: 'اعتماد الإصدار' })).toBeTruthy()
    const rejectButton = screen.getByRole('button', { name: 'إعادة للتصحيح' }) as HTMLButtonElement
    expect(rejectButton.disabled).toBe(true)
  })
})
