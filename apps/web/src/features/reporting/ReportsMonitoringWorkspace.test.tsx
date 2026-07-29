// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../r1/R1Screens', () => ({
  ReportsScreen: ({ capabilities }: { capabilities: readonly string[] }) => (
    <div>Reports screen: {capabilities.join(',')}</div>
  ),
}))

vi.mock('./DashboardsScreen', () => ({
  DashboardsScreen: ({
    dashboardId,
    scopeId,
    revision,
  }: {
    dashboardId?: string
    scopeId?: string | null
    revision?: number
  }) => (
    <div>
      Dashboards screen: {dashboardId ?? 'list'} / {scopeId ?? 'no-scope'} / {revision ?? 0}
    </div>
  ),
}))

vi.mock('../audit/AuditWorkspace', () => ({
  AuditWorkspace: ({
    token,
    capabilities,
  }: {
    token: string
    capabilities: readonly string[]
  }) => <div>Audit screen: {token} / {capabilities.join(',')}</div>,
}))

import type { Session } from '../../api'
import type { ReportsMonitoringRoute } from './ReportsMonitoringWorkspace'
import { ReportsMonitoringWorkspace } from './ReportsMonitoringWorkspace'

const session: Session = {
  access_token: 'test-token',
  csrf_token: 'test-token',
  user_id: 'user-1',
  expires_at: '2026-07-30T00:00:00.000Z',
  restricted: false,
  principal: { user_id: 'user-1' },
}

const allCapabilities = [
  'reporting.list',
  'reporting.dashboard',
  'audit.event.read',
] as const

function renderWorkspace({
  route = { name: 'reports' },
  capabilities = allCapabilities,
  navigate = vi.fn(),
  locale = 'ar',
}: {
  route?: ReportsMonitoringRoute
  capabilities?: readonly string[]
  navigate?: (path: string) => void
  locale?: 'ar' | 'en'
} = {}) {
  return render(
    <ReportsMonitoringWorkspace
      locale={locale}
      route={route}
      session={session}
      capabilities={capabilities}
      scopeId="scope-a"
      revision={7}
      navigate={navigate}
    />,
  )
}

describe('ReportsMonitoringWorkspace', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('renders the capability-filtered local tabs and marks the active route', () => {
    renderWorkspace()

    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual([
      'التقارير',
      'لوحات المؤشرات',
      'سجل التدقيق',
    ])
    expect(screen.getByRole('link', { name: 'التقارير' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByText(`Reports screen: ${allCapabilities.join(',')}`)).toBeTruthy()
  })

  it.each([
    ['reporting.list', 'التقارير'],
    ['reporting.dashboard', 'لوحات المؤشرات'],
    ['audit.event.read', 'سجل التدقيق'],
  ] as const)('hides the %s tab when its exact capability is absent', (capability, label) => {
    renderWorkspace({
      capabilities: allCapabilities.filter((candidate) => candidate !== capability),
    })

    expect(screen.queryByRole('link', { name: label })).toBeNull()
  })

  it('keeps each local tab on its existing deep link and delegates navigation', () => {
    const navigate = vi.fn()
    renderWorkspace({ navigate })

    const dashboardsTab = screen.getByRole('link', { name: 'لوحات المؤشرات' })
    expect(dashboardsTab.getAttribute('href')).toBe('/dashboards')
    dashboardsTab.click()

    expect(navigate).toHaveBeenCalledWith('/dashboards')
  })

  it('uses the approved English labels', () => {
    renderWorkspace({ locale: 'en' })

    expect(screen.getAllByRole('link').map((link) => link.textContent)).toEqual([
      'Reports',
      'Dashboards',
      'Audit ledger',
    ])
  })

  it('preserves dashboard detail, scope, and revision props for the dashboard screen', () => {
    renderWorkspace({ route: { name: 'dashboards', dashboardId: 'dashboard-1' } })

    expect(screen.getByRole('link', { name: 'لوحات المؤشرات' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByText('Dashboards screen: dashboard-1 / scope-a / 7')).toBeTruthy()
  })

  it('preserves the session token and capabilities for the audit screen', () => {
    renderWorkspace({ route: { name: 'audit' } })

    expect(screen.getByRole('link', { name: 'سجل التدقيق' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByText(`Audit screen: test-token / ${allCapabilities.join(',')}`)).toBeTruthy()
  })
})
