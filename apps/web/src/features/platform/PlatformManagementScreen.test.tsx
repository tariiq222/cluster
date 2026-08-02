// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../../app/session-context'
import { ApiError } from '../../api/http'
import { PlatformManagementScreen } from './PlatformManagementScreen'
import { AlertsSection } from './sections/AlertsSection'
import { CalendarsSection } from './sections/CalendarsSection'
import { LogsSection } from './sections/LogsSection'
import { RestoreSection } from './sections/RestoreSection'

/*
 * Task 10 — three behavior rules are tested before the production migration:
 *
 * 1. Tabs the principal cannot access are absent before render, never
 *    admitted-then-denied. A principal holding only `health.read` must not
 *    see the settings, calendars, backups, restore, maintenance, logs or
 *    alerts tabs at all.
 * 2. Restore is a deliberate two-step interaction: after the request is
 *    submitted the confirmation AlertDialog stays disabled until the exact
 *    backup name is typed by hand.
 * 3. A 503 deferred technical-logs response renders an explanatory Alert
 *    with a restore-request action, not the generic error state.
 */

const overviewFixture = {
  status: 'healthy',
  updated_at: '2026-08-01T08:00:00Z',
  issues: [],
  metrics: {
    health_checks: [{ code: 'database', status: 'healthy', latency_ms: 12 }],
    backup: {
      status: 'healthy',
      last_successful_at: '2026-08-01T07:00:00Z',
      last_failed_at: null,
      last_validation_at: '2026-08-01T07:00:00Z',
    },
  },
  allowed_actions: ['platform_operations.backup.run'],
} as const

const healthFixture = {
  status: 'healthy',
  updated_at: '2026-08-01T08:00:00Z',
  checks: [{ code: 'database', status: 'healthy', checked_at: '2026-08-01T08:00:00Z', latency_ms: 12 }],
  allowed_actions: [],
} as const

const backupReportFixture = {
  status: 'healthy',
  last_successful_at: '2026-08-01T07:00:00Z',
  last_failed_at: null,
  last_validation_at: '2026-08-01T07:00:00Z',
  allowed_actions: ['platform_operations.backup.run'],
} as const

vi.mock('../../api/hooks', () => ({
  usePlatformOperationsOverview: () => ({
    data: overviewFixture,
    isPending: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  usePlatformHealth: () => ({
    data: healthFixture,
    isPending: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
  usePlatformSettingsVersions: () => ({
    data: { items: [], next_cursor: null },
    isPending: false,
    isError: false,
    error: null,
    refetch: vi.fn(),
  }),
}))

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

const platformApiMock = vi.hoisted(() => ({
  getPlatformBackups: vi.fn(),
  dispatchPlatformBackup: vi.fn(),
  listBusinessCalendars: vi.fn(),
  createBusinessCalendar: vi.fn(),
  setBusinessCalendarWeekday: vi.fn(),
  setBusinessCalendarException: vi.fn(),
  publishBusinessCalendar: vi.fn(),
  listPlatformAlertPolicies: vi.fn(),
  updatePlatformAlertPolicy: vi.fn(),
  listPlatformMaintenanceWindows: vi.fn(),
  schedulePlatformMaintenanceWindow: vi.fn(),
  cancelPlatformMaintenanceWindow: vi.fn(),
  listPlatformTechnicalLogs: vi.fn(),
  requestPlatformTechnicalLogsRestore: vi.fn(),
  requestPlatformRestore: vi.fn(),
  confirmPlatformRestore: vi.fn(),
  getCurrentPlatformSettings: vi.fn(),
  createPlatformSettingsDraft: vi.fn(),
  setPlatformSetting: vi.fn(),
  validatePlatformSettingsVersion: vi.fn(),
  publishPlatformSettingsVersion: vi.fn(),
}))

vi.mock('./platform-api', () => platformApiMock)

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

function mount(node: ReactNode, initialRoute = '/platform') {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <MemoryRouter initialEntries={[initialRoute]}>
      <QueryClientProvider client={client}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          {node}
        </SessionProvider>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  navigateMock.mockReset()
  principalState.capabilities = []
  principalState.effectiveScope = null
  platformApiMock.getPlatformBackups.mockReset()
  platformApiMock.getPlatformBackups.mockResolvedValue(backupReportFixture)
  platformApiMock.dispatchPlatformBackup.mockReset()
  platformApiMock.listBusinessCalendars.mockReset()
  platformApiMock.listBusinessCalendars.mockResolvedValue({ items: [] })
  platformApiMock.listPlatformAlertPolicies.mockReset()
  platformApiMock.listPlatformAlertPolicies.mockResolvedValue({ items: [] })
  platformApiMock.listPlatformMaintenanceWindows.mockReset()
  platformApiMock.listPlatformMaintenanceWindows.mockResolvedValue({ items: [] })
  platformApiMock.listPlatformTechnicalLogs.mockReset()
  platformApiMock.listPlatformTechnicalLogs.mockResolvedValue({ items: [], next_cursor: null })
  platformApiMock.getCurrentPlatformSettings.mockReset()
  platformApiMock.getCurrentPlatformSettings.mockResolvedValue({ status: 'published', lock_version: 1 })
  platformApiMock.requestPlatformRestore.mockReset()
  platformApiMock.confirmPlatformRestore.mockReset()
  platformApiMock.requestPlatformTechnicalLogsRestore.mockReset()
})

describe('platform workspace capability filtering', () => {
  it('renders exactly five grouped tabs for a fully capable principal', async () => {
    principalState.capabilities = [
      'platform_operations.health.read',
      'platform_settings.read',
      'platform_settings.calendar.read',
      'platform_operations.alerts.manage',
      'platform_operations.logs.read',
      'platform_operations.backup.read',
      'platform_operations.restore.request',
      'platform_operations.maintenance.manage',
    ]
    mount(<PlatformManagementScreen />)

    const tabs = await screen.findAllByRole('tab')
    expect(tabs).toHaveLength(5)
    expect(tabs.map((tab) => tab.textContent)).toEqual([
      'ملخص التشغيل',
      'إعدادات المنصة',
      'المراقبة والتشخيص',
      'استمرارية البيانات',
      'نوافذ الصيانة',
    ])

    for (const legacyLabel of [
      'إعدادات الأمان',
      'التقويمات',
      'الصحة',
      'النسخ الاحتياطي',
      'طلبات الاستعادة',
      'السجلات الفنية',
      'سياسات التنبيهات',
    ]) {
      expect(screen.queryByRole('tab', { name: legacyLabel })).not.toBeInTheDocument()
    }
  })

  it('shows only overview and monitoring for a health-only principal', async () => {
    principalState.capabilities = ['platform_operations.health.read']
    mount(<PlatformManagementScreen />)

    expect(await screen.findByRole('tab', { name: 'ملخص التشغيل' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'المراقبة والتشخيص' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'إعدادات المنصة' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'استمرارية البيانات' })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'نوافذ الصيانة' })).not.toBeInTheDocument()
  })

  it('renders only authorized children inside a visible group', async () => {
    principalState.capabilities = ['platform_operations.alerts.manage']
    mount(<PlatformManagementScreen />)

    expect(await screen.findByRole('tab', { name: 'إعدادات المنصة' })).toBeInTheDocument()
    expect(await screen.findByText('لا توجد سياسات تنبيه مهيأة.')).toBeInTheDocument()
    expect(screen.getByText(/للبيئة الحالية/)).toBeInTheDocument()
    expect(platformApiMock.listPlatformAlertPolicies).toHaveBeenCalledTimes(1)
    expect(platformApiMock.listBusinessCalendars).not.toHaveBeenCalled()
    expect(platformApiMock.getCurrentPlatformSettings).not.toHaveBeenCalled()
    expect(screen.queryByText('إعدادات الأمان')).not.toBeInTheDocument()
    expect(screen.queryByText('التقويمات')).not.toBeInTheDocument()
  })

  it('maps a legacy alerts deep link to the settings group', async () => {
    principalState.capabilities = ['platform_operations.alerts.manage']
    mount(<PlatformManagementScreen />, '/platform?tab=alerts')

    expect(await screen.findByRole('tab', { name: 'إعدادات المنصة' })).toHaveAttribute('aria-selected', 'true')
    expect(await screen.findByText('لا توجد سياسات تنبيه مهيأة.')).toBeInTheDocument()
  })
})

describe('restore confirmation safety', () => {
  it('requires typing the exact backup name before a restore can be confirmed', async () => {
    principalState.capabilities = [
      'platform_operations.restore.request',
      'platform_operations.restore.confirm',
    ]
    platformApiMock.requestPlatformRestore.mockResolvedValue({
      http_status: 202,
      operation_id: '01980f50-5f0d-7000-8000-000000000101',
      status: 'awaiting_confirmation',
      allowed_actions: ['platform_operations.restore.confirm'],
    })

    mount(<RestoreSection />)

    // Step 1: request the restore for an operator-supplied backup identifier.
    fireEvent.change(await screen.findByLabelText('معرّف النسخة'), {
      target: { value: 'backup-2026-07-31' },
    })
    fireEvent.change(screen.getByLabelText('السبب'), {
      target: { value: 'Recovering data lost in the incident' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'طلب استعادة' }))

    // Step 2: the confirmation dialog appears and demands the exact name.
    const dialog = await screen.findByRole('alertdialog')
    const confirm = within(dialog).getByRole('button', { name: 'تأكيد الاستعادة' })
    expect(confirm).toBeDisabled()

    // A partial or wrong name keeps the confirm disabled.
    fireEvent.change(within(dialog).getByLabelText('اكتب اسم النسخة'), {
      target: { value: 'backup-2026-' },
    })
    expect(confirm).toBeDisabled()

    // The exact backup name enables the confirm.
    fireEvent.change(within(dialog).getByLabelText('اكتب اسم النسخة'), {
      target: { value: 'backup-2026-07-31' },
    })
    expect(confirm).toBeEnabled()
  })
})

describe('deferred technical logs', () => {
  it('renders deferred logs as an explanatory alert with a restore action, not a generic error', async () => {
    principalState.capabilities = [
      'platform_operations.logs.read',
      'platform_operations.logs.restore',
    ]
    platformApiMock.listPlatformTechnicalLogs.mockRejectedValue(
      new ApiError(503, {
        type: 'https://cluster.example/problems/service-unavailable',
        title: 'Service Unavailable',
        status: 503,
        detail: 'Technical logs are not available in this environment.',
      }),
    )

    mount(<LogsSection />)

    // An explanatory alert is shown, not the generic error state.
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(/مؤجلة|deferred/i)
    expect(within(alert).getByRole('button', { name: /اطلب استعادة|Request restore/ })).toBeInTheDocument()
  })

  it('keeps the explanatory alert but hides the restore action for a logs.read-only principal', async () => {
    principalState.capabilities = ['platform_operations.logs.read']
    platformApiMock.listPlatformTechnicalLogs.mockRejectedValue(
      new ApiError(503, {
        type: 'https://cluster.example/problems/service-unavailable',
        title: 'Service Unavailable',
        status: 503,
        detail: 'Technical logs are not available in this environment.',
      }),
    )

    mount(<LogsSection />)

    // The explanatory alert remains visible: the logs surface is deferred
    // for every logs reader, regardless of restore permission.
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent(/مؤجلة|deferred/i)

    // The restore action must NOT be exposed without
    // `platform_operations.logs.restore` — neither inside the alert nor as
    // a restore sheet.
    expect(within(alert).queryByRole('button', { name: /اطلب استعادة|Request restore/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'طلب استعادة السجلات' })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('معرّف الحزمة (Manifest)')).not.toBeInTheDocument()
  })
})

describe('alert policy manage permission', () => {
  it('renders toggle/edit controls only for policies whose own allowed_actions allow manage', async () => {
    principalState.capabilities = ['platform_operations.alerts.manage']
    platformApiMock.listPlatformAlertPolicies.mockResolvedValue({
      items: [
        {
          id: 'policy-1',
          code: 'high-cpu',
          status: 'enabled',
          severity: 'critical',
          channel: 'email',
          lock_version: 1,
          allowed_actions: ['platform_operations.alerts.manage'],
        },
        {
          id: 'policy-2',
          code: 'low-disk',
          status: 'enabled',
          severity: 'warning',
          channel: 'slack',
          lock_version: 1,
          allowed_actions: [],
        },
      ],
    })

    mount(<AlertsSection />)

    const manageableRow = (await screen.findByText('high-cpu')).closest('li')
    expect(manageableRow).not.toBeNull()
    // The manageable policy renders both the toggle and the edit control.
    expect(within(manageableRow as HTMLElement).getByRole('switch', { name: 'high-cpu مفعّل' })).toBeInTheDocument()
    expect(
      within(manageableRow as HTMLElement).getByRole('button', { name: 'تعديل high-cpu' }),
    ).toBeInTheDocument()

    const readOnlyRow = screen.getByText('low-disk').closest('li')
    expect(readOnlyRow).not.toBeNull()
    // The read-only policy renders no toggle and no edit control — manage
    // permission is per policy, not a single global gate.
    expect(within(readOnlyRow as HTMLElement).queryByRole('switch')).not.toBeInTheDocument()
    expect(
      within(readOnlyRow as HTMLElement).queryByRole('button', { name: /تعديل|Edit/ }),
    ).not.toBeInTheDocument()
  })
})

describe('calendar weekday navigation', () => {
  it('navigates to the weekday edit page when a weekday is picked', async () => {
    principalState.capabilities = ['platform_settings.calendar.manage']
    platformApiMock.listBusinessCalendars.mockResolvedValue({
      items: [
        {
          id: 'cal-1',
          scope_type: 'platform',
          scope_id: 'platform',
          status: 'draft',
          timezone: 'Asia/Riyadh',
          values: { working_days: [1, 2, 3, 4, 5], holidays: [] },
          allowed_actions: ['platform_settings.calendar.manage'],
          lock_version: 1,
        },
      ],
    })

    mount(<CalendarsSection />)

    // The weekday selector opens the seven-day options; picking one
    // navigates to the full weekday edit page instead of opening a Sheet.
    const weekdaySelect = await screen.findByRole('combobox', { name: 'تعديل يوم عمل' })
    fireEvent.click(weekdaySelect)
    fireEvent.click(await screen.findByRole('option', { name: 'الأحد' }))
    await waitFor(() => {
      expect(navigateMock).toHaveBeenCalledWith('/platform/calendars/cal-1/weekdays/1/edit')
    })
    // The Sheet is gone — no weekday working-day field is rendered inline.
    expect(screen.queryByLabelText('يوم عمل')).not.toBeInTheDocument()
  })
})
