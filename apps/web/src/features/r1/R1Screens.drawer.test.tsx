// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'

import { WorkDefinitionsScreen, WorkflowAdminScreen } from './R1Screens'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'ar',
  useToken: () => 'test-token',
}))

vi.mock('../../api', () => ({
  stateFromError: () => 'error',
}))

vi.mock('../../api/r1', () => ({
  createWorkDefinition: vi.fn(),
  createWorkDefinitionVersion: vi.fn(),
  createWorkflowDefinition: vi.fn(),
  getDashboard: vi.fn(),
  getReport: vi.fn(),
  getReportExport: vi.fn(),
  listDashboards: vi.fn(),
  listReports: vi.fn(),
  listTasks: vi.fn(),
  listWorkDefinitions: vi.fn().mockResolvedValue({ items: [{ id: 'definition-1', name: 'نوع طلب' }] }),
  listWorkflowDefinitions: vi.fn().mockResolvedValue({ items: [{ id: 'path-1', name: 'مسار موافقة' }] }),
  listWorkflowInstances: vi.fn().mockResolvedValue({ items: [] }),
  publishWorkDefinitionVersion: vi.fn(),
  publishWorkflowVersion: vi.fn(),
  requestReportExport: vi.fn(),
  searchRecords: vi.fn(),
  transitionTask: vi.fn(),
}))

describe('R1 admin creation drawers', () => {
  it('keeps request types list-first and reveals its create form only in the unified Drawer', async () => {
    render(<WorkDefinitionsScreen />)

    expect(await screen.findByText('نوع طلب')).toBeInTheDocument()
    expect(screen.queryByRole('dialog', { name: 'إضافة نوع طلب' })).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'إضافة نوع طلب' }))

    expect(screen.getByRole('dialog', { name: 'إضافة نوع طلب' })).toBeInTheDocument()
    expect(screen.getByLabelText('الرمز')).toBeInTheDocument()
  })

  it('keeps approval paths list-first and explains the immutable published-version flow', async () => {
    render(<WorkflowAdminScreen />)

    expect(await screen.findByText('مسار موافقة')).toBeInTheDocument()
    expect(screen.getByText(/الإصدارات المنشورة ثابتة/)).toBeInTheDocument()
    expect(screen.queryByRole('dialog', { name: 'إضافة مسار موافقة' })).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'إضافة مسار موافقة' }))

    expect(screen.getByRole('dialog', { name: 'إضافة مسار موافقة' })).toBeInTheDocument()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })
})
