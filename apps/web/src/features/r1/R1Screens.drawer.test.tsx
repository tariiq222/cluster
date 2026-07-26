// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { ReportsScreen, TasksScreen, WorkDefinitionsScreen, WorkflowAdminScreen } from './R1Screens'
import * as r1 from '../../api/r1'

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
  getReportExport: vi.fn(),
  listDashboards: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  listReports: vi.fn().mockResolvedValue({ items: [{ id: 'report-1', name: 'Quarterly report' }], total: 1 }),
  listTasks: vi.fn().mockResolvedValue({ items: [{ id: 'task-1', title: 'Review request', status: 'open', lock_version: 1 }] }),
  listWorkDefinitions: vi.fn().mockResolvedValue({ items: [{ id: 'definition-1', name: 'نوع طلب' }] }),
  listWorkflowDefinitions: vi.fn().mockResolvedValue({ items: [{ id: 'path-1', name: 'مسار موافقة' }] }),
  listWorkflowInstances: vi.fn().mockResolvedValue({ items: [] }),
  getReport: vi.fn().mockResolvedValue({ items: [{ id: 'row-1', name: 'Report row' }], total: 1 }),
  publishWorkDefinitionVersion: vi.fn(),
  publishWorkflowVersion: vi.fn(),
  requestReportExport: vi.fn(),
  searchRecords: vi.fn(),
  transitionTask: vi.fn(),
}))

describe('R1 admin creation screens', () => {
  it('keeps request types list-first and exposes the inline create form', async () => {
    render(<WorkDefinitionsScreen capabilities={['work_definition.create', 'work_definition.publish']} />)

    expect(await screen.findByText('نوع طلب')).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(screen.getByText(/يبقى كل سجل مثبتاً/)).toBeTruthy()

    expect(screen.getByRole('button', { name: 'إنشاء' })).toBeTruthy()
    expect(document.getElementById('work-definition-code')).toBeTruthy()
    expect(document.getElementById('work-definition-name')).toBeTruthy()
  })

  it('keeps approval paths list-first and surfaces the published definitions panel', async () => {
    render(<WorkflowAdminScreen capabilities={['workflow.manage']} />)

    expect(await screen.findByText('مسار موافقة')).toBeTruthy()
    expect(screen.getByText('التعريفات المنشورة')).toBeTruthy()
    expect(screen.queryByRole('dialog')).toBeNull()

    expect(screen.getByRole('button', { name: 'إنشاء' })).toBeTruthy()
    expect(document.getElementById('workflow-code')).toBeTruthy()
    expect(document.getElementById('workflow-name')).toBeTruthy()
  })

  it('keeps read-only principals on read surfaces without mutation controls', async () => {
    const { rerender } = render(<WorkDefinitionsScreen capabilities={['work_definition.read']} />)
    expect(await screen.findByText('نوع طلب')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إنشاء' })).toBeNull()

    rerender(<WorkflowAdminScreen capabilities={['workflow.read']} />)
    expect(await screen.findByText('مسار موافقة')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إنشاء' })).toBeNull()

    rerender(<TasksScreen capabilities={['tasks.read']} />)
    expect(await screen.findByText('Review request')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إكمال' })).toBeNull()

    rerender(<ReportsScreen capabilities={['reporting.list']} />)
    expect(await screen.findByText('Report row')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'طلب تصدير' })).toBeNull()
  })

  it('shows each mutation control only to the matching capability holder', async () => {
    const { rerender } = render(<TasksScreen capabilities={['tasks.read', 'tasks.complete']} />)
    expect(await screen.findByRole('button', { name: 'إكمال' })).toBeTruthy()

    rerender(<ReportsScreen capabilities={['reporting.list', 'reporting.export']} />)
    expect(await screen.findByText('Report row')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'طلب تصدير' })).toBeTruthy()
    expect(r1.requestReportExport).not.toHaveBeenCalled()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })
})
