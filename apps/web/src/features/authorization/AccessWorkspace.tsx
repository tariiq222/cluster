import { directionForLocale } from '../../app/copy'
import {
  ClipboardCheck,
  GitBranch,
  KeyRound,
  LockKeyhole,
  Network,
  ShieldCheck,
  UserCog,
  Users,
} from 'lucide-react'
import type { ReactElement } from 'react'

import { WorkspaceTabs } from '../../app/WorkspaceTabs'
import { pathFromRoute, type AppRoute } from '../../shell/routes'
import { IdentityAccounts } from '../identity/IdentityAccounts'
import { AccessContext } from './AccessContext'
import { AccessScopesScreen } from './AccessScopesScreen'
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
    identityAndAuthorizationNavigation: 'تنقل الحوكمة والوصول',
    governanceSections: 'أقسام الحوكمة والوصول',
    chooseAnIdentityOrAuthorization: 'اختر تبويبًا لبدء إدارة الهوية أو الصلاحيات.',
  },
  en: {
    trustAccessCentre: 'Governance & access centre',
    identityAuthorization: 'Identity & access',
    manageIdentityAndReviewHow: 'Manage accounts, roles, assignments, policies, delegations, and scopes from one place.',
    identityAndAuthorizationNavigation: 'Governance and access navigation',
    governanceSections: 'Governance and access sections',
    chooseAnIdentityOrAuthorization: 'Choose a tab to start managing identity or access.',
  },
} as const

type Locale = 'ar' | 'en'

export type AccessWorkspaceProps = {
  locale: Locale
  activeRoute: AppRoute
  navigate: (path: string) => void
  scopeReady?: boolean
  scopeEpoch?: number
}

const governanceTabs: Array<{
  key: string
  route: AppRoute
  icon: ReactElement
  ar: string
  en: string
}> = [
  { key: 'accounts', route: { name: 'identity-accounts' }, icon: <Users size={17} />, ar: 'الحسابات', en: 'Accounts' },
  { key: 'roles', route: { name: 'authorization', resource: 'roles' }, icon: <ShieldCheck size={17} />, ar: 'الأدوار والقدرات', en: 'Roles & capabilities' },
  { key: 'assignments', route: { name: 'authorization', resource: 'role-assignments' }, icon: <UserCog size={17} />, ar: 'الإسنادات', en: 'Role assignments' },
  { key: 'policies', route: { name: 'authorization', resource: 'classification-policies' }, icon: <LockKeyhole size={17} />, ar: 'السياسات والقوالب', en: 'Policies & templates' },
  { key: 'delegations', route: { name: 'authorization', resource: 'delegations' }, icon: <GitBranch size={17} />, ar: 'التفويضات', en: 'Delegations' },
  { key: 'scopes', route: { name: 'access-context' }, icon: <Network size={17} />, ar: 'النطاقات', en: 'Access scopes' },
]

const diagnosticTabs: Array<{
  key: string
  route: AppRoute
  icon: ReactElement
  ar: string
  en: string
}> = [
  { key: 'decision', route: { name: 'access-explanation' }, icon: <ClipboardCheck size={17} />, ar: 'فحص قرار الوصول', en: 'Access decision inspector' },
  { key: 'supervisory', route: { name: 'authorization', resource: 'supervisory' }, icon: <KeyRound size={17} />, ar: 'العلاقات الإشرافية', en: 'Supervisory relationships' },
]

function matchesRoute(activeRoute: AppRoute, target: AppRoute): boolean {
  if (activeRoute.name !== target.name) return false
  if (activeRoute.name === 'authorization' && target.name === 'authorization') return activeRoute.resource === target.resource
  return true
}

function tabLabel(locale: Locale, ar: string, en: string): string {
  return locale === 'ar' ? ar : en
}

function screenForRoute({ activeRoute, locale, scopeReady, scopeEpoch }: Pick<AccessWorkspaceProps, 'activeRoute' | 'locale' | 'scopeReady' | 'scopeEpoch'>) {
  switch (activeRoute.name) {
    case 'identity-accounts':
      return <IdentityAccounts />
    case 'authorization':
      return <AuthorizationAdmin resource={activeRoute.resource as AdminResource} />
    case 'access-scopes':
      return <AccessScopesScreen locale={locale} scopeReady={scopeReady ?? false} scopeEpoch={scopeEpoch ?? 0} />
    case 'access-explanation':
      return activeRoute.decisionId
        ? <AccessExplanation decisionId={activeRoute.decisionId} />
        : <AccessDecisionSimulator />
    case 'access-context':
      return <AccessContext />
    default:
      return null
  }
}

export function AccessWorkspace({ locale, activeRoute, navigate, scopeReady, scopeEpoch }: AccessWorkspaceProps) {
  const isAccessRoute = ['identity-accounts', 'authorization', 'access-scopes', 'access-explanation', 'access-context'].includes(activeRoute.name)
  const allTabs = [...governanceTabs, ...diagnosticTabs].map((tab) => ({
    key: tab.key,
    path: pathFromRoute(tab.route),
    active: matchesRoute(activeRoute, tab.route),
    label: tabLabel(locale, tab.ar, tab.en),
    icon: tab.icon,
    route: tab.route,
  }))
  const currentScreen = screenForRoute({ activeRoute, locale, scopeReady, scopeEpoch })

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
        {isAccessRoute && currentScreen ? currentScreen : (
          <div className="state-panel" role="status">
            <p>{screenCopy[locale].chooseAnIdentityOrAuthorization}</p>
          </div>
        )}
      </div>
    </section>
  )
}
