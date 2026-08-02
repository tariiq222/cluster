// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, within, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { RoleFormScreen } from './RoleFormScreen'
import { CAPABILITY_LABELS } from './accounts-copy'
import { ApiError } from '../../api/http'

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

vi.mock('../../api/access', () => ({
  getAdminResource: vi.fn(),
  listRoleCapabilityCodes: vi.fn(),
  listAllCapabilities: vi.fn(),
  createAdminResource: vi.fn(),
  updateAdminResource: vi.fn(),
  invalidateRoleCapabilityCache: vi.fn(),
}))

import {
  getAdminResource,
  listRoleCapabilityCodes,
  listAllCapabilities,
  createAdminResource,
  updateAdminResource,
  invalidateRoleCapabilityCache,
} from '../../api/access'

const capabilityRows = [
  {
    id: '01980f50-5f0d-7000-8000-000000000c01',
    code: 'work_record.read',
    capability_code: 'work_record.read',
    module_code: 'work_record',
    action: 'read',
    sensitivity: 'normal',
    group_label: 'work_record',
    lock_version: 1,
  },
  {
    id: '01980f50-5f0d-7000-8000-000000000c02',
    code: 'work_record.create',
    capability_code: 'work_record.create',
    module_code: 'work_record',
    action: 'create',
    sensitivity: 'normal',
    group_label: 'work_record',
    lock_version: 1,
  },
  {
    id: '01980f50-5f0d-7000-8000-000000000c03',
    code: 'documents.grant',
    capability_code: 'documents.grant',
    module_code: 'documents',
    action: 'grant',
    sensitivity: 'sensitive',
    group_label: 'documents',
    lock_version: 1,
  },
  {
    id: '01980f50-5f0d-7000-8000-000000000c04',
    code: 'identity.account.read',
    capability_code: 'identity.account.read',
    module_code: 'identity',
    action: 'read',
    sensitivity: 'internal',
    group_label: 'identity',
    lock_version: 1,
  },
] as const

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

function mount(node: ReactNode, locale: 'ar' | 'en' = 'ar') {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale={locale} setLocale={() => {}}>
        {node}
      </SessionProvider>
    </QueryClientProvider>,
  )
}

const manageCapabilities = [
  'authorization.role.read',
  'authorization.role.manage',
  'authorization.capability.read',
  'authorization.assignment.read',
]

function groupCheckboxOf(groupLabel: string) {
  const label = screen.getByText(groupLabel).closest('label')
  if (!label) throw new Error(`No group label ${groupLabel}`)
  return within(label as HTMLElement).getByRole('checkbox')
}

beforeEach(() => {
  navigateMock.mockReset()
  vi.mocked(getAdminResource).mockReset()
  vi.mocked(listRoleCapabilityCodes).mockReset()
  vi.mocked(listAllCapabilities).mockReset()
  vi.mocked(listAllCapabilities).mockResolvedValue([...capabilityRows])
  vi.mocked(createAdminResource).mockReset()
  vi.mocked(updateAdminResource).mockReset()
  vi.mocked(invalidateRoleCapabilityCache).mockReset()
  principalState.capabilities = []
})

describe('role form page gating', () => {
  it('renders the shared non-disclosing denied state without the reconstruct capabilities', () => {
    principalState.capabilities = ['authorization.role.read']
    mount(<RoleFormScreen />)
    expect(screen.getByTestId('role-form-screen')).toBeInTheDocument()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    expect(listAllCapabilities).not.toHaveBeenCalled()
  })
})

describe('role form page — create', () => {
  it('loads the full catalog, groups by module, searches, and submits the selected set', async () => {
    principalState.capabilities = [...manageCapabilities]
     mount(<RoleFormScreen />)

     expect(screen.getByTestId('role-form')).toBeInTheDocument()
     expect(screen.getByTestId('role-form-main')).toBeInTheDocument()
     expect(screen.getByTestId('role-form-review')).toBeInTheDocument()
     expect(screen.queryByText('1 / 4 صلاحية')).not.toBeInTheDocument()
     expect(document.querySelector('.sticky.bottom-0')).toBeNull()

     // The full catalog is requested (the walk helper pages through cursors).

    await waitFor(() => expect(listAllCapabilities).toHaveBeenCalledTimes(1))

    // Both localized group headings render with their item counts.
    expect(await screen.findByText('سجلات العمل')).toBeInTheDocument()
    expect(screen.getByText('الهوية والحسابات')).toBeInTheDocument()

    // Capabilities render their Arabic human labels, never raw codes.
    expect(screen.getByText('قراءة سجل عمل')).toBeInTheDocument()
    expect(screen.getByText('إنشاء سجل عمل')).toBeInTheDocument()
    expect(screen.getByText('منح وصول لمستند')).toBeInTheDocument()
    expect(screen.getByText('قراءة حسابات الدخول')).toBeInTheDocument()
    expect(screen.queryByText('work_record.read')).not.toBeInTheDocument()
    expect(screen.queryByText('documents.grant')).not.toBeInTheDocument()
    expect(screen.queryByText('identity.account.read')).not.toBeInTheDocument()
    expect(screen.queryByText('work_record')).not.toBeInTheDocument()

    // Sensitive capabilities carry the ShieldAlert marker.
    expect(document.querySelector('[data-testid="role-form-screen"] svg.lucide-shield-alert')).not.toBeNull()

    // Select the whole identity group via its group checkbox.
    fireEvent.click(groupCheckboxOf('الهوية والحسابات'))
    expect(screen.getByRole('checkbox', { name: 'قراءة حسابات الدخول' })).toBeChecked()

    // The counter reflects the selection (1 of 4) in the header and footer.
    await waitFor(() => {
      expect(screen.getAllByText(/1 \/ 4 صلاحية/).length).toBeGreaterThan(0)
    })

    // Search by the canonical code still narrows to the identity group only.
    fireEvent.change(screen.getByRole('searchbox', { name: 'بحث الصلاحيات' }), {
      target: { value: 'identity' },
    })
    expect(screen.getByText('قراءة حسابات الدخول')).toBeInTheDocument()
    expect(screen.queryByText('قراءة سجل عمل')).not.toBeInTheDocument()
    fireEvent.change(screen.getByRole('searchbox', { name: 'بحث الصلاحيات' }), {
      target: { value: '' },
    })

    // Fill the role fields and submit.
    fireEvent.change(screen.getByLabelText('رمز الدور'), { target: { value: 'finance.custom' } })
    fireEvent.change(screen.getByLabelText('اسم الدور'), { target: { value: 'مراجع مالي مخصص' } })
    fireEvent.click(screen.getByRole('button', { name: 'أنشئ الدور' }))

    await waitFor(() => {
      expect(createAdminResource).toHaveBeenCalledWith(
        'roles',
        {
          resource_type: 'role',
          code: 'finance.custom',
          name: 'مراجع مالي مخصص',
          capability_codes: ['identity.account.read'],
        },
        'x',
        'authorization-role',
      )
    })
    expect(invalidateRoleCapabilityCache).toHaveBeenCalled()
    expect(navigateMock).toHaveBeenCalledWith('/access?tab=roles')
  })

  it('clears a whole group with the group checkbox when everything is selected', async () => {
    principalState.capabilities = [...manageCapabilities]
    mount(<RoleFormScreen />)

    await screen.findByText('سجلات العمل')
    fireEvent.click(groupCheckboxOf('سجلات العمل'))
    expect(screen.getByRole('checkbox', { name: 'قراءة سجل عمل' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'إنشاء سجل عمل' })).toBeChecked()

    // The group checkbox now reads as «إلغاء تحديد المجموعة» and clears.
    fireEvent.click(groupCheckboxOf('سجلات العمل'))
    expect(screen.getByRole('checkbox', { name: 'قراءة سجل عمل' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'إنشاء سجل عمل' })).not.toBeChecked()
  })

  it('keeps the canonical codes visible as secondary technical lines in English', async () => {
    principalState.capabilities = [...manageCapabilities]
    mount(<RoleFormScreen />, 'en')

    // Human labels are the primary lines…
    expect(await screen.findByText('Read work record')).toBeInTheDocument()
    expect(screen.getByText('Read sign-in accounts')).toBeInTheDocument()

    // …and the canonical codes stay visible as mono secondary lines,
    // including the module code in the group header.
    const codeText = screen.getByText('work_record.read')
    expect(codeText.tagName).toBe('SPAN')
    expect(codeText.className).toMatch(/\bfont-mono\b/)
    expect(codeText).toHaveAttribute('dir', 'ltr')
    expect(screen.getByText('work_record')).toBeInTheDocument()
  })
})

describe('role form page — edit', () => {
  it('fetches the role by id, seeds the form, and saves with the observed lock version', async () => {
    principalState.capabilities = [...manageCapabilities]
    vi.mocked(getAdminResource).mockResolvedValue({
      id: '01980f50-5f0d-7000-8000-000000000r01',
      code: 'finance.custom',
      name_ar: 'مراجع مالي مخصص',
      name_en: 'Custom finance reviewer',
      lock_version: 2,
    })
    vi.mocked(listRoleCapabilityCodes).mockResolvedValue(['work_record.read'])

    mount(<RoleFormScreen roleId="01980f50-5f0d-7000-8000-000000000r01" />)

    // The form is seeded from the fetched role and the code field is locked.
    const codeInput = await screen.findByLabelText('رمز الدور')
    expect(codeInput).toHaveValue('finance.custom')
    expect(codeInput).toHaveAttribute('readonly')
    expect(screen.getByLabelText('اسم الدور')).toHaveValue('مراجع مالي مخصص')
    expect(await screen.findByRole('checkbox', { name: 'قراءة سجل عمل' })).toBeChecked()

    // Add another capability and save.
    fireEvent.click(screen.getByRole('checkbox', { name: 'قراءة حسابات الدخول' }))
    fireEvent.change(screen.getByLabelText('اسم الدور'), { target: { value: 'مراجع مالي معدل' } })
    fireEvent.click(screen.getByRole('button', { name: 'حفظ التغييرات' }))

    await waitFor(() => {
      expect(updateAdminResource).toHaveBeenCalledWith(
        'roles',
        '01980f50-5f0d-7000-8000-000000000r01',
        { name: 'مراجع مالي معدل', capability_codes: ['work_record.read', 'identity.account.read'] },
        2,
        'x',
      )
    })
    expect(navigateMock).toHaveBeenCalledWith('/access?tab=roles')
  })

  it('surfaces a stale 412 as an alert with a retry that reloads the role', async () => {
    principalState.capabilities = [...manageCapabilities]
    vi.mocked(getAdminResource).mockResolvedValue({
      id: '01980f50-5f0d-7000-8000-000000000r01',
      code: 'finance.custom',
      name_ar: 'مراجع مالي مخصص',
      lock_version: 1,
    })
    vi.mocked(listRoleCapabilityCodes).mockResolvedValue([])
    vi.mocked(updateAdminResource).mockRejectedValue(
      new ApiError(412, {
        type: 'about:blank',
        title: 'Precondition Failed',
        status: 412,
      }),
    )

    mount(<RoleFormScreen roleId="01980f50-5f0d-7000-8000-000000000r01" />)
    await screen.findByLabelText('رمز الدور')
    // Wait for the catalog and the seeded selection so the save action is enabled.
    await screen.findByText('سجلات العمل')
    const save = screen.getByRole('button', { name: 'حفظ التغييرات' })
    await waitFor(() => expect(save).toBeEnabled())
    fireEvent.click(save)

    await waitFor(() => {
      expect(updateAdminResource).toHaveBeenCalled()
    })
    await waitFor(() => {
      expect(screen.getByText('تعذّر تحديث الدور.')).toBeInTheDocument()
    })
    // The retry control reloads the role from the server.
    expect(within(screen.getByRole('alert')).getByRole('button', { name: 'إعادة المحاولة' })).toBeInTheDocument()
  })
})

/*
 * The role form renders CAPABILITY_LABELS as the primary line in both
 * locales, so every capability in the canonical catalog must carry a
 * bilingual label and the map must not drift from the catalog.
 * The expected list mirrors Modules/Authorization/Contracts/
 * CapabilityCatalog.php.
 */
describe('capability label catalog', () => {
  const catalogCodes = [
    'work_record.create', 'work_record.read', 'work_record.list', 'work_record.update',
    'work_record.submit', 'work_record.return', 'work_record.complete', 'work_record.cancel',
    'work_record.archive',
    'work_definition.create', 'work_definition.read', 'work_definition.list',
    'work_definition.update', 'work_definition.publish', 'work_definition.retire',
    'workflow.read', 'workflow.list', 'workflow.manage', 'workflow.author', 'workflow.approve',
    'workflow.decide', 'workflow.reassign', 'workflow.escalate', 'workflow.cancel',
    'work_management.history.read',
    'tasks.create', 'tasks.read', 'tasks.list', 'tasks.update', 'tasks.assign', 'tasks.start',
    'tasks.complete', 'tasks.cancel', 'tasks.comment', 'tasks.participant-manage',
    'documents.create', 'documents.update', 'documents.read', 'documents.list',
    'documents.initiate-upload', 'documents.complete-upload', 'documents.get-upload-status',
    'documents.scan-version', 'documents.reconcile-promotion', 'documents.link',
    'documents.download', 'documents.archive', 'documents.hold', 'documents.grant',
    'search.query',
    'reporting.read', 'reporting.list', 'reporting.run', 'reporting.export',
    'reporting.download', 'reporting.dashboard',
    'notifications.read', 'notifications.manage',
    'identity.account.read', 'identity.account.manage',
    'organization.cluster.manage', 'organization.cluster.read',
    'organization.facility.manage', 'organization.facility.read',
    'organization.unit.manage', 'organization.unit.read',
    'organization.position.manage', 'organization.position.read',
    'organization.person.manage', 'organization.person.read', 'organization.person.reference',
    'organization.assignment.manage', 'organization.assignment.read',
    'organization.import.manage', 'organization.import.approve', 'organization.import.read',
    'organization.temporary-assignment.manage', 'organization.temporary-assignment.read',
    'authorization.role.read', 'authorization.role.manage',
    'authorization.capability.read', 'authorization.capability.manage',
    'authorization.assignment.read', 'authorization.assignment.manage',
    'authorization.delegation.read', 'authorization.delegation.manage',
    'authorization.deny.read', 'authorization.deny.manage',
    'authorization.policy.read', 'authorization.policy.manage',
    'authorization.audit.read', 'authorization.decision.read',
    'audit.event.read', 'audit.event.export', 'audit.integrity.verify',
    'strategy.plan.read', 'strategy.plan.manage',
    'strategy.indicator.read', 'strategy.indicator.manage',
    'strategy.measurement.submit', 'strategy.measurement.approve', 'strategy.impact.read',
    'portfolio_projects.portfolio.read', 'portfolio_projects.portfolio.manage',
    'portfolio_projects.project.read', 'portfolio_projects.project.manage',
    'portfolio_projects.milestone.approve', 'portfolio_projects.impact.submit',
    'portfolio_projects.budget.read',
    'risk.risk.read', 'risk.risk.manage', 'risk.assess', 'risk.control.manage',
    'risk.treatment.manage', 'risk.accept', 'risk.kri.manage',
    'platform_settings.read', 'platform_settings.manage', 'platform_settings.publish',
    'platform_settings.calendar.read', 'platform_settings.calendar.manage',
    'platform_settings.calendar.override_official_holiday',
    'platform_operations.health.read', 'platform_operations.backup.read',
    'platform_operations.backup.run', 'platform_operations.restore.request',
    'platform_operations.restore.confirm', 'platform_operations.logs.read',
    'platform_operations.logs.restore', 'platform_operations.alerts.manage',
    'platform_operations.maintenance.manage', 'platform_operations.maintenance.cancel',
  ]

  it('provides a bilingual label for every catalog capability', () => {
    const missing = catalogCodes.filter((code) => !CAPABILITY_LABELS[code])
    expect(missing).toEqual([])
    for (const code of catalogCodes) {
      expect(CAPABILITY_LABELS[code].ar.length).toBeGreaterThan(0)
      expect(CAPABILITY_LABELS[code].en.length).toBeGreaterThan(0)
    }
  })

  it('does not carry labels for capabilities outside the catalog', () => {
    const extra = Object.keys(CAPABILITY_LABELS).filter((code) => !catalogCodes.includes(code))
    expect(extra).toEqual([])
  })
})
