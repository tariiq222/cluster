// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { AlertPolicyEditScreen } from './AlertPolicyEditScreen'

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

vi.mock('./platform-api', () => ({
  listPlatformAlertPolicies: vi.fn(),
  updatePlatformAlertPolicy: vi.fn(),
}))

import { listPlatformAlertPolicies, updatePlatformAlertPolicy } from './platform-api'

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

const policyFixture = {
  id: 'p-1',
  code: 'high-cpu',
  status: 'enabled',
  severity: 'critical',
  channel: 'email',
  allowed_actions: [],
  lock_version: 3,
}

beforeEach(() => {
  navigateMock.mockReset()
  vi.mocked(listPlatformAlertPolicies).mockReset()
  vi.mocked(updatePlatformAlertPolicy).mockReset()
  vi.mocked(listPlatformAlertPolicies).mockResolvedValue({ items: [policyFixture] })
  principalState.capabilities = []
})

describe('alert policy edit page', () => {
  it('finds the policy by id, seeds the fields, and saves with the observed lock version', async () => {
    principalState.capabilities = ['platform_operations.alerts.manage']

    mount(<AlertPolicyEditScreen policyId="p-1" />)

    // The form uses the shared TwoRegionFormLayout (DESIGN-RULES §2.7).
    const form = await screen.findByTestId('alert-policy-edit-form')
    expect(form.tagName).toBe('FORM')
    expect(screen.getByTestId('alert-policy-edit-main')).toBeInTheDocument()
    expect(screen.getByTestId('alert-policy-edit-review')).toBeInTheDocument()

    // The form seeds from the fetched policy.
    const channel = await screen.findByLabelText('القناة')
    expect(channel).toHaveValue('email')

    fireEvent.change(channel, { target: { value: 'slack' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ' }))

    await waitFor(() => {
      expect(updatePlatformAlertPolicy).toHaveBeenCalledWith(
        'x',
        'p-1',
        { status: 'enabled', severity: 'critical', channel: 'slack' },
        3,
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/platform?tab=settings')
  })

  it('renders the shared non-disclosing denied state without the manage capability', () => {
    principalState.capabilities = ['platform_operations.alerts.read']

    mount(<AlertPolicyEditScreen policyId="p-1" />)

    expect(screen.getByTestId('alert-policy-edit-screen')).toBeInTheDocument()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    expect(listPlatformAlertPolicies).not.toHaveBeenCalled()
  })
})
