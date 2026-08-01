// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, within, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { AccountsTab } from './tabs/AccountsTab'
import { RolesTab } from './tabs/RolesTab'
import { AccessScreen } from './AccessScreen'
import {
  issueAccountActivation,
  searchScopeTargets,
  listAdminResources,
  listAccounts,
  listCapabilities,
  listAssignments,
  listRolesWithCapabilities,
  listRoleCapabilityCodes,
  createAssignment,
  transitionAdminResource,
  fetchBootstrapState,
  completeBootstrap,
  explainDecision,
} from '../../api/access'

/*
 * Task 8 — two behavior rules that are intentionally tested before the
 * production migration:
 *
 * 1. Issuing an activation never exposes the activation secret. The dialog
 *    may confirm controlled delivery and expiry only, and the UI must ignore
 *    an unexpected `token` property even when the mocked payload carries one.
 * 2. The assignment-scope picker searches on demand: no query on mount, no
 *    query for a single trimmed character, then a query once the second
 *    character is typed — carrying scope_type plus a parent derived from the
 *    effective principal scope.
 */

const SENTINEL = 'activation-secret-sentinel'

const pendingAccount = {
  id: '01980f50-5f0d-7000-8000-000000000901',
  username: 'finance.pending',
  person_id: '01980f50-5f0d-7000-8000-000000000902',
  person_version: 1,
  status: 'pending',
  must_change_password: false,
  mfa_required: false,
  password_version: 1,
  locked_until: null,
  display_name_ar: 'مسؤول مالية جديد',
  display_name_en: 'New finance officer',
} as const

const systemRole = {
  id: '01980f50-5f0d-7000-8000-000000000911',
  code: 'finance.system',
  name_en: 'Finance reviewer',
  name_ar: 'مراجع مالي',
  is_system_role: true,
  role_type: 'system',
  status: 'active',
  lock_version: 1,
  capability_codes: ['records.read'],
  allowed_actions: ['clone'],
} as const

const customRole = {
  id: '01980f50-5f0d-7000-8000-000000000912',
  code: 'finance.custom',
  name_en: 'Finance reviewer (custom)',
  name_ar: 'مراجع مالي مخصص',
  is_system_role: false,
  role_type: 'custom',
  status: 'active',
  lock_version: 2,
  capability_codes: ['records.read'],
  allowed_actions: ['edit', 'archive'],
} as const

const queryResult = (items: unknown[]) => ({
  data: { items, next_cursor: null },
  isLoading: false,
  isError: false,
  error: null,
  refetch: vi.fn(),
})

const emptyQuery = queryResult([])

const accountFixture = {
  id: '01980f50-5f0d-7000-8000-000000000931',
  username: 'finance.officer',
  person_id: '01980f50-5f0d-7000-8000-000000000932',
  person_version: 1,
  status: 'active',
  must_change_password: false,
  mfa_required: false,
  password_version: 1,
  locked_until: null,
  display_name_ar: 'مسؤول مالية',
  display_name_en: 'Finance officer',
} as const

vi.mock('../../api/hooks', () => ({
  useUserAccounts: () => queryResult([pendingAccount]),
  usePeople: () => emptyQuery,
  useRolesList: () => queryResult([systemRole, customRole]),
  useCapabilitiesList: () => emptyQuery,
}))

vi.mock('../../api/access', () => ({
  issueAccountActivation: vi.fn(),
  searchScopeTargets: vi.fn(),
  listAdminResources: vi.fn(async () => ({ items: [], next_cursor: null })),
  listAccounts: vi.fn(async () => ({ items: [accountFixture], next_cursor: null })),
  listCapabilities: vi.fn(async () => ({ items: [], next_cursor: null })),
  listAssignments: vi.fn(async () => ({ items: [], next_cursor: null })),
  listRolesWithCapabilities: vi.fn(async () => ({ items: [systemRole, customRole], next_cursor: null })),
  listRoleCapabilityCodes: vi.fn(async () => []),
  createAssignment: vi.fn(async () => ({ id: '01980f50-5f0d-7000-8000-000000000933', lock_version: 1 })),
  createAdminResource: vi.fn(async () => ({ id: '01980f50-5f0d-7000-8000-000000000934', lock_version: 1 })),
  updateAdminResource: vi.fn(async () => ({ id: '01980f50-5f0d-7000-8000-000000000935', lock_version: 1 })),
  transitionAdminResource: vi.fn(async () => ({ id: '01980f50-5f0d-7000-8000-000000000936', lock_version: 1 })),
  fetchBootstrapState: vi.fn(async () => ({
    status: 'bootstrap_pending',
    version: 1,
    allowedCapabilities: ['authorization.bootstrap.complete'],
    expiresAt: null,
    completedAt: null,
    completedByUserId: null,
  })),
  completeBootstrap: vi.fn(async () => ({
    status: 'completed',
    version: 2,
    allowedCapabilities: ['authorization.bootstrap.complete'],
    expiresAt: null,
    completedAt: '2026-08-01T10:00:00Z',
    completedByUserId: '01980f50-5f0d-7000-8000-000000000941',
  })),
  explainDecision: vi.fn(async () => ({
    decision_id: '01980f50-5f0d-7000-8000-000000000942',
    decision: 'allow',
    action: 'records.read',
    resource_type: 'record',
    reason_codes: ['role-assigned'],
    policy_version: 'v1',
    facts_version: 'f1',
    authorization_trace_id: '01980f50-5f0d-7000-8000-000000000943',
    evaluated_at: '2026-08-01T10:00:00Z',
    correlation_id: '01980f50-5f0d-7000-8000-000000000944',
    classification: 'internal',
    access_context: {
      subject_id: '01980f50-5f0d-7000-8000-000000000945',
      tenant_id: '01980f50-5f0d-7000-8000-000000000946',
      roles: ['finance.system'],
      clearance: 'internal',
      correlation_id: '01980f50-5f0d-7000-8000-000000000944',
    },
    applies_in_plain_language: 'Finance officer may read records.',
    assignment_summaries: [
      {
        role_code: 'finance.system',
        effective_status: 'active',
        scope_type: 'cluster',
        scope_id: '01980f50-5f0d-7000-8000-000000000946',
      },
    ],
    obligations: ['audit'],
    policy_references: [
      { policy_code: 'records.read.policy', policy_version: 'v1', excerpt: 'excerpt' },
    ],
  })),
}))

const principalState = vi.hoisted(() => ({
  capabilities: [] as string[],
  effectiveScope: null as { scopeType: string; scopeId: string; label: string } | null,
}))

vi.mock('../../app/principal-context', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../app/principal-context')>()
  return {
    ...actual,
    usePrincipal: () => ({
      state: 'ready',
      capabilities: principalState.capabilities,
      features: { work_management: false, tasks: true },
      effectiveScope: principalState.effectiveScope,
      availableScopes: [],
      revision: 0,
      scopeEpoch: 0,
      scopeReady: true,
      refresh: () => {},
      selectScope: async () => {},
    }),
  }
})

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

beforeEach(() => {
  vi.mocked(issueAccountActivation).mockReset()
  vi.mocked(searchScopeTargets).mockReset()
  vi.mocked(searchScopeTargets).mockResolvedValue({ items: [], next_cursor: null })
  vi.mocked(listAccounts).mockReset()
  vi.mocked(listAccounts).mockResolvedValue({ items: [accountFixture], next_cursor: null })
  vi.mocked(listCapabilities).mockReset()
  vi.mocked(listCapabilities).mockResolvedValue({ items: [], next_cursor: null })
  vi.mocked(listAssignments).mockReset()
  vi.mocked(listAssignments).mockResolvedValue({ items: [], next_cursor: null })
  vi.mocked(listRolesWithCapabilities).mockReset()
  vi.mocked(listRolesWithCapabilities).mockResolvedValue({ items: [systemRole, customRole], next_cursor: null })
  vi.mocked(listRoleCapabilityCodes).mockReset()
  vi.mocked(listRoleCapabilityCodes).mockResolvedValue([])
  vi.mocked(createAssignment).mockReset()
  vi.mocked(transitionAdminResource).mockReset()
  vi.mocked(listAdminResources).mockReset()
  vi.mocked(listAdminResources).mockImplementation(async (resource: unknown) => {
    if (resource === 'roles') {
      return { items: [systemRole, customRole], next_cursor: null }
    }
    return { items: [], next_cursor: null }
  })
  vi.mocked(fetchBootstrapState).mockReset()
  vi.mocked(fetchBootstrapState).mockResolvedValue({
    status: 'bootstrap_pending',
    version: 1,
    allowedCapabilities: ['authorization.bootstrap.complete'],
    expiresAt: null,
    completedAt: null,
    completedByUserId: null,
  })
  vi.mocked(completeBootstrap).mockReset()
  vi.mocked(explainDecision).mockReset()
  principalState.capabilities = []
  principalState.effectiveScope = null
})

describe('accounts activation security', () => {
  it('confirms controlled delivery and expiry in a dialog and never exposes the activation secret', async () => {
    principalState.capabilities = ['identity.account.read', 'identity.account.manage']
    vi.mocked(issueAccountActivation).mockResolvedValue({
      account_id: pendingAccount.id,
      status: 'activation_issued',
      expires_at: '2099-01-01T00:00:00Z',
      delivery: 'controlled',
      token: SENTINEL,
    })

    mount(<AccountsTab />)

    // Pending accounts expose the activation action.
    fireEvent.click(screen.getByRole('button', { name: 'تفعيل الحساب' }))

    // The success surface is a dialog that confirms controlled delivery and
    // the expiry only.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/القناة الآمنة|secure channel/i)).toBeInTheDocument()
    expect(within(dialog).getByText(/تنتهي صلاحية|expires at/i)).toBeInTheDocument()

    // The over-broad mock carried an activation secret; it must never appear
    // anywhere in the rendered table or dialog.
    expect(vi.mocked(issueAccountActivation)).toHaveBeenCalledWith(pendingAccount.id, 'x')
    expect(screen.queryByText(SENTINEL, { exact: false })).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain(SENTINEL)
  })
})

describe('assignment scope target search', () => {
  it('searches assignment scope targets on demand rather than preloading them', async () => {
    principalState.capabilities = [
      'authorization.role.read',
      'authorization.capability.read',
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]
    principalState.effectiveScope = {
      scopeType: 'cluster',
      scopeId: '01980f50-5f0d-7000-8000-000000000921',
      label: 'المجموعة',
    }

    mount(<RolesTab />)

    // Open the assignments resource, then the creation sheet.
    fireEvent.click(screen.getByRole('button', { name: 'التعيينات' }))
    fireEvent.click(screen.getByRole('button', { name: 'إضافة تعيين' }))

    // No scope-target query on mount (sheet open alone must not query).
    expect(searchScopeTargets).not.toHaveBeenCalled()

    // Open the scope combobox. The trigger is labelled by the visible
    // FormLabel («النطاق»), and the CommandInput carries the precise
    // accessible name; the generic `combobox` role is shared by the Radix
    // Select triggers in the sheet, so we query by name. `findByRole` waits
    // for the supporting account/role labels to resolve so the form renders.
    fireEvent.click(await screen.findByRole('button', { name: 'النطاق' }))
    expect(searchScopeTargets).not.toHaveBeenCalled()
    const scopeInput = await screen.findByRole('combobox', { name: 'ابحث عن النطاق' })

    // A single trimmed character must not fire a query either.
    fireEvent.change(scopeInput, { target: { value: 'ش' } })
    expect(searchScopeTargets).not.toHaveBeenCalled()

    // The second character triggers the query with the scope_type plus the
    // parent derived from the effective principal scope (cluster here).
    fireEvent.change(scopeInput, { target: { value: 'شما' } })
    await waitFor(() => {
      expect(searchScopeTargets).toHaveBeenCalledWith(
        expect.objectContaining({
          scopeType: 'unit',
          parentScopeType: 'cluster',
          parentScopeId: '01980f50-5f0d-7000-8000-000000000921',
          search: 'شما',
        }),
      )
    })
  })
})

describe('assignment activation lifecycle', () => {
  it('exposes Activate for a pending assignment and only transitions after AlertDialog confirmation', async () => {
    principalState.capabilities = [
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]
    const pendingAssignment = {
      id: '01980f50-5f0d-7000-8000-000000000951',
      subject_user_id: accountFixture.id,
      role_id: systemRole.id,
      scope_type: 'unit',
      scope_id: '01980f50-5f0d-7000-8000-000000000952',
      start_at: '2026-08-01T08:00:00Z',
      end_at: null,
      status: 'pending',
      effective_status: 'pending',
      lock_version: 4,
      allowed_actions: ['activate', 'revoke', 'expire'],
    }
    vi.mocked(listAssignments).mockResolvedValue({
      items: [pendingAssignment],
      next_cursor: null,
    })

    mount(<RolesTab />)

    // With only assignment capabilities the assignments resource is the
    // default tab and the pending row exposes Activate.
    const activate = await screen.findByRole('button', { name: 'تفعيل' })
    expect(activate).toBeInTheDocument()

    // Opening the confirmation dialog must not fire the mutation yet.
    fireEvent.click(activate)
    expect(await screen.findByText('تفعيل التعيين')).toBeInTheDocument()
    expect(screen.getByText(/سيُفعَّل التعيين فوراً/)).toBeInTheDocument()
    expect(transitionAdminResource).not.toHaveBeenCalled()

    // Confirmation dispatches the activate transition with the observed
    // lock version, CSRF token, and assignment idempotency key.
    fireEvent.click(screen.getByRole('button', { name: 'تأكيد' }))
    await waitFor(() => {
      expect(transitionAdminResource).toHaveBeenCalledWith(
        'role-assignments',
        pendingAssignment.id,
        'activate',
        undefined,
        pendingAssignment.lock_version,
        'x',
        'authorization-assignment-activate',
      )
    })
  })
})

describe('assignment creation window validation', () => {
  it('blocks submission without a start and with an end before the start', async () => {
    principalState.capabilities = [
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]
    principalState.effectiveScope = {
      scopeType: 'cluster',
      scopeId: '01980f50-5f0d-7000-8000-000000000921',
      label: 'المجموعة',
    }

    mount(<RolesTab />)

    fireEvent.click(await screen.findByRole('button', { name: 'إضافة تعيين' }))

    // Fill the account, role, and scope type so the window alone is in
    // question (the sheet renders once the supporting labels resolve).
    fireEvent.click(await screen.findByRole('combobox', { name: 'الحساب' }))
    fireEvent.click(await screen.findByRole('option', { name: 'مسؤول مالية' }))
    fireEvent.click(screen.getByRole('combobox', { name: 'الدور' }))
    fireEvent.click(await screen.findByRole('option', { name: 'مراجع مالي' }))

    // Missing start: submission is blocked client-side, no mutation fires.
    fireEvent.click(screen.getByRole('button', { name: 'إنشاء التعيين' }))
    expect(await screen.findByText('البداية مطلوبة.')).toBeInTheDocument()
    expect(createAssignment).not.toHaveBeenCalled()

    // An end at or before the start is rejected the same way.
    fireEvent.change(screen.getByLabelText('البداية'), {
      target: { value: '2026-08-02T10:00' },
    })
    fireEvent.change(screen.getByLabelText('النهاية'), {
      target: { value: '2026-08-02T09:00' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'إنشاء التعيين' }))
    expect(await screen.findByText('يجب أن تكون النهاية بعد البداية.')).toBeInTheDocument()
    expect(createAssignment).not.toHaveBeenCalled()
  })
})

describe('access workspace shell', () => {
  it('renders the bootstrap tab only while the normalized bootstrap state is pending', async () => {
    principalState.capabilities = ['authorization.bootstrap.complete']

    mount(<AccessScreen />)

    // Pending state => the fourth «التهيئة» tab appears.
    expect(await screen.findByRole('tab', { name: 'التهيئة' })).toBeInTheDocument()

    // A completed bootstrap state never renders the tab.
    vi.mocked(fetchBootstrapState).mockResolvedValue({
      status: 'completed',
      version: 2,
      allowedCapabilities: ['authorization.bootstrap.complete'],
      expiresAt: null,
      completedAt: '2026-08-01T10:00:00Z',
      completedByUserId: '01980f50-5f0d-7000-8000-000000000941',
    })
    mount(<AccessScreen />)
    await waitFor(() => {
      expect(screen.queryByRole('tab', { name: 'التهيئة' })).not.toBeInTheDocument()
    })
  })

  it('completes bootstrap only after AlertDialog confirmation with the observed version and then removes the tab', async () => {
    principalState.capabilities = [
      'identity.account.read',
      'authorization.bootstrap.complete',
    ]
    /* First GET: pending. After the completion mutation, the authoritative
     * refetch (triggered by invalidateQueries) must resolve to completed —
     * modelling the live backend so invalidation cannot reintroduce pending
     * state. */
    vi.mocked(fetchBootstrapState)
      .mockResolvedValueOnce({
        status: 'bootstrap_pending',
        version: 7,
        allowedCapabilities: ['authorization.bootstrap.complete'],
        expiresAt: '2026-08-08T00:00:00Z',
        completedAt: null,
        completedByUserId: null,
      })
      .mockResolvedValue({
        status: 'completed',
        version: 8,
        allowedCapabilities: ['authorization.bootstrap.complete'],
        expiresAt: null,
        completedAt: '2026-08-01T10:00:00Z',
        completedByUserId: '01980f50-5f0d-7000-8000-000000000941',
      })
    vi.mocked(completeBootstrap).mockResolvedValue({
      status: 'completed',
      version: 8,
      allowedCapabilities: ['authorization.bootstrap.complete'],
      expiresAt: null,
      completedAt: '2026-08-01T10:00:00Z',
      completedByUserId: '01980f50-5f0d-7000-8000-000000000941',
    })

    mount(<AccessScreen />)

    const bootstrapTab = await screen.findByRole('tab', { name: 'التهيئة' })
    fireEvent.mouseDown(bootstrapTab)

    // Reason is required before the final confirmation can proceed.
    const reason = await screen.findByRole('textbox', { name: 'سبب الإكمال' })
    fireEvent.change(reason, { target: { value: 'بدء التشغيل' } })
    fireEvent.submit(reason.closest('form')!)

    // No completion call until the explicit AlertDialog confirmation.
    expect(completeBootstrap).not.toHaveBeenCalled()
    const confirm = await screen.findByRole('button', { name: 'تأكيد' })
    fireEvent.click(confirm)

    await waitFor(() => {
      expect(completeBootstrap).toHaveBeenCalledWith('بدء التشغيل', 7, 'x')
    })

    // The tab disappears after completion (authoritative refetch resolved
    // completed) while the accounts tab stays active.
    await waitFor(() => {
      expect(screen.queryByRole('tab', { name: 'التهيئة' })).not.toBeInTheDocument()
    })
    expect(screen.getByRole('tab', { name: 'الحسابات' })).toHaveAttribute('data-state', 'active')
  })

  it('renders the shared non-disclosing denied state when no tab remains visible', async () => {
    principalState.capabilities = ['authorization.bootstrap.complete']
    vi.mocked(fetchBootstrapState).mockResolvedValue({
      status: 'completed',
      version: 2,
      allowedCapabilities: ['authorization.bootstrap.complete'],
      expiresAt: null,
      completedAt: '2026-08-01T10:00:00Z',
      completedByUserId: '01980f50-5f0d-7000-8000-000000000941',
    })

    mount(<AccessScreen />)

    await waitFor(() => {
      expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    })
  })
})

describe('diagnostics justification timeline', () => {
  it('renders the decision justification chain as an ordered timeline', async () => {
    principalState.capabilities = ['authorization.decision.read']

    mount(<AccessScreen />)

    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'تشخيص الوصول' }))

    const decisionId = await screen.findByLabelText('معرّف القرار')
    fireEvent.change(decisionId, { target: { value: '01980f50-5f0d-7000-8000-000000000942' } })
    fireEvent.click(screen.getByRole('button', { name: 'فحص' }))

    // Plain-language explanation plus the ordered timeline stages.
    expect(await screen.findByText(/Finance officer may read records/)).toBeInTheDocument()
    const timeline = screen.getByRole('list', { name: 'سلسلة التبرير' })
    expect(within(timeline).getByText('finance.system')).toBeInTheDocument()
    expect(within(timeline).getByText('audit')).toBeInTheDocument()
    expect(within(timeline).getByText('records.read.policy')).toBeInTheDocument()
    // IDs are secondary metadata: absent from the justification timeline's
    // primary chain, present only in the metadata list.
    expect(within(timeline).queryByText('01980f50-5f0d-7000-8000-000000000942')).not.toBeInTheDocument()
    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000942')).toBeInTheDocument()
  })
})
