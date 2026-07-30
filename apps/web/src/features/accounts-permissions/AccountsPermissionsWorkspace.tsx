import { useCallback, useMemo, useRef } from 'react'
import type { ReactElement } from 'react'

import { directionForLocale, type Locale } from '../../app/copy'
import { WorkspaceTabs, type WorkspaceTab } from '../../app/WorkspaceTabs'
import {
  LockKeyhole,
  ShieldCheck,
  UserCog,
  Users,
  ClipboardCheck,
} from 'lucide-react'
import { EmptyState } from '../../ui'
import { AuthorizationMutationFeedbackProvider } from './AuthorizationMutationFeedback'
import type { AnnouncementRegionHandle } from './AnnouncementRegion'
import {
  accountPermissionsTabs,
  isAdvancedAccountPermissionsTab,
  tabAvailableFor,
  type AccountPermissionsTabKey,
} from './canMutateAdminResource'
import { AccountsTab } from './AccountsTab'
import { RolesPermissionsTab } from './RolesPermissionsTab'
import { RoleAssignmentsTab } from './RoleAssignmentsTab'
import { PoliciesScopesTab } from './PoliciesScopesTab'
import { PermissionDecisionInspector } from './PermissionDecisionInspector'

const COPY = {
  ar: {
    heading: 'الحسابات والصلاحيات',
    intro: 'إدارة الحسابات والأدوار والإسنادات والسياسات وقرارات الصلاحيات من مساحة واحدة.',
    governanceSections: 'أقسام الحسابات والصلاحيات',
    advancedEyebrow: 'متقدم',
    policiesTab: 'السياسات والنطاقات',
    inspectorTab: 'فاحص قرار الصلاحية',
    policiesUnavailable: 'أدوات السياسات والنطاقات المتقدمة غير متاحة لحسابك.',
    inspectorUnavailable: 'هذه الأداة المتقدمة غير متاحة لحسابك.',
    accountsUnavailable: 'الحسابات غير متاحة لحسابك.', rolesUnavailable: 'الأدوار والصلاحيات غير متاحة لحسابك.', assignmentsUnavailable: 'إسنادات الأدوار غير متاحة لحسابك.',
  },
  en: {
    heading: 'Accounts & Permissions',
    intro: 'Manage accounts, roles, assignments, policies, and decision inspection from one place.',
    governanceSections: 'Accounts & permissions sections',
    advancedEyebrow: 'Advanced',
    policiesTab: 'Policies & Scopes',
    inspectorTab: 'Permission Decision Inspector',
    policiesUnavailable: 'The advanced policies and scopes tools are not available to your account.',
    inspectorUnavailable: 'This advanced tool is not available to your account.',
    accountsUnavailable: 'Accounts are not available to your account.', rolesUnavailable: 'Roles and permissions are not available to your account.', assignmentsUnavailable: 'Role assignments are not available to your account.',
  },
} as const satisfies Record<Locale, Record<string, string>>

const TAB_ICONS: Record<AccountPermissionsTabKey, ReactElement> = {
  accounts: <Users size={17} aria-hidden="true" />,
  'roles-permissions': <ShieldCheck size={17} aria-hidden="true" />,
  'role-assignments': <UserCog size={17} aria-hidden="true" />,
  'policies-scopes': <LockKeyhole size={17} aria-hidden="true" />,
  'decision-inspector': <ClipboardCheck size={17} aria-hidden="true" />,
}

const TAB_PATHS: Record<AccountPermissionsTabKey, string> = {
  accounts: '/admin/identity/accounts',
  'roles-permissions': '/admin/authorization/roles',
  'role-assignments': '/admin/authorization/role-assignments',
  'policies-scopes': '/admin/authorization/classification-policies',
  'decision-inspector': '/admin/authorization/explain',
}

const TAB_LABELS: Record<AccountPermissionsTabKey, { ar: string; en: string }> = {
  accounts: { ar: 'الحسابات', en: 'Accounts' },
  'roles-permissions': { ar: 'الأدوار والصلاحيات', en: 'Roles & Permissions' },
  'role-assignments': { ar: 'إسنادات الأدوار', en: 'Role Assignments' },
  'policies-scopes': { ar: 'السياسات والنطاقات', en: 'Policies & Scopes' },
  'decision-inspector': { ar: 'فاحص قرار الصلاحية', en: 'Permission Decision Inspector' },
}

export type AccountsPermissionsWorkspaceProps = {
  locale: Locale
  /** Server-issued URL state, e.g. `?tab=decision-inspector`. */
  activeTab: AccountPermissionsTabKey
  capabilities: readonly string[]
  allowedActionsByRole?: Readonly<Record<string, readonly string[]>>
  navigate: (path: string) => void
  /** Defaults to ARIA-friendly tablist semantics. Pass `'links'` to keep the
   *  legacy anchor behaviour, useful when a downstream test or screen still
   *  queries by role=link. */
  tabsMode?: 'tabs' | 'links'
}

/**
 * Five-tab workspace that owns the governance surface. Owns its own tab state,
 *   - advanced tabs (`policies-scopes`, `decision-inspector`) render an
 *     explicit "Advanced" eyebrow alongside the localized unavailable state
 *     when the principal lacks the read or manage capability;
 *   - the URL state is `?tab=…` so deep links resolve to the same panel;
 *   - role + assignment mutations funnel through the
 *     `AuthorizationMutationFeedback` live region with one portal for the
 *     whole workspace;
 *   - the basic reads (account/role/policy lists) NEVER render raw UUIDs,
 *     capability IDs, or JSON payloads; capability codes are revealed only
 *     when the principal can read the capability catalog.
 */
export function AccountsPermissionsWorkspace(props: AccountsPermissionsWorkspaceProps) {
  const { locale, tabsMode = 'tabs' } = props
  const feedbackRef = useRef<AnnouncementRegionHandle | null>(null)
  return (
    <AuthorizationMutationFeedbackProvider locale={locale} regionRef={feedbackRef}>
      <WorkspaceShell {...props} tabsMode={tabsMode} />
    </AuthorizationMutationFeedbackProvider>
  )
}

function WorkspaceShell({
  locale,
  activeTab,
  capabilities,
  allowedActionsByRole,
  navigate,
  tabsMode,
}: AccountsPermissionsWorkspaceProps) {
  const labels = COPY[locale]

  const navigateToTab = useCallback(
    (tab: AccountPermissionsTabKey | string) => {
      const key = tab as AccountPermissionsTabKey
      navigate(`${TAB_PATHS[key]}?tab=${key}`)
    },
    [navigate],
  )

  const tabBar = useMemo<WorkspaceTab[]>(
    () =>
      accountPermissionsTabs.map((tab) => ({
        key: tab,
        label: locale === 'ar' ? TAB_LABELS[tab].ar : TAB_LABELS[tab].en,
        path: `${TAB_PATHS[tab]}?tab=${tab}`,
        active: tab === activeTab,
        icon: TAB_ICONS[tab],
        panelId: `${tab}-panel`,
      })),
    [activeTab, locale],
  )

  const isAdvanced = isAdvancedAccountPermissionsTab(activeTab)
  const isAvailable = tabAvailableFor(activeTab, capabilities)

  return (
    <section
      className="accounts-permissions-workspace"
      dir={directionForLocale(locale)}
      aria-labelledby="accounts-permissions-heading"
    >
      <header className="accounts-permissions-header">
        <div>
          <p className="accounts-permissions-eyebrow">
            {locale === 'ar' ? 'بوابة الإدارة' : 'Governance portal'}
          </p>
          <h1 id="accounts-permissions-heading">{labels.heading}</h1>
          <p>{labels.intro}</p>
        </div>
      </header>
      <WorkspaceTabs
        label={labels.governanceSections}
        tabs={tabBar}
        onNavigate={navigateToTab}
        mode={tabsMode}
        onTabSelect={(key) => navigateToTab(key as AccountPermissionsTabKey)}
      />
      <section
        className="accounts-permissions-panel"
        aria-label={TAB_LABELS[activeTab][locale]}
        data-testid="accounts-permissions-active"
      >
        {isAdvanced ? (
          <p className="accounts-permissions-advanced" aria-hidden="false">
            {labels.advancedEyebrow}
          </p>
        ) : null}
        {isAvailable ? (
          <ActivePanel
            tab={activeTab}
            locale={locale}
            capabilities={capabilities}
            allowedActionsByRole={allowedActionsByRole}
            panelId={`${activeTab}-panel`}
          />
        ) : (
          <div role="status">
            <EmptyState
              icon={<LockKeyhole aria-hidden="true" />}
              title={TAB_LABELS[activeTab][locale]}
              body={activeTab === 'accounts' ? labels.accountsUnavailable : activeTab === 'roles-permissions' ? labels.rolesUnavailable : activeTab === 'role-assignments' ? labels.assignmentsUnavailable : activeTab === 'policies-scopes' ? labels.policiesUnavailable : labels.inspectorUnavailable}
            />
          </div>
        )}
      </section>
    </section>
  )
}

function ActivePanel({
  tab,
  locale,
  capabilities,
  allowedActionsByRole,
  panelId,
}: {
  tab: AccountPermissionsTabKey
  locale: Locale
  capabilities: readonly string[]
  allowedActionsByRole?: Readonly<Record<string, readonly string[]>>
  panelId: string
}) {
  switch (tab) {
    case 'accounts':
      return (
        <section id={panelId} role="tabpanel" aria-labelledby="accounts-tab">
          <AccountsTab locale={locale} capabilities={capabilities} />
        </section>
      )
    case 'roles-permissions':
      return (
        <section id={panelId} role="tabpanel" aria-labelledby="roles-permissions-tab">
          <RolesPermissionsTab
            locale={locale}
            capabilities={capabilities}
            allowedActionsByRole={allowedActionsByRole}
          />
        </section>
      )
    case 'role-assignments':
      return (
        <section id={panelId} role="tabpanel" aria-labelledby="role-assignments-tab">
          <RoleAssignmentsTab locale={locale} capabilities={capabilities} />
        </section>
      )
    case 'policies-scopes':
      return (
        <section id={panelId} role="tabpanel" aria-labelledby="policies-scopes-tab">
          <PoliciesScopesTab locale={locale} capabilities={capabilities} />
        </section>
      )
    case 'decision-inspector':
      return (
        <section id={panelId} role="tabpanel" aria-labelledby="decision-inspector-tab">
          <PermissionDecisionInspector locale={locale} />
        </section>
      )
  }
}
