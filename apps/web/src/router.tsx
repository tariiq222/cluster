import { lazy, Suspense, useMemo, type ReactNode } from 'react'
import {
  createBrowserRouter,
  createMemoryRouter,
  Link,
  Navigate,
  RouterProvider,
  useNavigate,
  useParams,
  useRouteError,
  type RouteObject,
} from 'react-router-dom'
import { FileQuestion, RotateCcw } from 'lucide-react'
import { AppShell } from './app/AppShell'
import { usePrincipal } from './app/principal-context'
import { useLocale } from './app/session-context'
import { shellCopy } from './i18n'
import { Button } from '@/components/ui/button'
import { EmptyState, ErrorState, LoadingState } from '@/components/states'
import { Skeleton } from '@/components/ui/skeleton'

/*
 * Route-level code splitting (Task 14 Step 1): each screen is fetched only
 * when its route is first visited. The modules use named exports, so every
 * named export is mapped to the default the lazy() loader expects.
 */
const HomeDashboard = lazy(() =>
  import('./features/dashboard/HomeDashboard').then((m) => ({
    default: m.HomeDashboard,
  })),
)
const TasksScreen = lazy(() =>
  import('./features/tasks/TasksScreen').then((m) => ({
    default: m.TasksScreen,
  })),
)
const TaskDetailScreen = lazy(() =>
  import('./features/tasks/TaskDetailScreen').then((m) => ({
    default: m.TaskDetailScreen,
  })),
)
const TaskCreateScreen = lazy(() =>
  import('./features/tasks/TaskCreateScreen').then((m) => ({
    default: m.TaskCreateScreen,
  })),
)
const DocumentsScreen = lazy(() =>
  import('./features/documents/DocumentsScreen').then((m) => ({
    default: m.DocumentsScreen,
  })),
)
const DocumentCreateScreen = lazy(() =>
  import('./features/documents/DocumentCreateScreen').then((m) => ({
    default: m.DocumentCreateScreen,
  })),
)
const DocumentDetailScreen = lazy(() =>
  import('./features/documents/DocumentDetailScreen').then((m) => ({
    default: m.DocumentDetailScreen,
  })),
)
const OrganizationScreen = lazy(() =>
  import('./features/organization/OrganizationScreen').then((m) => ({
    default: m.OrganizationScreen,
  })),
)
const AccessScreen = lazy(() =>
  import('./features/accounts/AccessScreen').then((m) => ({
    default: m.AccessScreen,
  })),
)
const OrganizationClusterFormScreen = lazy(() =>
  import('./features/organization/ClusterFormScreen').then((m) => ({
    default: m.ClusterFormScreen,
  })),
)
const OrganizationFacilityFormScreen = lazy(() =>
  import('./features/organization/FacilityFormScreen').then((m) => ({
    default: m.FacilityFormScreen,
  })),
)
const OrganizationUnitCreateScreen = lazy(() =>
  import('./features/organization/UnitCreateScreen').then((m) => ({
    default: m.UnitCreateScreen,
  })),
)
const OrganizationPositionCreateScreen = lazy(() =>
  import('./features/organization/PositionCreateScreen').then((m) => ({
    default: m.PositionCreateScreen,
  })),
)
const OrganizationJobTitleCreateScreen = lazy(() =>
  import('./features/organization/JobTitleCreateScreen').then((m) => ({
    default: m.JobTitleCreateScreen,
  })),
)
const OrganizationPersonFormScreen = lazy(() =>
  import('./features/organization/PersonFormScreen').then((m) => ({
    default: m.PersonFormScreen,
  })),
)
const OrganizationAssignmentCreateScreen = lazy(() =>
  import('./features/organization/OrganizationAssignmentCreateScreen').then((m) => ({
    default: m.OrganizationAssignmentCreateScreen,
  })),
)
const SecuritySettingEditScreen = lazy(() =>
  import('./features/platform/SecuritySettingEditScreen').then((m) => ({
    default: m.SecuritySettingEditScreen,
  })),
)
const CalendarCreateScreen = lazy(() =>
  import('./features/platform/CalendarCreateScreen').then((m) => ({
    default: m.CalendarCreateScreen,
  })),
)
const CalendarWeekdayEditScreen = lazy(() =>
  import('./features/platform/CalendarWeekdayEditScreen').then((m) => ({
    default: m.CalendarWeekdayEditScreen,
  })),
)
const CalendarExceptionCreateScreen = lazy(() =>
  import('./features/platform/CalendarExceptionCreateScreen').then((m) => ({
    default: m.CalendarExceptionCreateScreen,
  })),
)
const MaintenanceCreateScreen = lazy(() =>
  import('./features/platform/MaintenanceCreateScreen').then((m) => ({
    default: m.MaintenanceCreateScreen,
  })),
)
const LogsRestoreScreen = lazy(() =>
  import('./features/platform/LogsRestoreScreen').then((m) => ({
    default: m.LogsRestoreScreen,
  })),
)
const AlertPolicyEditScreen = lazy(() =>
  import('./features/platform/AlertPolicyEditScreen').then((m) => ({
    default: m.AlertPolicyEditScreen,
  })),
)
const UploadVersionScreen = lazy(() =>
  import('./features/documents/UploadVersionScreen').then((m) => ({
    default: m.UploadVersionScreen,
  })),
)
const AuditEventDetailScreen = lazy(() =>
  import('./features/audit/AuditEventDetailScreen').then((m) => ({
    default: m.AuditEventDetailScreen,
  })),
)
const AccountCreateScreen = lazy(() =>
  import('./features/accounts/AccountCreateScreen').then((m) => ({
    default: m.AccountCreateScreen,
  })),
)
const AccountDetailScreen = lazy(() =>
  import('./features/accounts/AccountDetailScreen').then((m) => ({
    default: m.AccountDetailScreen,
  })),
)
const RoleFormScreen = lazy(() =>
  import('./features/accounts/RoleFormScreen').then((m) => ({
    default: m.RoleFormScreen,
  })),
)
const AssignmentCreateScreen = lazy(() =>
  import('./features/accounts/AssignmentCreateScreen').then((m) => ({
    default: m.AssignmentCreateScreen,
  })),
)
const ReportsMonitoringScreen = lazy(() =>
  import('./features/reports/ReportsMonitoringScreen').then((m) => ({
    default: m.ReportsMonitoringScreen,
  })),
)
const PlatformManagementScreen = lazy(() =>
  import('./features/platform/PlatformManagementScreen').then((m) => ({
    default: m.PlatformManagementScreen,
  })),
)
const NotificationsScreen = lazy(() =>
  import('./features/notifications/NotificationsScreen').then((m) => ({
    default: m.NotificationsScreen,
  })),
)
const SearchScreen = lazy(() =>
  import('./features/search/SearchScreen').then((m) => ({
    default: m.SearchScreen,
  })),
)
const MeScreen = lazy(() =>
  import('./features/identity/MeScreen').then((m) => ({
    default: m.MeScreen,
  })),
)
const ImportWizard = lazy(() =>
  import('./features/imports/ImportWizard').then((m) => ({
    default: m.ImportWizard,
  })),
)

/*
 * Localized recovery surface for the case where the URL matched a
 * parameterized route but a required param was missing or unparseable
 * (e.g. `/platform/calendars/cal-1/weekdays/abc/edit` — `abc` is not a
 * valid weekday). It deliberately reuses the shared not-found copy: from
 * the user's perspective there is no resource to point at, so the
 * same string the catch-all shows is the right answer. The 403/404
 * non-disclosure rule is preserved — a missing param must not reveal
 * which resources exist.
 */
export function ParamRecoverySurface() {
  const locale = useLocale()
  return (
    <EmptyState
      icon={<FileQuestion aria-hidden="true" />}
      title={shellCopy[locale].notFound}
    />
  )
}

export function TaskDetailRoute() {
  const { taskId } = useParams()
  if (!taskId) return <ParamRecoverySurface />
  return <TaskDetailScreen taskId={taskId} />
}

export function DocumentDetailRoute() {
  const { documentId } = useParams()
  if (!documentId) return <ParamRecoverySurface />
  return <DocumentDetailScreen documentId={documentId} />
}

export function DocumentVersionUploadRoute() {
  const { documentId } = useParams()
  if (!documentId) return <ParamRecoverySurface />
  return <UploadVersionScreen documentId={documentId} />
}

export function AuditEventDetailRoute() {
  const { eventId } = useParams()
  if (!eventId) return <ParamRecoverySurface />
  return <AuditEventDetailScreen eventId={eventId} />
}

export function OrganizationFacilityFormRoute() {
  const { facilityId } = useParams()
  return <OrganizationFacilityFormScreen facilityId={facilityId} />
}

export function OrganizationPersonFormRoute() {
  const { personId } = useParams()
  return <OrganizationPersonFormScreen personId={personId} />
}

export function SecuritySettingEditRoute() {
  const { versionId, settingKey } = useParams()
  if (!versionId || !settingKey) return <ParamRecoverySurface />
  return <SecuritySettingEditScreen versionId={versionId} settingKey={settingKey} />
}

export function CalendarWeekdayEditRoute() {
  const { calendarId, weekday } = useParams()
  const parsed = weekday !== undefined ? Number(weekday) : Number.NaN
  if (!calendarId || !Number.isInteger(parsed)) return <ParamRecoverySurface />
  return <CalendarWeekdayEditScreen calendarId={calendarId} weekday={parsed} />
}

export function CalendarExceptionCreateRoute() {
  const { calendarId } = useParams()
  if (!calendarId) return <ParamRecoverySurface />
  return <CalendarExceptionCreateScreen calendarId={calendarId} />
}

export function AlertPolicyEditRoute() {
  const { policyId } = useParams()
  if (!policyId) return <ParamRecoverySurface />
  return <AlertPolicyEditScreen policyId={policyId} />
}

export function AccountDetailRoute() {
  const { accountId } = useParams()
  if (!accountId) return <ParamRecoverySurface />
  return <AccountDetailScreen accountId={accountId} />
}

export function RoleFormRoute() {
  const { roleId } = useParams()
  return <RoleFormScreen roleId={roleId} />
}

export function ImportReviewRoute() {
  const { jobId } = useParams()
  if (!jobId) return <ParamRecoverySurface />
  return <ImportWizard jobId={jobId} />
}

function NotFoundScreen() {
  const locale = useLocale()
  return (
    <EmptyState
      icon={<FileQuestion aria-hidden="true" />}
      title={shellCopy[locale].notFound}
    />
  )
}

/*
 * Route-level error boundary: catches render failures and lazy-chunk
 * rejections. We log the raw error for debugging but the rendered surface
 * is the localized "something went wrong" copy with retry + home actions
 * — the technical message, stack, or any thrown payload is never rendered
 * to the user. 403/404 resource non-disclosure is preserved (the copy is
 * generic), and the retry re-resolves the current route so a transient
 * chunk load can succeed on the second try.
 */
function RouteErrorElement() {
  const locale = useLocale()
  const navigate = useNavigate()
  const routeError = useRouteError()
  // The error is intentionally not rendered: it is logged for operator
  // diagnostics only and never leaks into the visible surface.
  if (typeof console !== 'undefined') {
    // eslint-disable-next-line no-console
    console.error('Route error boundary caught', routeError)
  }
  const retry = () => {
    void navigate(
      window.location.pathname + window.location.search + window.location.hash,
      { replace: true },
    )
  }
  return (
    <EmptyState
      icon={<FileQuestion aria-hidden="true" />}
      title={shellCopy[locale].error}
      action={
        <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
          <Button type="button" variant="outline" size="sm" onClick={retry}>
            <RotateCcw aria-hidden="true" />
            {shellCopy[locale].retry}
          </Button>
          <Button asChild variant="default" size="sm">
            <Link to="/">{shellCopy[locale].home}</Link>
          </Button>
        </div>
      }
    />
  )
}

/*
 * Shared route-loading fallback: the shared LoadingState skeleton behind an
 * accessible status announcement. No raw spinners. It renders inside the
 * session/locale context, so the announcement is localized.
 */
export function RouteFallback() {
  const locale = useLocale()
  return (
    <div role="status" aria-live="polite" className="space-y-3 p-4 sm:p-6">
      <span className="sr-only">{shellCopy[locale].loading}</span>
      <LoadingState rows={4} />
    </div>
  )
}

/*
 * Every lazy route screen element renders through this single shared
 * Suspense boundary, so one fallback covers all route chunks. The shell and
 * route definitions stay eager.
 */
function RouteBoundary({ children }: { children: ReactNode }) {
  return <Suspense fallback={<RouteFallback />}>{children}</Suspense>
}

function WorkspacePlaceholder({ title }: { title: string }) {
  return (
    <div className="flex flex-col items-center gap-2 py-16 text-center">
      <p className="text-foreground font-medium">{title}</p>
      <p className="text-muted-foreground text-sm">
        قيد التطوير · Under construction
      </p>
    </div>
  )
}

/*
 * Feature-gated paths are registered ONLY when the flag is on. With the flag
 * off the API answers a non-disclosing 404, so the route must not exist at
 * all — an unregistered route cannot leak a loading skeleton.
 */
function workManagementRoutes() {
  return [
    {
      path: '/work-records',
      element: <WorkspacePlaceholder title="سجلات العمل · Work Records" />,
    },
    {
      path: '/work-records/:recordId',
      element: <WorkspacePlaceholder title="سجل العمل · Work Record" />,
    },
    {
      path: '/inbox',
      element: (
        <WorkspacePlaceholder title="صندوق الموافقات · Approvals Inbox" />
      ),
    },
    {
      path: '/workflow',
      element: <WorkspacePlaceholder title="سير العمل · Workflow" />,
    },
    {
      path: '/work-definitions',
      element: <WorkspacePlaceholder title="نماذج العمل · Work Definitions" />,
    },
  ]
}

const ROUTES = [
  {
    path: '/',
    element: (
      <RouteBoundary>
        <HomeDashboard />
      </RouteBoundary>
    ),
  },
  {
    path: '/tasks',
    element: (
      <RouteBoundary>
        <TasksScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/tasks/new',
    element: (
      <RouteBoundary>
        <TaskCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/tasks/:taskId',
    element: (
      <RouteBoundary>
        <TaskDetailRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/documents',
    element: (
      <RouteBoundary>
        <DocumentsScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/documents/new',
    element: (
      <RouteBoundary>
        <DocumentCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/documents/:documentId',
    element: (
      <RouteBoundary>
        <DocumentDetailRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/documents/:documentId/versions/new',
    element: (
      <RouteBoundary>
        <DocumentVersionUploadRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization',
    element: (
      <RouteBoundary>
        <OrganizationScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/import',
    element: (
      <RouteBoundary>
        <ImportReviewRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/import/:jobId',
    element: (
      <RouteBoundary>
        <ImportReviewRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/cluster/new',
    element: (
      <RouteBoundary>
        <OrganizationClusterFormScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/cluster/edit',
    element: (
      <RouteBoundary>
        <OrganizationClusterFormScreen mode="edit" />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/facilities/new',
    element: (
      <RouteBoundary>
        <OrganizationFacilityFormScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/facilities/:facilityId/edit',
    element: (
      <RouteBoundary>
        <OrganizationFacilityFormRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/units/new',
    element: (
      <RouteBoundary>
        <OrganizationUnitCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/positions/new',
    element: (
      <RouteBoundary>
        <OrganizationPositionCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/job-titles/new',
    element: (
      <RouteBoundary>
        <OrganizationJobTitleCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/people/new',
    element: (
      <RouteBoundary>
        <OrganizationPersonFormScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/people/:personId/edit',
    element: (
      <RouteBoundary>
        <OrganizationPersonFormRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/organization/assignments/new',
    element: (
      <RouteBoundary>
        <OrganizationAssignmentCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/access',
    element: (
      <RouteBoundary>
        <AccessScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/access/accounts/new',
    element: (
      <RouteBoundary>
        <AccountCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/access/accounts/:accountId',
    element: (
      <RouteBoundary>
        <AccountDetailRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/access/roles/new',
    element: (
      <RouteBoundary>
        <RoleFormScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/access/roles/:roleId/edit',
    element: (
      <RouteBoundary>
        <RoleFormRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/access/role-assignments/new',
    element: (
      <RouteBoundary>
        <AssignmentCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/reports',
    element: (
      <RouteBoundary>
        <ReportsMonitoringScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/reports/audit/events/:eventId',
    element: (
      <RouteBoundary>
        <AuditEventDetailRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform',
    element: (
      <RouteBoundary>
        <PlatformManagementScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/settings/:versionId/security/:settingKey/edit',
    element: (
      <RouteBoundary>
        <SecuritySettingEditRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/calendars/new',
    element: (
      <RouteBoundary>
        <CalendarCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/calendars/:calendarId/weekdays/:weekday/edit',
    element: (
      <RouteBoundary>
        <CalendarWeekdayEditRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/calendars/:calendarId/exceptions/new',
    element: (
      <RouteBoundary>
        <CalendarExceptionCreateRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/maintenance/new',
    element: (
      <RouteBoundary>
        <MaintenanceCreateScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/logs/restore',
    element: (
      <RouteBoundary>
        <LogsRestoreScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/platform/alerts/:policyId/edit',
    element: (
      <RouteBoundary>
        <AlertPolicyEditRoute />
      </RouteBoundary>
    ),
  },
  {
    path: '/notifications',
    element: (
      <RouteBoundary>
        <NotificationsScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/search',
    element: (
      <RouteBoundary>
        <SearchScreen />
      </RouteBoundary>
    ),
  },
  {
    path: '/me',
    element: (
      <RouteBoundary>
        <MeScreen />
      </RouteBoundary>
    ),
  },
  { path: '/login', element: <Navigate to="/" replace /> },
]

/*
 * Retired paths redirect so bookmarks and existing journeys keep working.
 * They are compatibility shims, not destinations — routePaths() omits them.
 *
 * `/me/security` and `/me/access` keep the user's tab intent by carrying
 * it in the query string that MeScreen's URL-backed tab hook reads back.
 * The earlier direct redirect to `/me` lost the tab the user had open
 * and re-opened the screen on the default tab; preserving `?tab=`
 * removes the stale-URL smell without making the redirect destination
 * a registered route.
 */
const REDIRECTS = [
  { path: '/api-docs', element: <Navigate to="/" replace /> },
  { path: '/accounts-permissions', element: <Navigate to="/access" replace /> },
  { path: '/reports-monitoring', element: <Navigate to="/reports" replace /> },
  {
    path: '/platform-management',
    element: <Navigate to="/platform" replace />,
  },
  { path: '/audit', element: <Navigate to="/reports" replace /> },
  { path: '/dashboards', element: <Navigate to="/reports" replace /> },
  { path: '/imports', element: <Navigate to="/organization/import" replace /> },
  {
    path: '/imports/:jobId',
    element: <ImportsCompatRedirect />,
  },
  { path: '/me/security', element: <Navigate to="/me?tab=security" replace /> },
  { path: '/me/access', element: <Navigate to="/me?tab=access" replace /> },
]

/*
 * The retired `/imports/:jobId` redirect must interpolate the actual job
 * id, not the literal template `:jobId`. Reading the param from the route
 * and composing the destination string at render time is the only way the
 * browser-issued `<a href="/imports/abc-123">` link lands on the correct
 * review screen instead of breaking the deep link into a literal segment
 * that no route matches.
 */
function ImportsCompatRedirect() {
  const { jobId } = useParams()
  if (!jobId) return <Navigate to="/organization/import" replace />
  return <Navigate to={`/organization/import/${jobId}`} replace />
}

export function routePaths(): string[] {
  return ROUTES.map((route) => route.path)
}

interface RouterConfig {
  features: { work_management: boolean; tasks: boolean }
  onLogout: () => void
}

/*
 * The same route tree is needed in two forms: a real browser router for
 * production, and a memory router for tests so we can drive the route
 * resolver from `initialEntries` without touching `window.history`. The
 * production factory accepts the test routes only when the caller opts
 * in (the test-only factory below); the public `router` never carries
 * them in production.
 */
interface BuildRoutesConfig {
  features: RouterConfig['features']
  additionalRoutes?: RouteObject[]
}

function buildRouteConfigs({ features, additionalRoutes = [] }: BuildRoutesConfig): RouteObject[] {
  return [
    ...(ROUTES as RouteObject[]),
    ...(REDIRECTS as RouteObject[]),
    ...(features.work_management ? (workManagementRoutes() as RouteObject[]) : []),
    ...additionalRoutes,
    { path: '*', element: <NotFoundScreen /> },
  ]
}

export function router({ features, onLogout }: RouterConfig) {
  return createBrowserRouter([
    {
      element: <AppShell onLogout={onLogout} />,
      errorElement: <RouteErrorElement />,
      children: buildRouteConfigs({ features }),
    },
  ])
}

/*
 * Test-only factory: builds a memory router with the production route
 * tree so a test can mount the AppRouter against a chosen `initialEntries`
 * path. The `additionalRoutes` slot lets a test inject a probe route
 * (e.g. one that throws to exercise the error boundary) without
 * polluting the production route table.
 */
export function createTestRouter({
  features,
  onLogout,
  initialEntries,
  additionalRoutes,
}: RouterConfig & {
  initialEntries: string[]
  additionalRoutes?: RouteObject[]
}) {
  return createMemoryRouter(
    [
      {
        element: <AppShell onLogout={onLogout} />,
        errorElement: <RouteErrorElement />,
        children: buildRouteConfigs({ features, additionalRoutes }),
      },
    ],
    { initialEntries },
  )
}

function PrincipalLoadingState() {
  const locale = useLocale()
  return (
    <main
      className="grid min-h-svh gap-4 bg-background p-4 md:grid-cols-[16rem_1fr] sm:p-6"
      data-testid="principal-loading"
    >
      <div
        role="status"
        aria-live="polite"
        className="space-y-3 border-e border-border pe-4"
      >
        <span className="sr-only">{shellCopy[locale].loading}</span>
        <Skeleton className="h-11 w-full" />
        <Skeleton className="h-8 w-3/4" />
        <Skeleton className="h-8 w-full" />
        <Skeleton className="h-8 w-5/6" />
      </div>
      <div className="space-y-4">
        <Skeleton className="h-14 w-full" />
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-28 w-full" />
        <Skeleton className="h-28 w-full" />
      </div>
    </main>
  )
}

function ResolvedRouter({
  features,
  onLogout,
}: {
  features: RouterConfig['features']
  onLogout: () => void
}) {
  const instance = useMemo(
    () => router({ features, onLogout }),
    [features, onLogout],
  )
  return <RouterProvider router={instance} />
}

/*
 * The router is built only after the principal resolves: features come from
 * /me, and feature-gated routes must be absent — not disabled — while the
 * flag is off. Returning null until then leaks no skeleton.
 */
export function AppRouter({ onLogout }: { onLogout: () => void }) {
  const principal = usePrincipal()
  const locale = useLocale()
  if (!principal.features) {
    if (principal.state === 'error') {
      return (
        <main className="min-h-svh bg-background p-4 sm:p-6">
          <div className="mx-auto max-w-2xl pt-12">
            <ErrorState
              locale={locale}
              correlationId={principal.errorCorrelationId}
              onRetry={principal.refresh}
            />
          </div>
        </main>
      )
    }
    return <PrincipalLoadingState />
  }
  return <ResolvedRouter features={principal.features} onLogout={onLogout} />
}
