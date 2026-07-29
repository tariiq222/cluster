import { directionForLocale } from '../../app/copy'
import {
  ClipboardCheck,
  LockKeyhole,
  ShieldCheck,
  UserCog,
  Users,
} from 'lucide-react'
import type { ReactElement } from 'react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { pathFromRoute, type AppRoute } from '../../shell/routes'
import { IdentityAccounts } from '../identity/IdentityAccounts'
import { AccessScopesScreen } from './AccessScopesScreen'
import { RolesCapabilitiesWorkspace } from './RolesCapabilitiesWorkspace'
import {
  AccessDecisionSimulator,
  AccessExplanation,
  AuthorizationAdmin,
  type AdminResource,
} from './AuthorizationAdmin'

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

function screenForRoute({ activeRoute, locale, scopeReady, scopeEpoch, capabilities, navigate }: Pick<AccessWorkspaceProps, 'activeRoute' | 'locale' | 'scopeReady' | 'scopeEpoch' | 'capabilities' | 'navigate'>) {
  switch (activeRoute.name) {
    case 'identity-accounts':
      return <IdentityAccounts />
    case 'authorization':
      if (activeRoute.resource === 'roles' || activeRoute.resource === 'capabilities') {
        return <RolesCapabilitiesWorkspace locale={locale} capabilities={capabilities ?? null} />
      }
      return <AuthorizationAdmin resource={activeRoute.resource as AdminResource} capabilities={capabilities ?? []} />
    case 'access-scopes':
      return <AccessScopesScreen locale={locale} scopeReady={scopeReady ?? false} scopeEpoch={scopeEpoch ?? 0} navigate={navigate} />
    case 'access-explanation':
      return activeRoute.decisionId
        ? <AccessExplanation decisionId={activeRoute.decisionId} />
        : <AccessDecisionSimulator />
    default:
      return null
  }
}

export function AccessWorkspace({ locale, activeRoute, navigate, scopeReady, scopeEpoch, capabilities }: AccessWorkspaceProps) {
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
    scopeReady,
    scopeEpoch,
    capabilities,
    navigate,
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
        {currentScreen ?? (
          <div className="state-panel" role="status">
            <p>{screenCopy[locale].chooseAnIdentityOrAuthorization}</p>
          </div>
        )}
      </div>
    </section>
  )
}
