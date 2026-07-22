// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

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

describe('R1 admin creation screens', () => {
  it('keeps request types list-first and exposes the inline create form', async () => {
    render(<WorkDefinitionsScreen />)

    expect(await screen.findByText('نوع طلب')).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.getByText(/يبقى كل سجل مثبتاً/)).toBeTruthy()

    expect(screen.getByRole('button', { name: 'إنشاء' })).toBeTruthy()
    expect(document.getElementById('work-definition-code')).toBeTruthy()
    expect(document.getElementById('work-definition-name')).toBeTruthy()
  })

  it('keeps approval paths list-first and surfaces the published definitions panel', async () => {
    render(<WorkflowAdminScreen />)

    expect(await screen.findByText('مسار موافقة')).toBeTruthy()
    expect(screen.getByText('التعريفات المنشورة')).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()

    expect(screen.getByRole('button', { name: 'إنشاء' })).toBeTruthy()
    expect(document.getElementById('workflow-code')).toBeTruthy()
    expect(document.getElementById('workflow-name')).toBeTruthy()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })
})
