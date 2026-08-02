import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { ShieldAlert } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { useNavigate } from '../../../app/navigation-context'
import { ApiError, stateFromError, type ResourceState } from '../../../api/http'
import { statusLabel, formatDate, type Locale } from '../../../i18n'
import * as access from '../../../api/access'
import * as generated from '../../../api/generated/cluster'
import { useRoleCapabilityCacheScope } from '../../../api/hooks'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
  roleCopy,
  CAPABILITY_ACTION_LABELS,
  CAPABILITY_MODULE_LABELS,
} from '../accounts-copy'

/* ------------------------------------------------------------------ */
/* Roles / capabilities / assignments workspace                       */
/* ------------------------------------------------------------------ */

type ResourceKey = 'roles' | 'capabilities' | 'role-assignments'

type RoleCopy = (typeof roleCopy)[Locale]
type CapabilityCatalogItem = access.NormalizedCapabilityRow
type RoleRow = generated.AuthorizationRole
type AssignmentRow = access.NormalizedAssignmentRow
type ResourceRow = Record<string, unknown> & { id: string; lock_version?: number }

function roleDisplayName(role: generated.AuthorizationRole, locale: 'ar' | 'en'): string {
  return locale === 'ar'
    ? (role.name_ar ?? role.name_en ?? role.code)
    : (role.name_en ?? role.name_ar ?? role.code)
}

function accountLabel(account: generated.UserAccount, locale: 'ar' | 'en'): string {
  return locale === 'en' && account.display_name_en
    ? account.display_name_en
    : account.display_name_ar
}

function scopeTypeLabel(scopeType: string | undefined, text: RoleCopy): string {
  if (scopeType === 'cluster') return text.scopeCluster
  if (scopeType === 'facility') return text.scopeFacility
  if (scopeType === 'unit') return text.scopeUnit
  return text.unavailable
}

function isSensitive(sensitivity: string | undefined): boolean {
  if (!sensitivity) return false
  return ['sensitive', 'critical', 'confidential', 'top_secret'].includes(sensitivity)
}

function sensitivityLabel(sensitivity: string | undefined, text: RoleCopy): string {
  switch (sensitivity) {
    case 'normal':
      return text.sensitivityNormal
    case 'sensitive':
      return text.sensitivitySensitive
    case 'critical':
      return text.sensitivityCritical
    case 'public':
      return text.sensitivityPublic
    case 'internal':
      return text.sensitivityInternal
    case 'confidential':
      return text.sensitivityConfidential
    case 'top_secret':
      return text.sensitivityTopSecret
    default:
      return sensitivity ?? text.unavailable
  }
}

/*
 * Localized action verb for the capability catalog. The wire's
 * `action` is the last dotted segment of a capability code; an unknown
 * action falls back to the raw value so the operator still sees what
 * the backend reported.
 */
function capabilityActionLabel(action: string | undefined, locale: Locale): string {
  if (!action) return ''
  return CAPABILITY_ACTION_LABELS[action]?.[locale] ?? action
}

/*
 * Localized area name for the capability catalog. The wire's
 * `module_code` is the first dotted segment; an unknown module falls
 * back to the raw value for the same reason as the action label.
 */
function capabilityModuleLabel(moduleCode: string | undefined, locale: Locale): string {
  if (!moduleCode) return ''
  return CAPABILITY_MODULE_LABELS[moduleCode]?.[locale] ?? moduleCode
}

function effectiveStatusLabel(status: string | undefined, text: RoleCopy): string {
  if (status === 'pending') return text.effectivePending
  if (status === 'active') return text.effectiveActive
  if (status === 'expired') return text.effectiveExpired
  if (status === 'revoked') return text.effectiveRevoked
  return status ?? text.unavailable
}

type ConfirmAction =
  | { kind: 'archive-role'; role: generated.AuthorizationRole }
  | { kind: 'activate-assignment'; assignment: AssignmentRow }
  | { kind: 'revoke-assignment'; assignment: AssignmentRow }
  | { kind: 'expire-assignment'; assignment: AssignmentRow }
  | null

export function RolesTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const navigate = useNavigate()
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const capabilities = principal.capabilities ?? []
  /*
   * The shared scoped `role-capability` association walk is invalidated
   * here on every principal scope/epoch or session-token change. Any
   * nested consumer in this tab — the resource page, the labels query,
   * the role picker in AssignmentSheet, the edit sheet — observes the
   * same module-level cache, so a single mount is sufficient to keep the
   * whole workspace coherent.
   */
  useRoleCapabilityCacheScope()
  const canReadRoles = capabilities.includes('authorization.role.read')
  const canManageRoles = capabilities.includes('authorization.role.manage')
  const canReadCapabilities = capabilities.includes('authorization.capability.read')
  const canReadAssignments = capabilities.includes('authorization.assignment.read')
  const canManageAssignments = capabilities.includes('authorization.assignment.manage')
  /*
   * Editing/creating a role requires reconstructing the capability set
   * client-side: the capability catalog plus the role-capabilities
   * associations. Without both reads the controls stay hidden so an edit
   * can never silently erase a role's capability set.
   */
  const canReconstructRole = canReadCapabilities && canReadAssignments

  /*
   * The resource switcher is seeded from the URL (`?resource=...`) so the
   * assignment-create page can return the operator to the exact resource
   * it came from. Switching resources resets pagination.
   */
  const [resource, setResource] = useState<ResourceKey>(() => {
    const param = searchParams.get('resource')
    if (
      param === 'roles' ||
      param === 'capabilities' ||
      param === 'role-assignments'
    ) {
      return param
    }
    return canReadRoles ? 'roles' : canReadCapabilities ? 'capabilities' : 'role-assignments'
  })
  const [history, setHistory] = useState<string[]>([])
  const [confirming, setConfirming] = useState<ConfirmAction>(null)
  const [mutationError, setMutationError] = useState<string | null>(null)

  const cursor = history.length > 0 ? history[history.length - 1] : undefined

  /*
   * Roles are enriched with capability_codes (from the scoped
   * role-capabilities resource) only while the principal can read
   * assignments; otherwise the plain role list is used.
   */
  const enrichRoles = canReadAssignments && resource === 'roles'

  const resourceQuery = useQuery({
    queryKey: enrichRoles
      ? (['access-roles-enriched', cursor ?? null] as const)
      : (['access-admin', resource, cursor ?? null] as const),
    queryFn: () => {
      if (resource === 'roles') {
        return enrichRoles
          ? access.listRolesWithCapabilities(cursor)
          : access.listAdminResources('roles', cursor)
      }
      if (resource === 'capabilities') {
        return access.listCapabilities(cursor)
      }
      return access.listAssignments(cursor)
    },
  })

  /* Supporting labels for assignments: fetched only while the
   * role-assignments resource is active — never preloaded. The
   * assignment-create page owns its own picker loads. */
  const accountsQuery = useQuery({
    queryKey: ['access-accounts-labels'] as const,
    queryFn: () => access.listAccounts(),
    enabled: resource === 'role-assignments',
  })
  const rolesLabelsQuery = useQuery({
    queryKey: canReadAssignments
      ? (['access-roles-labels-enriched'] as const)
      : (['access-roles-labels'] as const),
    queryFn: () =>
      canReadAssignments
        ? access.listRolesWithCapabilities()
        : access.listAdminResources('roles'),
    enabled: resource === 'role-assignments',
  })

  const accounts = (accountsQuery.data?.items ?? []) as unknown as generated.UserAccount[]
  const rolesForLabels = (rolesLabelsQuery.data?.items ?? []) as unknown as generated.AuthorizationRole[]

  const rawItems = resourceQuery.data?.items ?? []
  const nextCursor = resourceQuery.data?.next_cursor ?? null

  const state: ResourceState = resourceQuery.isLoading
    ? 'loading'
    : resourceQuery.isError
      ? stateFromError(resourceQuery.error)
      : rawItems.length === 0
        ? 'empty'
        : 'ready'

  function switchResource(next: ResourceKey) {
    setResource(next)
    setHistory([])
    setMutationError(null)
  }

  const invalidateActive = () => {
    void queryClient.invalidateQueries({ queryKey: ['access-admin'] })
    void queryClient.invalidateQueries({ queryKey: ['access-roles-enriched'] })
    void queryClient.invalidateQueries({ queryKey: ['access-roles-labels'] })
    void queryClient.invalidateQueries({ queryKey: ['access-roles-labels-enriched'] })
    void queryClient.invalidateQueries({ queryKey: ['access-accounts-labels'] })
  }

  /*
   * Drop the shared `role-capabilities` association walk in addition to
   * the React-Query cache. Used only after mutations that actually
   * change the allow-set (role clone, role archive, role create/update
   * through RoleSheet); assignment transitions operate on the separate
   * `role-assignments` resource and intentionally leave the association
   * walk intact.
   */
  const invalidateRoleAssociations = () => {
    access.invalidateRoleCapabilityCache()
  }

  const cloneMutation = useMutation({
    mutationFn: (role: generated.AuthorizationRole) =>
      access.transitionAdminResource(
        'roles',
        role.id,
        'clone',
        undefined,
        role.lock_version,
        csrfToken,
        'authorization-role-clone',
      ),
    onSuccess: () => {
      invalidateRoleAssociations()
      invalidateActive()
    },
    onError: (caught) =>
      setMutationError(caught instanceof ApiError ? caught.message : text.roleError),
  })

  const archiveMutation = useMutation({
    mutationFn: (role: generated.AuthorizationRole) =>
      access.updateAdminResource(
        'roles',
        role.id,
        { status: 'archived' },
        role.lock_version,
        csrfToken,
      ),
    onSuccess: () => {
      invalidateRoleAssociations()
      invalidateActive()
    },
    onError: (caught) =>
      setMutationError(caught instanceof ApiError ? caught.message : text.roleError),
  })

  const revokeMutation = useMutation({
    mutationFn: (assignment: AssignmentRow) =>
      access.transitionAdminResource(
        'role-assignments',
        assignment.id,
        'revoke',
        undefined,
        assignment.lock_version ?? 0,
        csrfToken,
        'authorization-assignment-revoke',
      ),
    onSuccess: () => invalidateActive(),
    onError: (caught) =>
      setMutationError(caught instanceof ApiError ? caught.message : text.assignmentError),
  })

  const expireMutation = useMutation({
    mutationFn: (assignment: AssignmentRow) =>
      access.transitionAdminResource(
        'role-assignments',
        assignment.id,
        'expire',
        undefined,
        assignment.lock_version ?? 0,
        csrfToken,
        'authorization-assignment-expire',
      ),
    onSuccess: () => invalidateActive(),
    onError: (caught) =>
      setMutationError(caught instanceof ApiError ? caught.message : text.assignmentError),
  })

  const activateMutation = useMutation({
    mutationFn: (assignment: AssignmentRow) =>
      access.transitionAdminResource(
        'role-assignments',
        assignment.id,
        'activate',
        undefined,
        assignment.lock_version ?? 0,
        csrfToken,
        'authorization-assignment-activate',
      ),
    onSuccess: () => invalidateActive(),
    onError: (caught) =>
      setMutationError(caught instanceof ApiError ? caught.message : text.assignmentError),
  })

  const busy =
    cloneMutation.isPending ||
    archiveMutation.isPending ||
    revokeMutation.isPending ||
    expireMutation.isPending ||
    activateMutation.isPending

  const columns: ColumnDef<ResourceRow>[] = []

  if (resource === 'roles') {
    columns.push(
      {
        accessorKey: 'name_ar',
        header: text.name,
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          return <span className="font-medium break-words whitespace-normal">{roleDisplayName(role, locale)}</span>
        },
      },
      {
        accessorKey: 'code',
        header: text.code,
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          return <span className="font-mono text-sm break-all whitespace-normal" dir="ltr">{role.code}</span>
        },
      },
      {
        accessorKey: 'is_system_role',
        header: text.capabilityGroup,
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          return (
            <Badge variant="outline">
              {role.is_system_role ? text.systemRole : text.customRole}
            </Badge>
          )
        },
      },
      {
        accessorKey: 'status',
        header: text.assignmentStatus,
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          return (
            <Badge variant="outline">
              {statusLabel(role.status, locale)}
            </Badge>
          )
        },
      },
      {
        accessorKey: 'capability_codes',
        header: text.capabilities,
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          return (
            <span className="text-sm whitespace-normal">
              {role.capability_codes?.length ?? 0}{' '}
              {text.countCapabilities}
            </span>
          )
        },
      },
      {
        accessorKey: 'id',
        header: '',
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          if (!canManageRoles) return null
          return (
            <div className="flex flex-wrap items-center gap-2">
              {role.is_system_role ? (
                <Button
                  size="sm"
                  variant="outline"
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setMutationError(null)
                    cloneMutation.mutate(role)
                  }}
                >
                  {text.clone}
                </Button>
              ) : (
                <>
                  {canReconstructRole ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      type="button"
                      disabled={busy}
                      onClick={() => {
                        setMutationError(null)
                        navigate(`/access/roles/${role.id}/edit`)
                      }}
                    >
                      {text.edit}
                    </Button>
                  ) : null}
                  <Button
                    size="sm"
                    variant="outline"
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      setMutationError(null)
                      setConfirming({ kind: 'archive-role', role })
                    }}
                  >
                    {text.archive}
                  </Button>
                </>
              )}
            </div>
          )
        },
      },
    )
  } else if (resource === 'capabilities') {
    /*
     * Three-column capability catalog: a translated action verb with the
     * canonical capability code underneath, a translated area name with
     * the module_code underneath (and the distinct group_label as a
     * tertiary inline line), and a sensitivity badge. The capability
     * code and module_code are still rendered — as small mono secondary
     * lines, never as wide first-class columns — so the operator keeps
     * the canonical identifier in view without the table needing to
     * accommodate a five-column row.
     */
    columns.push(
      {
        accessorKey: 'code',
        header: text.capabilityColumnPermission,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          const actionLabel = capabilityActionLabel(capability.action, locale)
          return (
            <div className="flex min-w-0 flex-col gap-0.5">
              <span className="font-medium text-sm break-words whitespace-normal">
                {actionLabel || text.unavailable}
              </span>
              <span
                className="font-mono text-xs text-muted-foreground break-all whitespace-normal"
                dir="ltr"
                title={text.capabilityCodeHint}
              >
                {capability.code ?? text.unavailable}
              </span>
            </div>
          )
        },
      },
      {
        accessorKey: 'module_code',
        header: text.capabilityColumnArea,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          const moduleLabel = capabilityModuleLabel(capability.module_code, locale)
          const groupLabel = capability.group_label
          const groupIsDistinct = typeof groupLabel === 'string'
            && groupLabel.length > 0
            && groupLabel !== capability.module_code
          return (
            <div className="flex min-w-0 flex-col gap-0.5">
              <span className="font-medium text-sm break-words whitespace-normal">
                {moduleLabel || text.unavailable}
              </span>
              <span
                className="font-mono text-xs text-muted-foreground break-all whitespace-normal"
                dir="ltr"
                title={text.capabilityCodeHint}
              >
                {capability.module_code ?? text.unavailable}
              </span>
              {groupIsDistinct ? (
                <span
                  className="text-muted-foreground text-xs break-words whitespace-normal"
                  title={text.capabilityGroupHint}
                >
                  {groupLabel}
                </span>
              ) : null}
            </div>
          )
        },
      },
      {
        accessorKey: 'sensitivity',
        header: text.capabilityColumnSensitivity,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          const sensitivity = capability.sensitivity
          const label = sensitivityLabel(sensitivity, text)
          return (
            <Badge
              variant="outline"
              className="inline-flex items-center gap-1.5 font-normal"
            >
              {isSensitive(sensitivity) ? (
                <ShieldAlert aria-hidden="true" className="size-3.5" />
              ) : null}
              <span>{label}</span>
            </Badge>
          )
        },
      },
    )
  } else {
    columns.push(
      {
        accessorKey: 'subject_user_id',
        header: text.subject,
        cell: ({ row }) => {
          const assignment = row.original as unknown as AssignmentRow
          const account = accounts.find((item) => item.id === assignment.subject_user_id)
          return account ? (
            <span className="font-medium break-words whitespace-normal">{accountLabel(account, locale)}</span>
          ) : (
            <span className="text-muted-foreground text-sm">{text.unavailable}</span>
          )
        },
      },
      {
        accessorKey: 'role_id',
        header: text.assignmentRole,
        cell: ({ row }) => {
          const assignment = row.original as unknown as AssignmentRow
          const role = rolesForLabels.find((item) => item.id === assignment.role_id)
          return role ? (
            <span className="text-sm whitespace-normal">{roleDisplayName(role, locale)}</span>
          ) : (
            <span className="text-muted-foreground text-sm">{text.unavailable}</span>
          )
        },
      },
      {
        accessorKey: 'scope_type',
        header: text.assignmentScope,
        cell: ({ row }) => {
          const assignment = row.original as unknown as AssignmentRow
          return (
            <span className="text-sm whitespace-normal">
              {scopeTypeLabel(assignment.scope_type, text)}
            </span>
          )
        },
      },
      {
        accessorKey: 'effective_status',
        header: text.assignmentStatus,
        cell: ({ row }) => {
          const assignment = row.original as unknown as AssignmentRow
          return (
            <Badge variant="outline">
              {effectiveStatusLabel(assignment.effective_status, text)}
            </Badge>
          )
        },
      },
      {
        accessorKey: 'start_at',
        header: text.assignmentWindow,
        cell: ({ row }) => {
          const assignment = row.original as unknown as AssignmentRow
          const start = formatDate(assignment.start_at, locale)
          const end = formatDate(assignment.end_at, locale)
          return <span className="text-sm break-all whitespace-normal" dir="ltr">{start} – {end || '—'}</span>
        },
      },
      {
        accessorKey: 'id',
        header: '',
        cell: ({ row }) => {
          const assignment = row.original as unknown as AssignmentRow
          if (!canManageAssignments) return null
          const allowed = assignment.allowed_actions ?? []
          const showActivate = allowed.includes('activate')
          const showExpire = allowed.includes('expire')
          const showRevoke = allowed.includes('revoke')
          if (!showActivate && !showExpire && !showRevoke) return null
          return (
            <div className="flex flex-wrap items-center gap-2">
              {showActivate ? (
                <Button
                  size="sm"
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setMutationError(null)
                    setConfirming({ kind: 'activate-assignment', assignment })
                  }}
                >
                  {text.assignmentActivate}
                </Button>
              ) : null}
              {showExpire ? (
                <Button
                  size="sm"
                  variant="outline"
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setMutationError(null)
                    setConfirming({ kind: 'expire-assignment', assignment })
                  }}
                >
                  {text.expire}
                </Button>
              ) : null}
              {showRevoke ? (
                <Button
                  size="sm"
                  variant="ghost"
                  type="button"
                  disabled={busy}
                  onClick={() => {
                    setMutationError(null)
                    setConfirming({ kind: 'revoke-assignment', assignment })
                  }}
                >
                  {text.revoke}
                </Button>
              ) : null}
            </div>
          )
        },
      },
    )
  }

  const emptyCopy =
    resource === 'roles'
      ? text.empty
      : resource === 'capabilities'
        ? text.capabilitiesEmpty
        : text.assignmentsEmpty

  return (
    <div className="space-y-4 min-w-0">
      <h2 className="text-xl font-semibold tracking-tight">{text.roles}</h2>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div
          role="toolbar"
          aria-label={text.resourcesToolbar}
          className="flex flex-wrap items-center gap-2"
        >
          {canReadRoles ? (
            <Button
              type="button"
              size="sm"
              aria-pressed={resource === 'roles'}
              variant={resource === 'roles' ? 'default' : 'outline'}
              onClick={() => switchResource('roles')}
            >
              {text.resourceRoles}
            </Button>
          ) : null}
          {canReadCapabilities ? (
            <Button
              type="button"
              size="sm"
              aria-pressed={resource === 'capabilities'}
              variant={resource === 'capabilities' ? 'default' : 'outline'}
              onClick={() => switchResource('capabilities')}
            >
              {text.resourceCapabilities}
            </Button>
          ) : null}
          {canReadAssignments ? (
            <Button
              type="button"
              size="sm"
              aria-pressed={resource === 'role-assignments'}
              variant={resource === 'role-assignments' ? 'default' : 'outline'}
              onClick={() => switchResource('role-assignments')}
            >
              {text.resourceAssignments}
            </Button>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {resource === 'roles' && canManageRoles && canReconstructRole ? (
            <Button
              size="sm"
              onClick={() => {
                setMutationError(null)
                navigate('/access/roles/new')
              }}
            >
              {text.create}
            </Button>
          ) : null}
          {resource === 'role-assignments' && canManageAssignments ? (
            <Button size="sm" onClick={() => navigate('/access/role-assignments/new')}>
              {text.addAssignment}
            </Button>
          ) : null}
        </div>
      </div>

      {mutationError ? (
        <p className="text-destructive text-sm" role="alert">{mutationError}</p>
      ) : null}

      <DataTable
        columns={columns}
        data={rawItems as ResourceRow[]}
        state={state}
        nextCursor={nextCursor}
        onNext={() => {
          if (nextCursor) setHistory((current) => [...current, nextCursor])
        }}
        onPrev={() => setHistory((current) => current.slice(0, -1))}
        canPrev={history.length > 0}
        locale={locale}
        onRetry={() => void resourceQuery.refetch()}
        correlationId={
          resourceQuery.error instanceof ApiError
            ? resourceQuery.error.correlationId
            : null
        }
        empty={
          <div className="py-12 text-center">
            <p className="text-foreground font-medium">{emptyCopy}</p>
          </div>
        }
      />

      <AlertDialog
        open={confirming !== null}
        onOpenChange={(next) => { if (!next && !busy) setConfirming(null) }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {confirming?.kind === 'archive-role'
                ? text.archiveTitle
                : confirming?.kind === 'activate-assignment'
                  ? text.assignmentActivateTitle
                  : confirming?.kind === 'expire-assignment'
                    ? text.assignmentExpireTitle
                    : confirming?.kind === 'revoke-assignment'
                      ? text.assignmentRevokeTitle
                      : ''}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {confirming?.kind === 'archive-role'
                ? text.archiveBody
                : confirming?.kind === 'activate-assignment'
                  ? text.assignmentActivateBody
                  : confirming?.kind === 'expire-assignment'
                    ? text.assignmentExpireBody
                    : confirming?.kind === 'revoke-assignment'
                      ? text.assignmentRevokeBody
                      : ''}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={busy}>{text.cancel}</AlertDialogCancel>
            <AlertDialogAction
              disabled={busy}
              onClick={() => {
                if (confirming?.kind === 'archive-role') {
                  archiveMutation.mutate(confirming.role)
                } else if (confirming?.kind === 'activate-assignment') {
                  activateMutation.mutate(confirming.assignment)
                } else if (confirming?.kind === 'expire-assignment') {
                  expireMutation.mutate(confirming.assignment)
                } else if (confirming?.kind === 'revoke-assignment') {
                  revokeMutation.mutate(confirming.assignment)
                }
                setConfirming(null)
              }}
            >
              {text.confirm}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
