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
  decisionId?: string
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
  decisionId,
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
  return (
    <section
      className="accounts-permissions-workspace"
      dir={directionForLocale(locale)}
      aria-labelledby="accounts-permissions-heading"
    >
      <h1 id="accounts-permissions-heading" className="visually-hidden">
        {labels.heading}
      </h1>
      <header className="accounts-permissions-header">
        <div>
          <p className="accounts-permissions-eyebrow">
            {locale === 'ar' ? 'بوابة الإدارة' : 'Governance portal'}
          </p>
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
      {accountPermissionsTabs.map((tab) => {
        const isActive = tab === activeTab
        const tabIsAdvanced = isAdvancedAccountPermissionsTab(tab)
        const tabIsAvailable = tabAvailableFor(tab, capabilities)
        const tabPanelId = `${tab}-panel`
        const tabLabel = TAB_LABELS[tab][locale]
        if (!isActive) {
          // Inactive panel shells exist solely so every tab's `aria-controls`
          // resolves to a real DOM node. They are hidden and never mount any
          // heavyweight child component, so the only live content is the
          // active panel.
          return (
            <section
              key={tabPanelId}
              id={tabPanelId}
              role="tabpanel"
              aria-labelledby={`${tab}-tab`}
              hidden
              data-testid={`accounts-permissions-panel-${tab}`}
              data-active="false"
            />
          )
        }
        return (
          <section
            key={tabPanelId}
            id={tabPanelId}
            role="tabpanel"
            aria-labelledby={`${tab}-tab`}
            className="accounts-permissions-panel"
            data-testid="accounts-permissions-active"
            data-active="true"
          >
            {tabIsAdvanced ? (
              <p className="accounts-permissions-advanced" aria-hidden="false">
                {labels.advancedEyebrow}
              </p>
            ) : null}
            {tabIsAvailable ? (
              <ActivePanel
                tab={tab}
                locale={locale}
                capabilities={capabilities}
                allowedActionsByRole={allowedActionsByRole}
                decisionId={decisionId}
              />
            ) : (
              <div role="status">
                <EmptyState
                  icon={<LockKeyhole aria-hidden="true" />}
                  title={tabLabel}
                  body={tab === 'accounts' ? labels.accountsUnavailable : tab === 'roles-permissions' ? labels.rolesUnavailable : tab === 'role-assignments' ? labels.assignmentsUnavailable : tab === 'policies-scopes' ? labels.policiesUnavailable : labels.inspectorUnavailable}
                />
              </div>
            )}
          </section>
        )
      })}
     </section>
   )
 }

function ActivePanel({
  tab,
  locale,
  capabilities,
  allowedActionsByRole,
  decisionId,
}: {
  tab: AccountPermissionsTabKey
  locale: Locale
  capabilities: readonly string[]
  allowedActionsByRole?: Readonly<Record<string, readonly string[]>>
  decisionId?: string
}) {
  switch (tab) {
    case 'accounts':
      return <AccountsTab locale={locale} capabilities={capabilities} />
    case 'roles-permissions':
      return (
        <RolesPermissionsTab
          locale={locale}
          capabilities={capabilities}
          allowedActionsByRole={allowedActionsByRole}
        />
      )
    case 'role-assignments':
      return <RoleAssignmentsTab locale={locale} capabilities={capabilities} />
    case 'policies-scopes':
      return <PoliciesScopesTab locale={locale} capabilities={capabilities} />
    case 'decision-inspector':
      return <PermissionDecisionInspector locale={locale} decisionId={decisionId} />
  }
}
