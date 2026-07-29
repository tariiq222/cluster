// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import type { Session } from '../../api'
import { ApiError } from '../../api/http'
import { getDashboard, listDashboards } from '../../api/r1'
import { listActionableWorkflowStepsInbox, listTasks, listWorkflowInstances } from '../workflow/workflow-api'
import { WorkDashboard } from './WorkDashboard'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'ar',
  useToken: () => 'test-token',
}))

vi.mock('../../api/r1', () => ({
  getDashboard: vi.fn(),
  listDashboards: vi.fn(),
}))

vi.mock('../workflow/workflow-api', () => ({
  listTasks: vi.fn(),
  listWorkflowInstances: vi.fn(),
  listActionableWorkflowStepsInbox: vi.fn(),
}))

const inboxMock = vi.mocked(listActionableWorkflowStepsInbox)
const tasksMock = vi.mocked(listTasks)
const requestsMock = vi.mocked(listWorkflowInstances)
const dashboardsMock = vi.mocked(listDashboards)
const getDashboardMock = vi.mocked(getDashboard)
const session = { access_token: 'test-token', user_id: 'user-1' } as unknown as Session

function renderDashboard(overrides: Partial<React.ComponentProps<typeof WorkDashboard>> = {}) {
  const props: React.ComponentProps<typeof WorkDashboard> = {
    locale: 'ar',
    session,
    principalRevision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    effectiveScopeId: 'scope-a',
    workManagementEnabled: true,
    canViewTasks: true,
    canViewDashboards: true,
    canCreateRequest: false,
    canBrowseServices: false,
    onCreateRequest: vi.fn(),
    onBrowseServices: vi.fn(),
    onOpenApprovals: vi.fn(),
    onOpenRequests: vi.fn(),
    onOpenTasks: vi.fn(),
    onOpenDocuments: vi.fn(),
    onOpenDashboards: vi.fn(),
    onOpenRequestInstance: vi.fn(),
    onOpenApprovalStep: vi.fn(),
    onOpenTask: vi.fn(),
    ...overrides,
  }
  return { ...render(<WorkDashboard {...props} />), props }
}

function readyWork() {
  inboxMock.mockResolvedValue([{ id: 'approval-1', source_type: 'طلب اعتماد', state: 'active', allowed_actions: [] }])
  tasksMock.mockResolvedValue({
    items: [
      { id: 'today-task', title: 'مهمة اليوم', due_at: '2026-07-23T10:00:00Z', state: 'active' },
      { id: 'later-task', title: 'مهمة الغد', due_at: '2026-07-24T10:00:00Z', state: 'active' },
    ],
    total: 2,
  })
  requestsMock.mockResolvedValue({ items: [{ id: 'request-1', state: 'active' }], total: 1 })
}

beforeEach(() => {
  vi.resetAllMocks()
  readyWork()
  dashboardsMock.mockResolvedValue({ items: [], total: 0 })
  getDashboardMock.mockResolvedValue({ items: [], total: 0 })
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('WorkDashboard', () => {
  it('loads only tasks and issues zero work-management fetches when the feature is disabled', async () => {
    renderDashboard({ workManagementEnabled: false })

    // Tasks still load.
    await waitFor(() => expect(tasksMock).toHaveBeenCalled())
    // No approvals inbox, no requests, no workflow fetches at all.
    expect(inboxMock).not.toHaveBeenCalled()
    expect(requestsMock).not.toHaveBeenCalled()
    // Approvals/requests items and panels never render.
    expect(screen.queryByText('طلب اعتماد')).toBeNull()
  })

  it('does not fetch tasks and hides the task KPIs/today panel when canViewTasks is false', async () => {
    renderDashboard({ canViewTasks: false })

    // No task API call at all.
    expect(tasksMock).not.toHaveBeenCalled()
    // No "Today" panel or "Due today" / "Overdue" headings rendered.
    expect(screen.queryByText('اليوم')).toBeNull()
    expect(screen.queryByText('مستحقة اليوم')).toBeNull()
    expect(screen.queryByText('متأخرة')).toBeNull()
    // Work management surfaces still load and render.
    await waitFor(() => expect(inboxMock).toHaveBeenCalled())
    expect(await screen.findByText('طلب اعتماد')).toBeTruthy()
  })

  it('fails closed and does not call any dashboard API when both work_management and canViewTasks are false', async () => {
    renderDashboard({ workManagementEnabled: false, canViewTasks: false })

    expect(tasksMock).not.toHaveBeenCalled()
    expect(inboxMock).not.toHaveBeenCalled()
    expect(requestsMock).not.toHaveBeenCalled()
    // The page header still renders so the shell layout is intact.
    expect(screen.getByRole('heading', { name: 'الرئيسية' })).toBeTruthy()
    // No task/work-management panel or KPI headings.
    expect(screen.queryByText('اليوم')).toBeNull()
    expect(screen.queryByText('ما يحتاجك الآن')).toBeNull()
  })

  it('keeps loading only when both flags and work-management are off but still renders nothing', async () => {
    renderDashboard({ workManagementEnabled: false, canViewTasks: true })

    // Tasks still load.
    await waitFor(() => expect(tasksMock).toHaveBeenCalled())
    expect(inboxMock).not.toHaveBeenCalled()
    expect(requestsMock).not.toHaveBeenCalled()
    // Today panel + KPIs render because canViewTasks is true.
    expect(await screen.findByText('اليوم')).toBeTruthy()
  })

  it('keeps work visible when optional dashboard indicators fail', async () => {
    dashboardsMock.mockRejectedValue(new Error('network unavailable'))

    renderDashboard()

    expect(await screen.findByText('طلب اعتماد')).toBeTruthy()
    expect(screen.getByText('تعذر تحميل المؤشرات. أعد المحاولة.')).toBeTruthy()
  })

  it('uses the same waiting and active personal approval source as the inbox', async () => {
    inboxMock.mockResolvedValue([{ id: 'waiting-approval', source_type: 'بانتظار قرار', state: 'waiting', allowed_actions: ['approve'] }])

    renderDashboard()

    expect(await screen.findByText('بانتظار قرار')).toBeTruthy()
    expect(inboxMock).toHaveBeenCalledWith('test-token')
  })

  it('does not request dashboards when the principal lacks the reporting capability', async () => {
    renderDashboard({ canViewDashboards: false })

    expect(await screen.findByText('طلب اعتماد')).toBeTruthy()
    expect(dashboardsMock).not.toHaveBeenCalled()
  })

  it('shows only authorized header actions while the priority CTA remains the approvals CTA', async () => {
    const { props } = renderDashboard({ canCreateRequest: true, canBrowseServices: true })

    expect(await screen.findByRole('button', { name: 'طلب جديد' })).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'طلب جديد' }))
    fireEvent.click(screen.getByRole('button', { name: 'استعراض الخدمات' }))
    fireEvent.click(screen.getByRole('button', { name: 'افتح صندوق الاعتمادات' }))
    expect(props.onCreateRequest).toHaveBeenCalledTimes(1)
    expect(props.onBrowseServices).toHaveBeenCalledTimes(1)
    expect(props.onOpenApprovals).toHaveBeenCalledTimes(1)
    const priorityStrip = document.querySelector('#work-dashboard-priority-strip')?.closest('section')
    const kpis = document.querySelector('.work-dashboard-kpis')
    const priorityList = document.querySelector('#work-dashboard-priority-panel')?.closest('section')
    expect(priorityStrip?.compareDocumentPosition(kpis!)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
    expect(kpis?.compareDocumentPosition(priorityList!)).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
  })

  it('does not start scope-bound reads while the effective scope is pending', async () => {
    renderDashboard({ scopeReady: false, effectiveScopeId: null, scopeEpoch: 1 })

    expect(inboxMock).not.toHaveBeenCalled()
    expect(tasksMock).not.toHaveBeenCalled()
    expect(requestsMock).not.toHaveBeenCalled()
    expect(dashboardsMock).not.toHaveBeenCalled()
  })

  it('keeps a failed source separate from ready sources and exposes its own retry', async () => {
    tasksMock.mockRejectedValue(new ApiError(500, { type: 'about:blank', title: 'Server error', status: 500 }))

    renderDashboard()

    expect(await screen.findByText('طلب اعتماد')).toBeTruthy()
    expect(screen.getByText('تعذر تحميل المهام. أعد المحاولة.')).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'إعادة المحاولة' }).length).toBeGreaterThan(0)
  })

  it('clears prior-scope results and ignores their late response after a revision change', async () => {
    let resolveOldInbox: (value: Array<{ id: string; source_type: string; state: string; allowed_actions: string[] }>) => void = () => undefined
    inboxMock.mockImplementationOnce(() => new Promise((resolve) => { resolveOldInbox = resolve }))
    const { rerender, props } = renderDashboard({ principalRevision: 1, effectiveScopeId: 'scope-a', effectiveScopeLabel: 'النطاق أ' })

    rerender(<WorkDashboard {...props} principalRevision={2} effectiveScopeId="scope-b" effectiveScopeLabel="النطاق ب" />)
    resolveOldInbox([{ id: 'old-step', source_type: 'قديم', state: 'active', allowed_actions: [] }])

    await waitFor(() => expect(inboxMock).toHaveBeenCalledTimes(2))
    expect(screen.queryByText('قديم')).toBeNull()
    expect(document.querySelector('.work-dashboard-scope')?.textContent).toContain('النطاق ب')
  })

  it('shows only today tasks and sends the priority CTA to approvals', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-23T08:00:00Z'))
    const { props } = renderDashboard()

    await act(async () => { await Promise.resolve() })
    expect(screen.getByText('مهمة اليوم')).toBeTruthy()
    expect(screen.queryByText('مهمة الغد')).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'افتح صندوق الاعتمادات' }))
    expect(props.onOpenApprovals).toHaveBeenCalledTimes(1)
  })

  it('shows an authorization denial separately from a retryable source error', async () => {
    tasksMock.mockRejectedValue(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))

    renderDashboard()

    expect(await screen.findByText('طلب اعتماد')).toBeTruthy()
    expect(screen.getByText('لا تملك صلاحية عرض هذا القسم.')).toBeTruthy()
    expect(screen.queryByText('تعذر تحميل المهام. أعد المحاولة.')).toBeNull()
  })

  it('keeps the mobile KPI grid at two columns and constrains dashboard content to one column', () => {
    const css = readFileSync('src/features/dashboard/WorkDashboard.css', 'utf8')
    expect(css).toMatch(/\.work-dashboard-kpis\s*\{[\s\S]*grid-template-columns:\s*repeat\(2, minmax\(0, 1fr\)\)/)
    expect(css).not.toMatch(/@media \(max-width: 600px\)[\s\S]*\.work-dashboard-kpis[\s\S]*grid-template-columns:\s*1fr/)
    expect(css).toMatch(/\.work-dashboard-content\s*\{[\s\S]*grid-template-columns:\s*minmax\(0, 1fr\)/)
  })
})
