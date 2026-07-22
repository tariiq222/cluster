import { directionForLocale } from '../../app/copy'
import {
  ClipboardCheck,
  FileKey2,
  GitBranch,
  KeyRound,
  LockKeyhole,
  Network,
  ShieldCheck,
  UserCog,
  Users,
} from 'lucide-react'
import type { ReactElement } from 'react'

import { WorkspaceTabs, type WorkspaceTab } from '../../app/WorkspaceTabs'
import { pathFromRoute, type AppRoute } from '../../shell/routes'
import { IdentityAccounts } from '../identity/IdentityAccounts'
import { AccessContext } from './AccessContext'
import {
  AccessDecisionSimulator,
  AccessExplanation,
  AuthorizationAdmin,
  type AdminResource,
} from './AuthorizationAdmin'

const screenCopy = {
  ar: {
    accounts: 'الحسابات',
    rolesCapabilities: 'الأدوار والقدرات',
    accessScopes: 'نطاقات الوصول',
    testAnAccessDecision: 'اختبار قرار الوصول',
    trustAccessCentre: 'مركز الثقة والوصول',
    identityAuthorization: 'الهوية والتفويض',
    manageIdentityAndReviewHow: 'أدر هوية المستخدم وراجع كيف تُمنح الصلاحيات من مكان واحد.',
    identityAndAuthorizationNavigation: 'تنقل الهوية والتفويض',
    rolesAndCapabilitiesSections: 'أقسام الأدوار والقدرات',
    chooseAnIdentityOrAuthorization: 'اختر مساحة لإدارة الهوية أو التفويض.',
  },
  en: {
    accounts: 'Accounts',
    rolesCapabilities: 'Roles & capabilities',
    accessScopes: 'Access scopes',
    testAnAccessDecision: 'Test an access decision',
    trustAccessCentre: 'Trust & access centre',
    identityAuthorization: 'Identity & authorization',
    manageIdentityAndReviewHow: 'Manage identity and review how access is granted from one place.',
    identityAndAuthorizationNavigation: 'Identity and authorization navigation',
    rolesAndCapabilitiesSections: 'Roles and capabilities sections',
    chooseAnIdentityOrAuthorization: 'Choose an identity or authorization area to begin.',
  },
} as const


type Locale = 'ar' | 'en'

export type AccessWorkspaceProps = {
  locale: Locale
  /** The current URL route. Non-access routes render the hub landing state. */
  activeRoute: AppRoute
  navigate: (path: string) => void
}

type RoutedTab = WorkspaceTab & { route: AppRoute }

const authorizationTabs: Array<{
  key: string
  route: AppRoute
  icon: ReactElement
  ar: string
  en: string
}> = [
  { key: 'roles', route: { name: 'authorization', resource: 'roles' }, icon: <ShieldCheck size={17} />, ar: 'الأدوار', en: 'Roles' },
  { key: 'capabilities', route: { name: 'authorization', resource: 'capabilities' }, icon: <KeyRound size={17} />, ar: 'الصلاحيات', en: 'Capabilities' },
  { key: 'assignments', route: { name: 'authorization', resource: 'role-assignments' }, icon: <UserCog size={17} />, ar: 'إسنادات الأدوار', en: 'Role assignments' },
  { key: 'delegations', route: { name: 'authorization', resource: 'delegations' }, icon: <GitBranch size={17} />, ar: 'التفويضات', en: 'Delegations' },
  { key: 'classification', route: { name: 'authorization', resource: 'classification-policies' }, icon: <LockKeyhole size={17} />, ar: 'سياسات التصنيف', en: 'Classification policies' },
  { key: 'fields', route: { name: 'authorization', resource: 'field-access-templates' }, icon: <FileKey2 size={17} />, ar: 'قوالب وصول الحقول', en: 'Field access templates' },
  { key: 'supervisory', route: { name: 'authorization', resource: 'supervisory' }, icon: <Network size={17} />, ar: 'العلاقات الإشرافية', en: 'Supervisory relationships' },
]

function matchesRoute(activeRoute: AppRoute, target: AppRoute): boolean {
  if (activeRoute.name !== target.name) return false
  if (activeRoute.name === 'authorization' && target.name === 'authorization') return activeRoute.resource === target.resource
  return true
}

function tabLabel(locale: Locale, ar: string, en: string): string {
  return locale === 'ar' ? ar : en
}

function screenForRoute({ activeRoute }: Pick<AccessWorkspaceProps, 'activeRoute'>) {
  switch (activeRoute.name) {
    case 'identity-accounts':
      return <IdentityAccounts />
    case 'authorization':
      return <AuthorizationAdmin resource={activeRoute.resource as AdminResource} />
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

export function AccessWorkspace({ locale, activeRoute, navigate }: AccessWorkspaceProps) {
  const isAuthorizationRoute = activeRoute.name === 'authorization'
  const isAccessRoute = ['identity-accounts', 'authorization', 'access-explanation', 'access-context'].includes(activeRoute.name)
  const primaryTabs: RoutedTab[] = [
    {
      key: 'accounts',
      route: { name: 'identity-accounts' },
      path: pathFromRoute({ name: 'identity-accounts' }),
      active: activeRoute.name === 'identity-accounts',
      label: screenCopy[locale].accounts,
      icon: <Users size={17} />,
    },
    {
      key: 'roles-and-capabilities',
      route: { name: 'authorization', resource: 'roles' },
      path: pathFromRoute({ name: 'authorization', resource: 'roles' }),
      active: isAuthorizationRoute,
      label: screenCopy[locale].rolesCapabilities,
      icon: <ShieldCheck size={17} />,
    },
    {
      key: 'scopes',
      route: { name: 'access-context' },
      path: pathFromRoute({ name: 'access-context' }),
      active: activeRoute.name === 'access-context',
      label: screenCopy[locale].accessScopes,
      icon: <Network size={17} />,
    },
    {
      key: 'decision',
      route: { name: 'access-explanation' },
      path: pathFromRoute({ name: 'access-explanation' }),
      active: activeRoute.name === 'access-explanation',
      label: screenCopy[locale].testAnAccessDecision,
      icon: <ClipboardCheck size={17} />,
    },
  ]
  const authorizationTabViews: RoutedTab[] = authorizationTabs.map((tab) => ({
    key: tab.key,
    path: pathFromRoute(tab.route),
    active: matchesRoute(activeRoute, tab.route),
    label: tabLabel(locale, tab.ar, tab.en),
    icon: tab.icon,
    route: tab.route,
  }))
  const currentScreen = screenForRoute({ activeRoute })

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
        label={screenCopy[locale].identityAndAuthorizationNavigation}
        tabs={primaryTabs}
        onNavigate={navigate}
      />
      {isAuthorizationRoute && (
        <WorkspaceTabs
          label={screenCopy[locale].rolesAndCapabilitiesSections}
          tabs={authorizationTabViews}
          onNavigate={navigate}
        />
      )}
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
