// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { SecuritySettingEditScreen } from './SecuritySettingEditScreen'
import { ApiError } from '../../api/http'

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

const refetchMock = vi.hoisted(() => vi.fn())

const versionsState = vi.hoisted(() => ({
  data: null as unknown,
  isPending: false,
  error: null as unknown,
}))

vi.mock('../../api/hooks', () => ({
  usePlatformSettingsVersions: () => ({
    data: versionsState.data,
    isPending: versionsState.isPending,
    isError: versionsState.error !== null,
    error: versionsState.error,
    refetch: refetchMock,
  }),
}))

vi.mock('./platform-api', () => ({
  setPlatformSetting: vi.fn(),
}))

import { setPlatformSetting } from './platform-api'

const principalState = vi.hoisted(() => ({ capabilities: [] as string[] }))

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: principalState.capabilities,
    features: { work_management: false, tasks: true },
    effectiveScope: null,
    availableScopes: [],
    revision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    refresh: () => {},
    selectScope: async () => {},
  }),
}))

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

function mount(node: ReactNode) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        {node}
      </SessionProvider>
    </QueryClientProvider>,
  )
}

const versionsFixture = {
  items: [
    {
      id: 'v-1',
      version_id: 'v-1',
      status: 'draft',
      lock_version: 4,
      security: { idle_timeout_minutes: 15 },
    },
  ],
  next_cursor: null,
}

beforeEach(() => {
  navigateMock.mockReset()
  refetchMock.mockReset()
  vi.mocked(setPlatformSetting).mockReset()
  principalState.capabilities = []
  versionsState.data = versionsFixture
  versionsState.isPending = false
  versionsState.error = null
})

describe('security setting edit page', () => {
  it('loads the version list, seeds the current value, and saves with the observed lock version', async () => {
    principalState.capabilities = ['platform_settings.manage']

    mount(<SecuritySettingEditScreen versionId="v-1" settingKey="idle_timeout_minutes" />)

    // The form uses the shared SingleRegionFormLayout (DESIGN-RULES §2.7).
    const form = await screen.findByTestId('security-setting-edit-form')
    expect(form.tagName).toBe('FORM')

    // The version is found in the list and the field seeds from its policy.
    const input = await screen.findByLabelText('مهلة الخمول (دقيقة)')
    expect(input).toHaveValue(15)

    fireEvent.change(input, { target: { value: '20' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(setPlatformSetting).toHaveBeenCalledWith(
        'x',
        'v-1',
        'idle_timeout_minutes',
        { value_type: 'integer', value: 20 },
        4,
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/platform?tab=settings')
  })

  it('surfaces a stale 412 as an alert, reloads the list, and keeps the typed value', async () => {
    principalState.capabilities = ['platform_settings.manage']
    vi.mocked(setPlatformSetting).mockRejectedValue(
      new ApiError(412, {
        type: 'about:blank',
        title: 'Precondition Failed',
        status: 412,
      }),
    )

    mount(<SecuritySettingEditScreen versionId="v-1" settingKey="idle_timeout_minutes" />)

    const input = await screen.findByLabelText('مهلة الخمول (دقيقة)')
    fireEvent.change(input, { target: { value: '25' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(screen.getByText(/تعارض في النسخة/)).toBeInTheDocument()
    })
    expect(refetchMock).toHaveBeenCalled()
    // The inputs are preserved after the conflict — nothing is cleared.
    expect(input).toHaveValue(25)
  })
})
