import { directionForLocale } from '../../app/copy'
import {
  ClipboardCheck,
  LockKeyhole,
  ShieldCheck,
  UserCog,
  Users,
} from 'lucide-react'
import { useRef, type ReactElement } from 'react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { pathFromRoute, type AppRoute } from '../../shell/routes'
import { AccountsTab } from '../accounts-permissions/AccountsTab'
import { PermissionDecisionInspector } from '../accounts-permissions/PermissionDecisionInspector'
import { PoliciesScopesTab } from '../accounts-permissions/PoliciesScopesTab'
import { RoleAssignmentsTab } from '../accounts-permissions/RoleAssignmentsTab'
import { RolesPermissionsTab } from '../accounts-permissions/RolesPermissionsTab'
import { AuthorizationMutationFeedbackProvider } from '../accounts-permissions/AuthorizationMutationFeedback'
import type { AnnouncementRegionHandle } from '../accounts-permissions/AnnouncementRegion'

const screenCopy = {
  ar: {
    trustAccessCentre: 'مركز الحوكمة والوصول',
    identityAuthorization: 'الهوية والصلاحيات',
    manageIdentityAndReviewHow: 'أدر الحسابات والأدوار والإسنادات والسياسات والنطاقات من مساحة واحدة.',
    governanceSections: 'أقسام الحوكمة والوصول',
    chooseAnIdentityOrAuthorization: 'اختر تبويبًا لبدء إدارة الهوية أو الصلاحيات.',
  },
  en: {
    trustAccessCentre: 'Governance & access centre',
    identityAuthorization: 'Identity & access',
    manageIdentityAndReviewHow: 'Manage accounts, roles, assignments, policies, and scopes from one place.',
    governanceSections: 'Governance and access sections',
    chooseAnIdentityOrAuthorization: 'Choose a tab to start managing identity or access.',
  },
} as const

type Locale = 'ar' | 'en'

export type AccessSectionKey =
  | 'accounts'
  | 'roles-permissions'
  | 'role-assignments'
  | 'policies-scopes'
  | 'decision-inspector'

export type AccessWorkspaceProps = {
  locale: Locale
  activeRoute: AppRoute
  navigate: (path: string) => void
  scopeReady?: boolean
  scopeEpoch?: number
  capabilities?: readonly string[]
}

/**
 * Maps a route to its owning access workspace section. The five sections
 * collapse the prior eight governance/diagnostic tabs into a single local
 * navigation level per workspace.
 */
export function accessSectionForRoute(route: AppRoute): AccessSectionKey {
  if (route.name === 'identity-accounts') return 'accounts'
  if (
    route.name === 'authorization' &&
    (route.resource === 'roles' || route.resource === 'capabilities')
  ) {
    return 'roles-permissions'
  }
  if (route.name === 'authorization' && route.resource === 'role-assignments') {
    return 'role-assignments'
  }
  if (route.name === 'authorization' || route.name === 'access-scopes') {
    return 'policies-scopes'
  }
  return 'decision-inspector'
}

const sectionTabs: Array<{
  key: AccessSectionKey
  route: AppRoute
  icon: ReactElement
  ar: string
  en: string
}> = [
  { key: 'accounts', route: { name: 'identity-accounts' }, icon: <Users size={17} />, ar: 'الحسابات', en: 'Accounts' },
  { key: 'roles-permissions', route: { name: 'authorization', resource: 'roles' }, icon: <ShieldCheck size={17} />, ar: 'الأدوار والصلاحيات', en: 'Roles and permissions' },
  { key: 'role-assignments', route: { name: 'authorization', resource: 'role-assignments' }, icon: <UserCog size={17} />, ar: 'إسناد الأدوار', en: 'Role assignments' },
  { key: 'policies-scopes', route: { name: 'authorization', resource: 'classification-policies' }, icon: <LockKeyhole size={17} />, ar: 'سياسات ونطاقات الصلاحيات', en: 'Permission policies and scopes' },
  { key: 'decision-inspector', route: { name: 'access-explanation' }, icon: <ClipboardCheck size={17} />, ar: 'فحص قرار الصلاحية', en: 'Permission decision inspector' },
]

function screenForRoute({ activeRoute, locale, capabilities }: Pick<AccessWorkspaceProps, 'activeRoute' | 'locale' | 'capabilities'>) {
  const grantedCapabilities = capabilities ?? []

  switch (accessSectionForRoute(activeRoute)) {
    case 'accounts':
      return <AccountsTab locale={locale} capabilities={grantedCapabilities} />
    case 'roles-permissions':
      return <RolesPermissionsTab locale={locale} capabilities={grantedCapabilities} />
    case 'role-assignments':
      return <RoleAssignmentsTab locale={locale} capabilities={grantedCapabilities} />
    case 'policies-scopes':
      return <PoliciesScopesTab locale={locale} capabilities={grantedCapabilities} />
    case 'decision-inspector':
      return <PermissionDecisionInspector
        locale={locale}
        decisionId={activeRoute.name === 'access-explanation' ? activeRoute.decisionId : undefined}
      />
  }
}

export function AccessWorkspace({ locale, activeRoute, navigate, capabilities }: AccessWorkspaceProps) {
  const feedbackRef = useRef<AnnouncementRegionHandle | null>(null)
  const activeSection = accessSectionForRoute(activeRoute)
  const allTabs = sectionTabs.map((tab) => ({
    key: tab.key,
    path: pathFromRoute(tab.route),
    active: tab.key === activeSection,
    label: locale === 'ar' ? tab.ar : tab.en,
    icon: tab.icon,
    route: tab.route,
  }))
  const currentScreen = screenForRoute({
    activeRoute,
    locale,
    capabilities,
  })

  return (
    <section className="access-workspace" dir={directionForLocale(locale)} aria-labelledby="access-workspace-heading">
      <header className="access-workspace-header">
        <div>
          <p className="access-workspace-eyebrow">{screenCopy[locale].trustAccessCentre}</p>
          <h1 id="access-workspace-heading">{screenCopy[locale].identityAuthorization}</h1>
          <p>{screenCopy[locale].manageIdentityAndReviewHow}</p>
        </div>
      </header>
      <WorkspaceTabs
        label={screenCopy[locale].governanceSections}
        tabs={allTabs}
        onNavigate={navigate}
      />
      <div className="access-workspace-content">
        <AuthorizationMutationFeedbackProvider locale={locale} regionRef={feedbackRef}>
          {currentScreen ?? (
            <div className="state-panel" role="status">
              <p>{screenCopy[locale].chooseAnIdentityOrAuthorization}</p>
            </div>
          )}
        </AuthorizationMutationFeedbackProvider>
      </div>
    </section>
  )
}
