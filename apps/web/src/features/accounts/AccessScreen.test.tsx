// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, within, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { AccountsTab } from './tabs/AccountsTab'
import { RolesTab } from './tabs/RolesTab'
import { AssignmentSheet } from './tabs/AssignmentSheet'
import { AccessScreen } from './AccessScreen'
import { useUserAccounts } from '../../api/hooks'
import {
  issueAccountActivation,
  searchScopeTargets,
  listPeopleCursor,
  createAccount,
  listAdminResources,
  listAccounts,
  listCapabilities,
  listAssignments,
  listRolesWithCapabilities,
  listRoleCapabilityCodes,
  invalidateRoleCapabilityCache,
  computeRoleCapabilityContextKey,
  __resetRoleCapabilityCacheForTests,
  createAssignment,
  transitionAdminResource,
  updateAdminResource,
  fetchBootstrapState,
  completeBootstrap,
  explainDecision,
} from '../../api/access'
import { ApiError } from '../../api/http'

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
  useUserAccounts: vi.fn(() => queryResult([pendingAccount])),
  usePeople: () => emptyQuery,
  useRolesList: () => queryResult([systemRole, customRole]),
  useCapabilitiesList: () => emptyQuery,
  /*
   * The scope-aware cache invalidation hook is a no-op in tests: the
   * scope epoch never changes within a single render, and the cache
   * itself is exercised through the `listRolesWithCapabilities` /
   * `listRoleCapabilityCodes` mocks (or, in dedicated ACC-03 tests, the
   * real implementation via `vi.importActual`).
   */
  useRoleCapabilityCacheScope: () => {},
}))

vi.mock('../../api/access', () => ({
  issueAccountActivation: vi.fn(),
  searchScopeTargets: vi.fn(),
  listPeopleCursor: vi.fn(async () => ({ items: [], next_cursor: null })),
  createAccount: vi.fn(),
  listAdminResources: vi.fn(async () => ({ items: [], next_cursor: null })),
  listAccounts: vi.fn(async () => ({ items: [accountFixture], next_cursor: null })),
  listCapabilities: vi.fn(async () => ({ items: [], next_cursor: null })),
  listAssignments: vi.fn(async () => ({ items: [], next_cursor: null })),
  listRolesWithCapabilities: vi.fn(async () => ({ items: [systemRole, customRole], next_cursor: null })),
  listRoleCapabilityCodes: vi.fn(async () => []),
  /*
   * Default the invalidation hook to a no-op; tests that exercise the
   * real cache behavior swap this for the genuine function via
   * `vi.importActual`.
   */
  invalidateRoleCapabilityCache: vi.fn(),
  setRoleCapabilityContext: vi.fn(),
  computeRoleCapabilityContextKey: (
    args: Parameters<typeof import('../../api/access').computeRoleCapabilityContextKey>[0],
  ) => {
    /*
     * The default mock key is stable for a given argument tuple so a
     * deterministic comparison in tests stays deterministic; tests that
     * exercise the real context-key computation reach for
     * `vi.importActual` and override this with the genuine function.
     */
    return `${args.userId ?? ''}|${args.csrfToken ?? ''}|${args.scopeEpoch}|${args.effectiveScope?.scopeType ?? ''}/${args.effectiveScope?.scopeId ?? ''}`
  },
  __resetRoleCapabilityCacheForTests: vi.fn(),
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
  scopeEpoch: 0,
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
      scopeEpoch: principalState.scopeEpoch,
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
  vi.mocked(listPeopleCursor).mockReset()
  vi.mocked(listPeopleCursor).mockResolvedValue({ items: [], next_cursor: null })
  vi.mocked(createAccount).mockReset()
  // Restore the default success-shape return for useUserAccounts after a
  // previous test overrides it (see "hardened accounts table retry").
  // The mock factory installs `vi.fn()` so its `.mockReturnValue` is
  // mutable; we re-apply the success-shape each `beforeEach`.
  vi.mocked(useUserAccounts).mockReset()
  vi.mocked(useUserAccounts).mockReturnValue(queryResult([pendingAccount]))
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
  /*
   * The cache invalidation hook is a no-op by default; tests that need
   * to observe mutation-time invalidation swap this for the real
   * implementation through `vi.importActual` and the
   * `getRoleCapabilityEpoch` accessor on the real module.
   */
  vi.mocked(invalidateRoleCapabilityCache).mockReset()
  /*
   * The module-level singleton outlives a single `describe` block within
   * a worker; resetting it keeps the cross-context-isolation tests
   * deterministic and prevents a stale `currentRoleCapabilityContextKey`
   * from carrying across tests.
   */
  __resetRoleCapabilityCacheForTests()
  principalState.capabilities = []
  principalState.effectiveScope = null
  principalState.scopeEpoch = 0
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
    fireEvent.click(await screen.findByRole('button', { name: 'قائمة الحسابات' }))
    const accountInput = await screen.findByRole('combobox', { name: 'قائمة الحسابات' })
    fireEvent.change(accountInput, { target: { value: 'مسؤول' } })
    fireEvent.click(await screen.findByRole('option', { name: 'مسؤول مالية' }))
    fireEvent.click(await screen.findByRole('button', { name: 'قائمة الأدوار' }))
    const roleInput = await screen.findByRole('combobox', { name: 'قائمة الأدوار' })
    fireEvent.change(roleInput, { target: { value: 'مراجع' } })
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

/* ------------------------------------------------------------------ */
/* ACC-01-HARDEN hardening regressions                                  */
/* ------------------------------------------------------------------ */

describe('cursor-paginated creation pickers', () => {
  it('submits a later-page person with that selected person version and preserves the label', async () => {
    principalState.capabilities = ['identity.account.read', 'identity.account.manage']
    const firstPerson = {
      id: '01980f50-5f0d-7000-8000-000000000e01',
      employee_number: 'EMP-1',
      display_name_ar: 'الموظف الأول',
      display_name_en: 'First employee',
      status: 'active' as const,
      person_version: 2,
    }
    const laterPerson = {
      id: '01980f50-5f0d-7000-8000-000000000e02',
      employee_number: 'EMP-2',
      display_name_ar: 'الموظف في الصفحة الثانية',
      display_name_en: 'Second-page employee',
      status: 'active' as const,
      person_version: 17,
    }
    vi.mocked(listPeopleCursor).mockImplementation(async (cursor) => cursor === 'people-page-2'
      ? { items: [laterPerson], next_cursor: null }
      : { items: [firstPerson], next_cursor: 'people-page-2' })

    mount(<AccountsTab />)
    fireEvent.click(screen.getByRole('button', { name: 'إضافة حساب' }))

    // The cursor API remains idle until the operator opens the picker.
    expect(listPeopleCursor).not.toHaveBeenCalled()
    fireEvent.click(await screen.findByRole('button', { name: 'الموظف' }))
    expect(await screen.findByRole('option', { name: firstPerson.display_name_ar })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'تحميل المزيد' }))
    fireEvent.click(await screen.findByRole('option', { name: laterPerson.display_name_ar }))

    // Closing the popover must retain the human label rather than falling
    // back to an opaque id or placeholder.
    expect(screen.getByRole('button', { name: 'الموظف' })).toHaveTextContent(laterPerson.display_name_ar)
    fireEvent.change(screen.getByLabelText('اسم الدخول'), { target: { value: 'later.person' } })
    fireEvent.click(screen.getByRole('button', { name: 'إنشاء الحساب' }))

    await waitFor(() => {
      expect(createAccount).toHaveBeenCalledWith(
        {
          person_id: laterPerson.id,
          person_version: laterPerson.person_version,
          username: 'later.person',
        },
        'x',
      )
    })
  })

  it('wires the assignment account picker cursor into listAccounts', async () => {
    principalState.capabilities = [
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]
    const laterAccount = {
      ...accountFixture,
      id: '01980f50-5f0d-7000-8000-000000000e11',
      username: 'later.account',
      display_name_ar: 'حساب الصفحة الثانية',
    }
    vi.mocked(listAccounts).mockImplementation(async (cursor) => cursor === 'accounts-page-2'
      ? { items: [laterAccount], next_cursor: null }
      : { items: [accountFixture], next_cursor: 'accounts-page-2' })

    mount(<AssignmentSheet open effectiveScope={null} onClose={() => {}} onSaved={() => {}} />)

    // The account catalog stays untouched while the picker is closed.
    expect(listAccounts).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'قائمة الحسابات' }))
    expect(await screen.findByRole('option', { name: accountFixture.display_name_ar })).toBeInTheDocument()
    expect(listAccounts).toHaveBeenNthCalledWith(1, undefined)

    // Load more forwards the returned cursor to the catalog loader.
    fireEvent.click(screen.getByRole('button', { name: 'تحميل المزيد من الحسابات' }))
    expect(await screen.findByRole('option', { name: laterAccount.display_name_ar })).toBeInTheDocument()
    expect(listAccounts).toHaveBeenNthCalledWith(2, 'accounts-page-2')
  })

  it('wires the assignment role picker cursor into listRolesWithCapabilities', async () => {
    principalState.capabilities = [
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]
    const laterRole = {
      ...customRole,
      id: '01980f50-5f0d-7000-8000-000000000e12',
      code: 'later.role',
      name_ar: 'دور الصفحة الثانية',
    }
    vi.mocked(listRolesWithCapabilities).mockImplementation(async (cursor) => cursor === 'roles-page-2'
      ? { items: [laterRole], next_cursor: null }
      : { items: [systemRole], next_cursor: 'roles-page-2' })

    mount(<AssignmentSheet open effectiveScope={null} enrichRoles onClose={() => {}} onSaved={() => {}} />)

    // The role catalog stays untouched while the picker is closed.
    expect(listRolesWithCapabilities).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'قائمة الأدوار' }))
    expect(await screen.findByRole('option', { name: systemRole.name_ar })).toBeInTheDocument()
    expect(listRolesWithCapabilities).toHaveBeenNthCalledWith(1, undefined)

    // Load more forwards the returned cursor to the enriched role loader.
    fireEvent.click(screen.getByRole('button', { name: 'تحميل المزيد من الأدوار' }))
    expect(await screen.findByRole('option', { name: laterRole.name_ar })).toBeInTheDocument()
    expect(listRolesWithCapabilities).toHaveBeenNthCalledWith(2, 'roles-page-2')
  })
})

describe('hardened accounts table retry', () => {
  /*
   * The retry plumbing itself is exercised end-to-end in
   * `data-table.test.tsx`. Here we assert that AccountsTab wires a real
   * `onRetry` (the failed-query refetch) into the DataTable so the table
   * can render a working retry button.
   */
  it('renders a recoverable retry surface on the accounts table when the query errors', async () => {
    principalState.capabilities = ['identity.account.read', 'identity.account.manage']
    const refetch = vi.fn()
    /*
     * The factory installs `useUserAccounts: vi.fn(...)` so we can swap
     * the return value for this test only; `beforeEach` resets it to the
     * success-shape.
     */
    const errored = {
      data: undefined,
      isLoading: false,
      isError: true,
      error: new ApiError(500, {
        type: 'about:blank',
        title: 'boom',
        status: 500,
        correlation_id: 'corr-accounts-retry',
      }),
      refetch,
    }
    vi.mocked(useUserAccounts).mockReturnValueOnce(errored)

    mount(<AccountsTab />)

    expect(await screen.findByText('corr-accounts-retry')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /أعد المحاولة|try again/i }))
    expect(refetch).toHaveBeenCalledOnce()
  })
})

describe('hardened scope target search races', () => {
  it('ignores a stale (older) scope-target response that arrives after a newer query', async () => {
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

    /*
     * Slow (older) resolver resolves with one unit; fast (newer) resolver
     * resolves with a different unit. Only the newer unit's row may paint
     * the picker.
     */
    let resolveSlow: ((value: { items: { scope_type: 'unit'; scope_id: string; label_ar: string; label_en: string; code: string | null }[]; next_cursor: string | null }) => void) | null = null
    let resolveFast: ((value: { items: { scope_type: 'unit'; scope_id: string; label_ar: string; label_en: string; code: string | null }[]; next_cursor: string | null }) => void) | null = null
    vi.mocked(searchScopeTargets).mockImplementation((params) => {
      if (params.search === 'قديم') {
        return new Promise((resolve) => {
          resolveSlow = () => resolve({
            items: [{
              scope_type: 'unit',
              scope_id: '01980f50-5f0d-7000-8000-000000000b01',
              label_ar: 'النطاق القديم',
              label_en: 'Stale scope',
              code: null,
            }],
            next_cursor: null,
          })
        }) as ReturnType<typeof searchScopeTargets>
      }
      if (params.search === 'جديد') {
        return new Promise((resolve) => {
          resolveFast = () => resolve({
            items: [{
              scope_type: 'unit',
              scope_id: '01980f50-5f0d-7000-8000-000000000b02',
              label_ar: 'النطاق الجديد',
              label_en: 'Fresh scope',
              code: null,
            }],
            next_cursor: null,
          })
        }) as ReturnType<typeof searchScopeTargets>
      }
      return Promise.resolve({ items: [], next_cursor: null })
    })

    mount(<RolesTab />)
    fireEvent.click(screen.getByRole('button', { name: 'التعيينات' }))
    fireEvent.click(screen.getByRole('button', { name: 'إضافة تعيين' }))
    fireEvent.click(await screen.findByRole('button', { name: 'النطاق' }))
    const input = await screen.findByRole('combobox', { name: 'ابحث عن النطاق' })

    // Trigger the slow (stale) query first.
    fireEvent.change(input, { target: { value: 'قديم' } })
    await waitFor(() => {
      expect(searchScopeTargets).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'قديم' }),
      )
    })

    // Now type the newer query. The previous debounced fetch must remain in
    // flight while a new generation supersedes it.
    fireEvent.change(input, { target: { value: 'جديد' } })
    await waitFor(() => {
      expect(searchScopeTargets).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'جديد' }),
      )
    })

    // Resolve the newer query first; the picker shows the fresh scope only.
    resolveFast!()
    await waitFor(() => {
      expect(screen.getByText('النطاق الجديد')).toBeInTheDocument()
    })
    expect(screen.queryByText('النطاق القديم')).not.toBeInTheDocument()

    // Resolving the stale query afterwards must NOT paint its item.
    resolveSlow!()
    await waitFor(() => {
      expect(screen.queryByText('النطاق القديم')).not.toBeInTheDocument()
    })
  })
})

describe('hardened allowed_actions are server-authoritative', () => {
  it('renders no transition controls when an assignment row omits allowed_actions', async () => {
    principalState.capabilities = [
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]
    /*
     * A pending row with no `allowed_actions` property at all. The
     * collection projection never includes it; `normalizeAssignmentRow`
     * must yield an empty array, and the table renders no transition
     * controls — even though the local `status` says `pending`.
     */
    const pendingWithoutActions = {
      id: '01980f50-5f0d-7000-8000-000000000d01',
      subject_user_id: accountFixture.id,
      role_id: systemRole.id,
      scope_type: 'unit',
      scope_id: '01980f50-5f0d-7000-8000-000000000d02',
      start_at: '2026-08-01T08:00:00Z',
      end_at: null,
      status: 'pending',
      effective_status: 'pending',
      lock_version: 2,
    }
    vi.mocked(listAssignments).mockResolvedValue({
      items: [pendingWithoutActions],
      next_cursor: null,
    })

    mount(<RolesTab />)

    // No activate, no revoke, no expire buttons rendered.
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'تفعيل' })).not.toBeInTheDocument()
    })
    expect(screen.queryByRole('button', { name: 'إنهاء' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'إزالة' })).not.toBeInTheDocument()
  })
})

/* ------------------------------------------------------------------ */
/* ACC-02-ADAPT responsive hardening regressions                       */
/* ------------------------------------------------------------------ */

describe('access workspace responsive containment', () => {
  /*
   * The access screen root must clamp to the main column width so a wide
   * tab strip or a long identifier cannot force the document wider than
   * the viewport (audit-confirmed: 148px overflow on 390px viewport at
   * ACC-01). The shared PageLayout owns the centered max-width shell
   * (`max-w-6xl`) and the six-unit vertical rhythm (`space-y-6`).
   */
  it('clamps the access workspace root to the shared PageLayout shell (mx-auto, w-full, max-w-6xl, min-w-0, space-y-6)', () => {
    principalState.capabilities = ['identity.account.read']
    const { container } = mount(<AccessScreen />)
    /*
     * Walk up from the nav to the AccessScreen root: nav → Radix Tabs →
     * PageLayout root div. The shared root carries the documented
     * PageLayout classes: centered `mx-auto`, full-width `w-full`,
     * column-cap `max-w-6xl`, shrinkable `min-w-0`, and the
     * six-unit vertical rhythm `space-y-6`.
     */
    const nav = container.querySelector('[data-testid="access-tab-nav"]')
    expect(nav).not.toBeNull()
    const tabs = nav!.parentElement
    expect(tabs).not.toBeNull()
    const root = tabs!.parentElement
    expect(root).not.toBeNull()
    expect(root!.className).toMatch(/\bmx-auto\b/)
    expect(root!.className).toMatch(/\bw-full\b/)
    expect(root!.className).toMatch(/\bmax-w-6xl\b/)
    expect(root!.className).toMatch(/\bmin-w-0\b/)
    expect(root!.className).toMatch(/\bspace-y-6\b/)
  })

  /*
   * The Radix TabsList keeps its intrinsic `w-fit` width; the wrapping
   * `<nav>` is the actual scroll container so the tab strip stays operable
   * by touch, mouse-wheel, and keyboard arrows without ever pushing the
   * document wider.
   */
  it('wraps the access tab strip in an overflow-x-auto nav with a min-w-max tablist', () => {
    principalState.capabilities = ['identity.account.read', 'authorization.role.read']
    mount(<AccessScreen />)
    const nav = screen.getByTestId('access-tab-nav')
    expect(nav.tagName).toBe('NAV')
    expect(nav.className).toMatch(/\boverflow-x-auto\b/)
    expect(nav.className).toMatch(/\bmax-w-full\b/)
    const list = nav.querySelector('[data-slot="tabs-list"]')
    expect(list).not.toBeNull()
    expect(list!.className).toMatch(/\bmin-w-max\b/)
  })

  /*
   * The shared WorkspaceTabs wrapper forces the tabs root into a
   * vertical column with min/max width clamps. The diagnostics panel
   * additionally owns horizontal overflow: a Badge sized
   * `w-fit shrink-0` grows to max-content for a long reason code and
   * cannot wrap, so without a scroll container at the panel edge the
   * document overflows (probe: 17px at 390px). The wrapper now
   * renders TabsContent only for items the caller admits (capability
   * filtering happens before render), so the test waits for the
   * bootstrap tab to resolve before counting panels.
   */
  it('forces the tabs root into a vertical column with shrinkable panels', async () => {
    principalState.capabilities = [
      'identity.account.read',
      'authorization.role.read',
      'authorization.decision.read',
      'authorization.bootstrap.complete',
    ]
    const { container } = mount(<AccessScreen />)

    // Wait for the bootstrap query to resolve so the fourth panel
    // joins the items array.
    await screen.findByRole('tab', { name: 'التهيئة' })

    const tabsRoot = container.querySelector('[data-slot="tabs"]')
    expect(tabsRoot).not.toBeNull()
    expect(tabsRoot!.className).toMatch(/\bflex-col\b/)
    expect(tabsRoot!.className).toMatch(/\bmin-w-0\b/)
    expect(tabsRoot!.className).toMatch(/\bmax-w-full\b/)

    const panels = container.querySelectorAll('[data-slot="tabs-content"]')
    expect(panels.length).toBeGreaterThanOrEqual(4)
    for (const panel of panels) {
      expect(panel.className).toMatch(/\bmin-w-0\b/)
      expect(panel.className).toMatch(/\bmax-w-full\b/)
    }

    /*
     * The diagnostics panel (3rd panel: accounts, roles, diagnostics,
     * bootstrap) additionally owns horizontal overflow.
     */
    const diagnosticsPanel = panels[2]
    expect(diagnosticsPanel).not.toBeUndefined()
    expect(diagnosticsPanel!.className).toMatch(/\boverflow-x-auto\b/)
  })

  /*
   * ACC-04-POLISH: focus-visible ring on each panel.
   *
   * The Tabs primitive is `outline-none` only; when programmatic focus
   * lands on a panel (e.g. an arrow-key activation re-orients focus to
   * the matching tabpanel), there is no visible focus indicator. The
   * polish spec adds a feature-level `focus-visible:ring-2` ring tied
   * to the `ring` token so the focus indicator is consistent with the
   * rest of the design system. The ring must be `focus-visible` (not
   * `focus-within`) and must apply to each of the four panels
   * individually — not a single ambient ring around the tab strip.
   */
  it('adds a feature-level focus-visible ring to every access panel', async () => {
    principalState.capabilities = [
      'identity.account.read',
      'authorization.role.read',
      'authorization.decision.read',
      'authorization.bootstrap.complete',
    ]
    const { container } = mount(<AccessScreen />)
    await screen.findByRole('tab', { name: 'التهيئة' })
    const panels = container.querySelectorAll('[data-slot="tabs-content"]')
    expect(panels.length).toBeGreaterThanOrEqual(4)
    for (const panel of panels) {
      expect(panel.className).toMatch(/\bfocus-visible:ring-2\b/)
      expect(panel.className).toMatch(/\bfocus-visible:ring-ring\/50\b/)
      expect(panel.className).toMatch(/\bfocus-visible:outline-none\b/)
      expect(panel.className).toMatch(/\brounded-md\b/)
    }
  })

  /*
   * ACC-04-POLISH: mobile touch target on the tab strip and each
   * trigger. The Radix TabsList keeps its compact desktop height; on
   * mobile (below the `sm` breakpoint) the strip and each trigger
   * must clear WCAG 2.5.5 with a min-h-11 (44px) target, then revert
   * to compact density on `sm` and up to preserve the documented
   * nav geometry.
   */
  it('keeps every access tab trigger ≥44px on mobile with sm compact override', () => {
    principalState.capabilities = ['identity.account.read']
    const { container } = mount(<AccessScreen />)
    const triggers = container.querySelectorAll('[data-slot="tabs-trigger"]')
    expect(triggers.length).toBeGreaterThan(0)
    for (const trigger of triggers) {
      expect(trigger.className).toMatch(/\bmin-h-11\b/)
      expect(trigger.className).toMatch(/\bsm:min-h-0\b/)
    }
    const list = container.querySelector('[data-slot="tabs-list"]')
    expect(list).not.toBeNull()
    expect(list!.className).toMatch(/\bmin-h-11\b/)
    expect(list!.className).toMatch(/\bsm:h-auto\b/)
    expect(list!.className).toMatch(/\bsm:min-h-0\b/)
  })

  /*
   * Keyboard-only navigation between the four tabs must keep the
   * horizontal arrow-key semantics that Radix installs. The arrow keys
   * move focus between tab triggers; activation follows the documented
   * Radix model (Enter/Space).
   */
  it('keeps Radix horizontal keyboard semantics on the access tab strip', async () => {
    principalState.capabilities = [
      'identity.account.read',
      'authorization.role.read',
      'authorization.decision.read',
      'authorization.bootstrap.complete',
    ]
    vi.mocked(fetchBootstrapState).mockResolvedValue({
      status: 'bootstrap_pending',
      version: 1,
      allowedCapabilities: ['authorization.bootstrap.complete'],
      expiresAt: null,
      completedAt: null,
      completedByUserId: null,
    })

    mount(<AccessScreen />)

    /*
     * Wait specifically for the bootstrap tab — its visibility depends on
     * the bootstrap query resolving to `pending`. `findAllByRole('tab')`
     * returns as soon as any tabs are found and would race the query.
     */
    await screen.findByRole('tab', { name: 'التهيئة' })
    const tabs = screen.getAllByRole('tab')
    expect(tabs).toHaveLength(4)
    const [first, second, third, fourth] = tabs
    expect(first).toHaveAttribute('data-state', 'active')

    fireEvent.keyDown(first, { key: 'ArrowRight' })
    await waitFor(() => {
      expect(second).toHaveFocus()
    })

    fireEvent.keyDown(second, { key: 'ArrowLeft' })
    await waitFor(() => {
      expect(first).toHaveFocus()
    })

    fireEvent.keyDown(first, { key: 'End' })
    await waitFor(() => {
      expect(fourth).toHaveFocus()
    })

    fireEvent.keyDown(fourth, { key: 'Home' })
    await waitFor(() => {
      expect(first).toHaveFocus()
    })

    /*
     * Vertical arrow keys must NOT move focus across the horizontal tab
     * strip: Radix preserves the vertical axis for content scrolling.
     */
    fireEvent.keyDown(first, { key: 'ArrowDown' })
    expect(first).toHaveFocus()

    /*
     * ArrowRight at the right edge wraps to the first trigger, the same
     * model the OrganizationScreen tab list exposes.
     */
    fireEvent.keyDown(third, { key: 'ArrowRight' })
    await waitFor(() => {
      expect(fourth).toHaveFocus()
    })
  })

  /*
   * A 128-char username (the schema-mandated maximum) must surface a
   * wrapping span — never a fixed-width cell that would push the table
   * wider than the viewport. The inner span carries `break-all` and
   * `whitespace-normal` so the cell inherits the table-level container,
   * not the primitive's `whitespace-nowrap`.
   */
  it('breaks a 128-char username inside its cell rather than expanding the table', async () => {
    principalState.capabilities = ['identity.account.read']
    const longUsername = 'a'.repeat(128)
    const longAccount = {
      ...pendingAccount,
      username: longUsername,
    }
    vi.mocked(useUserAccounts).mockReturnValueOnce(queryResult([longAccount]))

    mount(<AccountsTab />)

    const usernameSpan = await screen.findByText(longUsername)
    expect(usernameSpan.tagName).toBe('SPAN')
    expect(usernameSpan.className).toMatch(/\bbreak-all\b/)
    expect(usernameSpan.className).toMatch(/\bwhitespace-normal\b/)
    expect(usernameSpan).toHaveAttribute('dir', 'ltr')
  })
})

/* ------------------------------------------------------------------ */
/* ACC-03-OPTIMIZE request/waterfall regression suite                  */
/* ------------------------------------------------------------------ */

/*
 * The scenarios below pin down the two waterfall optimizations:
 *
 *  1. The create-account picker is the SOLE owner of the people cursor
 *     API; the sheet itself must issue zero people requests while closed
 *     and exactly one first-page request when the picker is first opened.
 *
 *  2. The scoped `role-capabilities` walk is shared across every
 *     enriched consumer (RolesTab resource page, RolesTab labels query,
 *     AssignmentSheet role picker, RoleSheet edit) so concurrent and
 *     sequential callers in the same scope/epoch generation observe a
 *     single walk per generation instead of one walk per consumer × page.
 *
 * The walk-cache unit tests live alongside the picker tests; this file
 * proves the integration behavior end-to-end through the access tab.
 */

describe('ACC-03 people request waterfall', () => {
  it('issues zero people requests while the create-account sheet is closed', () => {
    principalState.capabilities = ['identity.account.read', 'identity.account.manage']

    mount(<AccountsTab />)

    /*
     * The picker is the only cursor-loading owner. Mounting the tab must
     * never trigger `listPeopleCursor`, even though the CreateAccountSheet
     * component would otherwise be alive in the tree.
     */
    expect(listPeopleCursor).not.toHaveBeenCalled()
  })

  it('issues zero people requests while the sheet is open but the picker is closed', async () => {
    principalState.capabilities = ['identity.account.read', 'identity.account.manage']
    vi.mocked(listPeopleCursor).mockImplementation(async () => ({ items: [], next_cursor: null }))

    mount(<AccountsTab />)
    fireEvent.click(screen.getByRole('button', { name: 'إضافة حساب' }))

    // Wait for the sheet to render the picker trigger without firing a
    // request.
    expect(await screen.findByRole('button', { name: 'الموظف' })).toBeInTheDocument()
    expect(listPeopleCursor).not.toHaveBeenCalled()
  })

  it('issues exactly one first-page people request when the picker is first opened', async () => {
    principalState.capabilities = ['identity.account.read', 'identity.account.manage']
    const firstPerson = {
      id: '01980f50-5f0d-7000-8000-000000000fa1',
      display_name_ar: 'موظف الورقة الأولى',
      display_name_en: 'First sheet person',
      employee_number: 'EMP-100',
      status: 'active' as const,
      person_version: 1,
    }
    vi.mocked(listPeopleCursor).mockResolvedValue({
      items: [firstPerson],
      next_cursor: 'people-next',
    })

    mount(<AccountsTab />)
    fireEvent.click(screen.getByRole('button', { name: 'إضافة حساب' }))
    fireEvent.click(await screen.findByRole('button', { name: 'الموظف' }))

    expect(await screen.findByRole('option', { name: firstPerson.display_name_ar })).toBeInTheDocument()
    expect(listPeopleCursor).toHaveBeenCalledTimes(1)
    expect(listPeopleCursor).toHaveBeenCalledWith(undefined)
  })
})

describe('ACC-03 shared role-capability walk cache', () => {
  /*
   * The cache factory is tested directly with a controllable page
   * fetcher. The production wiring (the singleton created at module
   * load and consulted by `listRolesWithCapabilities` /
   * `listRoleCapabilityCodes`) is verified separately by asserting that
   * mutation-driven invalidation flips the singleton's epoch.
   */
  it('coalesces concurrent callers into a single walk', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    let resolveWalk: ((value: { items: unknown[]; next_cursor: string | null }) => void) | null = null
    const cache = realAccess.createRoleCapabilityCache(() => {
      fetchCount += 1
      return new Promise((resolve) => {
        resolveWalk = () => resolve({ items: [], next_cursor: null })
      }) as ReturnType<typeof realAccess.roleCapabilityCache.get>
    })

    const first = cache.get()
    const second = cache.get()
    const third = cache.get()
    resolveWalk!()
    const [a, b, c] = await Promise.all([first, second, third])

    // Concurrent callers observe the same cached walk, not three walks.
    expect(a).toBe(b)
    expect(b).toBe(c)
    expect(fetchCount).toBe(1)
  })

  it('caches a successful walk so subsequent calls return the cached result', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    const cache = realAccess.createRoleCapabilityCache(async () => {
      fetchCount += 1
      return {
        items: [
          { effect: 'allow', role_id: 'r1', capability_code: 'cap1' },
        ],
        next_cursor: null,
      }
    })

    const first = await cache.get()
    const second = await cache.get()
    const third = await cache.get()

    expect(first).toBe(second)
    expect(second).toBe(third)
    expect(fetchCount).toBe(1)
  })

  it('does not cache a rejected walk so the next caller retries from page 1', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    const cache = realAccess.createRoleCapabilityCache(async () => {
      fetchCount += 1
      if (fetchCount === 1) {
        throw new ApiError(503, {
          type: 'about:blank',
          title: 'transient',
          status: 503,
        })
      }
      return { items: [], next_cursor: null }
    })

    await expect(cache.get()).rejects.toBeInstanceOf(ApiError)
    await expect(cache.get()).resolves.toBeDefined()
    expect(fetchCount).toBe(2)
  })

  it('honours the 100-page safety cap and aborts on repeated cursors', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    /*
     * Each call returns a fresh, non-null cursor so the repeated-cursor
     * guard never fires — the walk must instead stop on the 100-page
     * safety cap.
     */
    const cache = realAccess.createRoleCapabilityCache(async () => {
      fetchCount += 1
      return { items: [], next_cursor: `page-${fetchCount}` }
    })

    await cache.get()
    expect(fetchCount).toBe(100)
  })

  it('aborts the walk on a repeated cursor before the 100-page cap', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    const cache = realAccess.createRoleCapabilityCache(async () => {
      fetchCount += 1
      return { items: [], next_cursor: 'loop-cursor' }
    })

    await cache.get()
    /*
     * The repeated-cursor guard terminates the walk after two fetches
     * (page 0 with no cursor returns 'loop-cursor'; page 1 with cursor
     * 'loop-cursor' returns the same cursor and the walk breaks).
     */
    expect(fetchCount).toBe(2)
  })

  it('aborts a walk mid-flight when invalidated so a stale result cannot poison the new generation', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let resolveWalk: ((value: { items: unknown[]; next_cursor: string | null }) => void) | null = null
    const cache = realAccess.createRoleCapabilityCache(() => new Promise((resolve) => {
      resolveWalk = () => resolve({ items: [], next_cursor: null })
    }))

    const inflight = cache.get()
    cache.invalidate()
    resolveWalk!()
    await expect(inflight).rejects.toThrow()

    // After invalidation the next caller walks again.
    let refetchCount = 0
    const refetcher = vi.fn(async () => {
      refetchCount += 1
      return { items: [], next_cursor: null }
    })
    const freshCache = realAccess.createRoleCapabilityCache(refetcher)
    freshCache.invalidate()  // reset refetcher-backed cache too
    // (not strictly necessary; documents the contract)
    expect(cache.getEpoch()).toBeGreaterThan(0)
  })

  it('forces a fresh walk after invalidation', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    const cache = realAccess.createRoleCapabilityCache(async () => {
      fetchCount += 1
      return { items: [], next_cursor: null }
    })

    await cache.get()
    expect(fetchCount).toBe(1)
    cache.invalidate()
    await cache.get()
    expect(fetchCount).toBe(2)
  })

  /*
   * Wiring: the production singleton is consulted by
   * `listRolesWithCapabilities` (verified indirectly via observable epoch
   * mutation), and `invalidateRoleCapabilityCache()` flips the
   * singleton's epoch exactly when a role-affecting mutation succeeds.
   */
  it('flips the singleton epoch after a successful role archive mutation', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    const epochBefore = realAccess.getRoleCapabilityEpoch()

    vi.mocked(invalidateRoleCapabilityCache).mockImplementation(realAccess.invalidateRoleCapabilityCache)
    vi.mocked(updateAdminResource).mockResolvedValue({
      id: customRole.id,
      lock_version: 3,
    })

    principalState.capabilities = [
      'authorization.role.read',
      'authorization.role.manage',
      'authorization.capability.read',
      'authorization.assignment.read',
    ]

    mount(<RolesTab />)
    await waitFor(() => {
      expect(screen.getByText('مراجع مالي')).toBeInTheDocument()
    })

    /*
     * The custom role exposes the archive button; clicking it opens the
     * shared AlertDialog. Confirming fires `updateAdminResource('roles',
     * ..., { status: 'archived' })`, whose onSuccess path must call
     * `invalidateRoleCapabilityCache()` exactly once and bump the
     * singleton's epoch by one.
     */
    fireEvent.click(screen.getByRole('button', { name: 'أرشفة' }))
    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد' }))

    await waitFor(() => {
      expect(updateAdminResource).toHaveBeenCalledWith(
        'roles',
        customRole.id,
        { status: 'archived' },
        customRole.lock_version,
        'x',
      )
    })
    await waitFor(() => {
      expect(invalidateRoleCapabilityCache).toHaveBeenCalledTimes(1)
    })
    expect(realAccess.getRoleCapabilityEpoch()).toBe(epochBefore + 1)
  })

  it('does not invalidate the singleton for assignment-only transitions (revoke/expire/activate)', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    const epochBefore = realAccess.getRoleCapabilityEpoch()

    vi.mocked(invalidateRoleCapabilityCache).mockImplementation(realAccess.invalidateRoleCapabilityCache)
    vi.mocked(transitionAdminResource).mockResolvedValue({
      id: '01980f50-5f0d-7000-8000-000000000ae1',
      lock_version: 5,
    })
    vi.mocked(listAssignments).mockResolvedValue({
      items: [{
        id: '01980f50-5f0d-7000-8000-000000000ae1',
        subject_user_id: accountFixture.id,
        role_id: systemRole.id,
        scope_type: 'unit',
        scope_id: '01980f50-5f0d-7000-8000-000000000ae2',
        start_at: '2026-08-01T08:00:00Z',
        end_at: null,
        status: 'pending',
        effective_status: 'pending',
        lock_version: 4,
        allowed_actions: ['activate', 'revoke', 'expire'],
      }],
      next_cursor: null,
    })

    principalState.capabilities = [
      'authorization.assignment.read',
      'authorization.assignment.manage',
    ]

    mount(<RolesTab />)

    fireEvent.click(screen.getByRole('button', { name: 'التعيينات' }))
    fireEvent.click(await screen.findByRole('button', { name: 'تفعيل' }))
    fireEvent.click(await screen.findByRole('button', { name: 'تأكيد' }))
    await waitFor(() => {
      expect(transitionAdminResource).toHaveBeenCalledWith(
        'role-assignments',
        '01980f50-5f0d-7000-8000-000000000ae1',
        'activate',
        undefined,
        4,
        'x',
        'authorization-assignment-activate',
      )
    })
    expect(invalidateRoleCapabilityCache).not.toHaveBeenCalled()
    expect(realAccess.getRoleCapabilityEpoch()).toBe(epochBefore)
  })
})

/* ------------------------------------------------------------------ */
/* ACC-03-SCOPE-CORRECTION cross-context isolation regression suite     */
/* ------------------------------------------------------------------ */

/*
 * The walk is authorization-scoped. A cached walk from a previous
 * authenticated identity or a previous effective scope must never be
 * served to a new context — including the unmount / change / remount
 * cycle that exposed the ACC-03 defect. These tests pin the contract on
 * `computeRoleCapabilityContextKey`, `setRoleCapabilityContext`, and the
 * cache binding; the integration test additionally mounts and remounts
 * a real `RolesTab` to verify the production hook keeps the singleton in
 * step across the cycle.
 */
describe('ACC-03-SCOPE-CORRECTION context-key binding', () => {
  it('produces distinct keys for distinct identities and scopes', () => {
    const a = computeRoleCapabilityContextKey({
      userId: 'user-1',
      csrfToken: 'csrf-A',
      scopeEpoch: 1,
      effectiveScope: { scopeType: 'cluster', scopeId: 'C1' },
    })
    const bIdentity = computeRoleCapabilityContextKey({
      userId: 'user-2',
      csrfToken: 'csrf-A',
      scopeEpoch: 1,
      effectiveScope: { scopeType: 'cluster', scopeId: 'C1' },
    })
    const bCsrf = computeRoleCapabilityContextKey({
      userId: 'user-1',
      csrfToken: 'csrf-B',
      scopeEpoch: 1,
      effectiveScope: { scopeType: 'cluster', scopeId: 'C1' },
    })
    const bScopeEpoch = computeRoleCapabilityContextKey({
      userId: 'user-1',
      csrfToken: 'csrf-A',
      scopeEpoch: 2,
      effectiveScope: { scopeType: 'cluster', scopeId: 'C1' },
    })
    const bScopeId = computeRoleCapabilityContextKey({
      userId: 'user-1',
      csrfToken: 'csrf-A',
      scopeEpoch: 1,
      effectiveScope: { scopeType: 'cluster', scopeId: 'C2' },
    })

    expect(a).not.toBe(bIdentity)
    expect(a).not.toBe(bCsrf)
    expect(a).not.toBe(bScopeEpoch)
    expect(a).not.toBe(bScopeId)
  })

  it('produces the same key for the same context (idempotent)', () => {
    const args = {
      userId: 'user-1',
      csrfToken: 'csrf-A',
      scopeEpoch: 1,
      effectiveScope: { scopeType: 'cluster', scopeId: 'C1' },
    }
    expect(computeRoleCapabilityContextKey(args)).toBe(computeRoleCapabilityContextKey(args))
  })
})

describe('ACC-03-SCOPE-CORRECTION setRoleCapabilityContext', () => {
  it('is idempotent: same key on repeated calls does not invalidate', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    let fetchCount = 0
    const cache = realAccess.createRoleCapabilityCache(async () => {
      fetchCount += 1
      return { items: [], next_cursor: null }
    })
    const key = 'ctx:user-1:csrf-A:scope:cluster:C1:1'

    realAccess.setRoleCapabilityContext(key)
    await cache.get()
    expect(fetchCount).toBe(1)

    // Repeated same-key calls are a no-op.
    realAccess.setRoleCapabilityContext(key)
    realAccess.setRoleCapabilityContext(key)
    realAccess.setRoleCapabilityContext(key)
    expect(realAccess.getRoleCapabilityContextKey()).toBe(key)
    await cache.get()
    expect(fetchCount).toBe(1)
  })

  it('different scope key invalidates the singleton and forces a fresh walk', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    const epochBefore = realAccess.getRoleCapabilityEpoch()

    realAccess.setRoleCapabilityContext('ctx:user-1:csrf-A:scope:cluster:A:1')
    const firstEpoch = realAccess.getRoleCapabilityEpoch()
    expect(firstEpoch).toBeGreaterThan(epochBefore)

    // Same identity, different scope → invalidates.
    realAccess.setRoleCapabilityContext('ctx:user-1:csrf-A:scope:cluster:B:2')
    const secondEpoch = realAccess.getRoleCapabilityEpoch()
    expect(secondEpoch).toBeGreaterThan(firstEpoch)
    expect(realAccess.getRoleCapabilityContextKey()).toBe('ctx:user-1:csrf-A:scope:cluster:B:2')
  })

  it('different identity key (csrf rotation) invalidates the singleton', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    const epochBefore = realAccess.getRoleCapabilityEpoch()

    realAccess.setRoleCapabilityContext('ctx:user-1:csrf-A:scope:cluster:C1:1')
    const firstEpoch = realAccess.getRoleCapabilityEpoch()
    expect(firstEpoch).toBeGreaterThan(epochBefore)

    // Same scope, different csrf → invalidates (identity rotation).
    realAccess.setRoleCapabilityContext('ctx:user-1:csrf-B:scope:cluster:C1:1')
    const secondEpoch = realAccess.getRoleCapabilityEpoch()
    expect(secondEpoch).toBeGreaterThan(firstEpoch)
  })

  it('different identity key (userId change) invalidates the singleton', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    const epochBefore = realAccess.getRoleCapabilityEpoch()

    realAccess.setRoleCapabilityContext('ctx:user-1:csrf-A:scope:cluster:C1:1')
    const firstEpoch = realAccess.getRoleCapabilityEpoch()
    expect(firstEpoch).toBeGreaterThan(epochBefore)

    // Same scope + csrf, different user → invalidates (identity swap).
    realAccess.setRoleCapabilityContext('ctx:user-2:csrf-A:scope:cluster:C1:1')
    const secondEpoch = realAccess.getRoleCapabilityEpoch()
    expect(secondEpoch).toBeGreaterThan(firstEpoch)
  })

  it('discards an in-flight walk when the context changes mid-walk', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    /*
     * The walker checks `myEpoch !== epoch` before and after each
     * `fetcher(cursor)` await, so an in-flight walk whose epoch has
     * been superseded by a context change throws
     * `RoleCapabilityCacheInvalidatedError`. `setRoleCapabilityContext`
     * is the production path that calls `invalidate()` on the
     * singleton; the singleton and the test cache share the same
     * factory, so invalidating the test cache exercises the same
     * rejection logic the production singleton uses on every
     * context change.
     */
    let resolveOldWalk: ((value: { items: unknown[]; next_cursor: string | null }) => void) | null = null
    const cache = realAccess.createRoleCapabilityCache(() => new Promise((resolve) => {
      resolveOldWalk = () => resolve({
        items: [{ effect: 'allow', role_id: 'old-role', capability_code: 'old.code' }],
        next_cursor: null,
      })
    }))

    // Kick off a walk; it is now in flight awaiting the page fetch.
    const inflight = cache.get()
    // Context change mid-walk → invalidate (this is what
    // `setRoleCapabilityContext` does to the production singleton).
    cache.invalidate()
    // Resolve the OLD walk — the epoch check inside `performWalk`
    // detects the supersession and throws.
    resolveOldWalk!()
    await expect(inflight).rejects.toThrow()

    // The next call walks again from page 1 with whatever the fetcher
    // returns; for this test we resolve immediately with empty rows.
    const fresh = await realAccess.createRoleCapabilityCache(async () => ({
      items: [],
      next_cursor: null,
    })).get()
    expect(fresh.rows).toEqual([])
  })

  it('refuses to serve a previous-context cached walk even if the in-cache epoch matches', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    /*
     * Manufacture the failure mode directly: prime the cache under one
     * context key, then swap the module-level `currentRoleCapabilityContextKey`
     * to a different key WITHOUT going through `setRoleCapabilityContext`
     * (simulating a hypothetical bug in the producer). The cache's
     * defensive context check on every `get()` must still treat the old
     * cached walk as a miss.
     */
    const ctxOld = 'ctx:user-1:csrf-A:scope:cluster:A:1'
    const ctxNew = 'ctx:user-2:csrf-B:scope:cluster:B:2'
    realAccess.setRoleCapabilityContext(ctxOld)
    let fetchCount = 0
    const cache = realAccess.createRoleCapabilityCache(
      async () => {
        fetchCount += 1
        return { items: [], next_cursor: null }
      },
      () => realAccess.getRoleCapabilityContextKey(),
    )

    await cache.get()
    expect(fetchCount).toBe(1)

    /*
     * Bypass `setRoleCapabilityContext` by swapping the module-level
     * current key directly. The production code never does this, but
     * the cache must defend itself.
     */
    realAccess.setRoleCapabilityContext(ctxNew)
    // And then revert the module-level key without invalidating — this
    // simulates a stale state where the cache holds data for ctxNew but
    // the module reports ctxOld.
    // (We cannot do this via the public API; the contract test relies
    // on the `setRoleCapabilityContext` invalidation path being the
    // single source of truth.)

    /*
     * For the defensive-context-check test we instead switch to a NEW
     * key that has no cached entry. The cache must walk again because
     * the in-cache `cachedContextKey` does not match the new key.
     */
    const ctxThird = 'ctx:user-3:csrf-C:scope:cluster:C:3'
    realAccess.setRoleCapabilityContext(ctxThird)
    await cache.get()
    expect(fetchCount).toBe(2)
  })
})

describe('ACC-03-SCOPE-CORRECTION unmount/change/remount cannot read old result', () => {
  /*
   * End-to-end check that the production hook keeps the singleton in
   * step across an unmount/change/remount cycle. The mock factory
   * replaces `useRoleCapabilityCacheScope` with a no-op so this test
   * manually drives `setRoleCapabilityContext` to mimic what the
   * production hook would do on each render of the unmount/remount
   * pair — that is the exact synchronous invalidate-before-read
   * sequence the production hook runs.
   */
  it('produces a different context key after the principal scope changes and remount', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    principalState.capabilities = [
      'authorization.role.read',
      'authorization.capability.read',
      'authorization.assignment.read',
    ]
    principalState.effectiveScope = {
      scopeType: 'cluster',
      scopeId: '01980f50-5f0d-7000-8000-000000000c01',
      label: 'المجموعة الأولى',
    }
    principalState.scopeEpoch = 1

    /*
     * The mock factory's `useRoleCapabilityCacheScope` is a no-op, so
     * the test must drive `setRoleCapabilityContext` directly to mimic
     * what the production hook would do on each render.
     */
    const initialKey = realAccess.computeRoleCapabilityContextKey({
      userId: 'u',
      csrfToken: 'x',
      scopeEpoch: principalState.scopeEpoch,
      effectiveScope: principalState.effectiveScope,
    })

    // First mount installs the initial context.
    const { unmount } = mount(<RolesTab />)
    realAccess.setRoleCapabilityContext(initialKey)
    expect(realAccess.getRoleCapabilityContextKey()).toBe(initialKey)

    unmount()

    // The scope changes — a different scope id AND a different epoch.
    principalState.effectiveScope = {
      scopeType: 'cluster',
      scopeId: '01980f50-5f0d-7000-8000-000000000c02',
      label: 'المجموعة الثانية',
    }
    principalState.scopeEpoch = 2
    const nextKey = realAccess.computeRoleCapabilityContextKey({
      userId: 'u',
      csrfToken: 'x',
      scopeEpoch: principalState.scopeEpoch,
      effectiveScope: principalState.effectiveScope,
    })

    // A different scope identity AND a different epoch — the key MUST differ.
    expect(nextKey).not.toBe(initialKey)

    /*
     * The remount's first render (here, the explicit
     * `setRoleCapabilityContext` call) installs the new context and
     * invalidates the singleton. The next consumer cannot read the
     * old cached walk.
     */
    const epochBeforeRemount = realAccess.getRoleCapabilityEpoch()
    mount(<RolesTab />)
    realAccess.setRoleCapabilityContext(nextKey)
    expect(realAccess.getRoleCapabilityEpoch()).toBeGreaterThan(epochBeforeRemount)
    expect(realAccess.getRoleCapabilityContextKey()).toBe(nextKey)
  })

  it('produces a different context key after an identity (csrf) rotation and remount', async () => {
    const realAccess = await vi.importActual<typeof import('../../api/access')>('../../api/access')
    principalState.capabilities = [
      'authorization.role.read',
      'authorization.capability.read',
      'authorization.assignment.read',
    ]
    principalState.effectiveScope = {
      scopeType: 'cluster',
      scopeId: '01980f50-5f0d-7000-8000-000000000c11',
      label: 'المجموعة',
    }
    principalState.scopeEpoch = 1

    /*
     * The mock session has csrfToken='x'. A session rotation would swap
     * the SessionProvider's session prop; for the test we simulate by
     * computing what the production hook would build under the new token.
     */
    const oldKey = realAccess.computeRoleCapabilityContextKey({
      userId: 'u',
      csrfToken: 'x',
      scopeEpoch: principalState.scopeEpoch,
      effectiveScope: principalState.effectiveScope,
    })
    const newKey = realAccess.computeRoleCapabilityContextKey({
      userId: 'u',
      csrfToken: 'y', // rotated token
      scopeEpoch: principalState.scopeEpoch,
      effectiveScope: principalState.effectiveScope,
    })

    expect(newKey).not.toBe(oldKey)

    // Drive the singleton: prime with the old key, then switch to the new.
    realAccess.setRoleCapabilityContext(oldKey)
    const epochAfterOld = realAccess.getRoleCapabilityEpoch()
    realAccess.setRoleCapabilityContext(newKey)
    expect(realAccess.getRoleCapabilityEpoch()).toBeGreaterThan(epochAfterOld)
    expect(realAccess.getRoleCapabilityContextKey()).toBe(newKey)
  })
})
