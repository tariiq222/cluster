// @vitest-environment jsdom
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../api/r1', () => ({
  listDashboards: vi.fn(),
  getDashboard: vi.fn(),
}))

import { SessionProvider } from '../../app/session-context'
import { getDashboard, listDashboards } from '../../api/r1'
import { DashboardsScreen } from './DashboardsScreen'

const listDashboardsMock = vi.mocked(listDashboards)
const getDashboardMock = vi.mocked(getDashboard)

function renderScreen(props: Partial<React.ComponentProps<typeof DashboardsScreen>> = {}) {
  const componentProps = {
    locale: 'ar' as const,
    scopeId: 'scope-a',
    revision: 1,
    ...props,
  }
  return {
    ...render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <DashboardsScreen {...componentProps} />
      </SessionProvider>,
    ),
    componentProps,
  }
}

describe('DashboardsScreen', () => {
  beforeEach(() => {
    listDashboardsMock.mockReset().mockResolvedValue({
      items: [{ id: 'd-1', title: 'المعاملات المتأخرة' }],
      total: 1,
    })
    getDashboardMock.mockReset().mockResolvedValue({ items: [], total: 7 })
  })

  afterEach(() => cleanup())

  it('renders only dashboards returned by the authorized list endpoint', async () => {
    renderScreen()

    expect(await screen.findByRole('link', { name: 'المعاملات المتأخرة' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: /لوحة غير مصرح بها/ })).toBeNull()
  })

  it('loads detail with the effective scope and reloads it after a scope revision', async () => {
    const { rerender, componentProps } = renderScreen({ dashboardId: 'd-1' })

    await waitFor(() => expect(getDashboardMock).toHaveBeenCalledWith('test-token', 'd-1', 'scope-a'))

    rerender(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <DashboardsScreen {...componentProps} dashboardId="d-1" scopeId="scope-b" revision={2} />
      </SessionProvider>,
    )

    await waitFor(() => expect(getDashboardMock).toHaveBeenCalledWith('test-token', 'd-1', 'scope-b'))
  })

  it('ignores a detail response from the previous scope', async () => {
    let resolveOld!: (value: { items: never[]; total: number }) => void
    getDashboardMock
      .mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve }))
      .mockResolvedValueOnce({ items: [], total: 2 })

    const { rerender, componentProps } = renderScreen({ dashboardId: 'd-1' })
    await waitFor(() => expect(getDashboardMock).toHaveBeenCalledTimes(1))

    rerender(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <DashboardsScreen {...componentProps} dashboardId="d-1" scopeId="scope-b" revision={2} />
      </SessionProvider>,
    )
    await waitFor(() => expect(screen.getByText('2')).toBeTruthy())

    await act(async () => { resolveOld({ items: [], total: 99 }) })
    expect(screen.queryByText('99')).toBeNull()
    expect(screen.getByText('2')).toBeTruthy()
  })
})
