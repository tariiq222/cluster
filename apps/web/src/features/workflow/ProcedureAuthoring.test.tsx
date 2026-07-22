// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import type { Session } from '../../api'
import { listWorkflowDefinitions, listWorkflowVersions } from '../../api/r1'
import { ProcedureAuthoring } from './ProcedureAuthoring'

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

describe('ProcedureAuthoring screen', () => {
  beforeEach(() => {
    listWorkflowDefinitionsMock.mockReset()
    listWorkflowVersionsMock.mockReset()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('renders the Arabic heading, description, and empty state when no drafts exist', async () => {
    listWorkflowDefinitionsMock.mockResolvedValueOnce({ items: [], total: 0 })

    render(<ProcedureAuthoring locale="ar" session={session} />)

    expect(await screen.findByRole('heading', { name: 'تصميم الإجراء' })).toBeTruthy()
    expect(screen.getByText('حرّر مسودة الإجراء ثم أرسلها إلى مكتب إدارة العمليات للمراجعة.')).toBeTruthy()
    expect(screen.getByText('لا توجد مسودات بانتظار التصميم')).toBeTruthy()
    expect(screen.getByText(/ستظهر هنا المسودات التي أنشأها أعضاء مكتب العمليات/)).toBeTruthy()
  })

  it('lists draft versions with the draft badge and gates the submit button on a non-empty step list', async () => {
    listWorkflowDefinitionsMock.mockResolvedValueOnce({
      items: [{ id: 'def-1', name: 'مسار اختبار', code: 'test_path' }],
      total: 1,
    })
    listWorkflowVersionsMock.mockResolvedValueOnce({
      items: [
        {
          id: 'ver-1',
          version_number: 2,
          review_state: 'draft',
          graph_document: { nodes: [] },
        },
      ],
      total: 1,
    })

    render(<ProcedureAuthoring locale="ar" session={session} />)

    expect(await screen.findByRole('heading', { name: /مسار اختبار/ })).toBeTruthy()
    expect(screen.getAllByText('مسودة').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: /إضافة خطوة/ })).toBeTruthy()
    const submitButton = screen.getByRole('button', { name: 'إرسال للمراجعة' }) as HTMLButtonElement
    expect(submitButton.disabled).toBe(true)
  })
})
