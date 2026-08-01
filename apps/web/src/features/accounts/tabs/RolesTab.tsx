import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { ShieldAlert } from 'lucide-react'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { usePrincipal } from '../../../app/principal-context'
import { ApiError, stateFromError, type ResourceState } from '../../../api/http'
import { statusLabel, formatDate, type Locale } from '../../../i18n'
import * as access from '../../../api/access'
import * as generated from '../../../api/generated/cluster'
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
import { roleCopy } from '../accounts-copy'
import { RoleSheet } from './RoleSheet'
import { AssignmentSheet } from './AssignmentSheet'

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
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const capabilities = principal.capabilities ?? []
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

  const [resource, setResource] = useState<ResourceKey>(
    canReadRoles ? 'roles' : canReadCapabilities ? 'capabilities' : 'role-assignments',
  )
  const [history, setHistory] = useState<string[]>([])
  const [roleSheet, setRoleSheet] = useState<{
    open: boolean
    role: generated.AuthorizationRole | null
  }>({ open: false, role: null })
  const [assignmentSheetOpen, setAssignmentSheetOpen] = useState(false)
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

  /* Supporting labels for assignments: fetched only while assignments are
   * active or its creation sheet is open — never preloaded. */
  const accountsQuery = useQuery({
    queryKey: ['access-accounts-labels'] as const,
    queryFn: () => access.listAccounts(),
    enabled: resource === 'role-assignments' || assignmentSheetOpen,
  })
  const rolesLabelsQuery = useQuery({
    queryKey: canReadAssignments
      ? (['access-roles-labels-enriched'] as const)
      : (['access-roles-labels'] as const),
    queryFn: () =>
      canReadAssignments
        ? access.listRolesWithCapabilities()
        : access.listAdminResources('roles'),
    enabled: resource === 'role-assignments' || assignmentSheetOpen,
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
    onSuccess: () => invalidateActive(),
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
    onSuccess: () => invalidateActive(),
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
          return <span className="font-medium">{roleDisplayName(role, locale)}</span>
        },
      },
      {
        accessorKey: 'code',
        header: text.code,
        cell: ({ row }) => {
          const role = row.original as unknown as RoleRow
          return <span className="font-mono text-sm" dir="ltr">{role.code}</span>
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
            <span className="text-sm">
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
            <div className="flex items-center gap-2">
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
                        setRoleSheet({ open: true, role })
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
    columns.push(
      {
        accessorKey: 'code',
        header: text.capabilityCode,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          return <span className="font-mono text-sm" dir="ltr">{capability.code}</span>
        },
      },
      {
        accessorKey: 'module_code',
        header: text.capabilityModule,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          return <span className="font-mono text-sm" dir="ltr">{capability.module_code}</span>
        },
      },
      {
        accessorKey: 'action',
        header: text.capabilityAction,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          return <span className="text-sm">{capability.action}</span>
        },
      },
      {
        accessorKey: 'sensitivity',
        header: text.capabilitySensitivity,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          const sensitivity = capability.sensitivity
          return isSensitive(sensitivity) ? (
            <span className="inline-flex items-center gap-1.5">
              <ShieldAlert aria-hidden="true" className="size-4" />
              <span>{sensitivityLabel(sensitivity, text)}</span>
            </span>
          ) : (
            <span className="text-sm">{sensitivityLabel(sensitivity, text)}</span>
          )
        },
      },
      {
        accessorKey: 'group_label',
        header: text.capabilityGroup,
        cell: ({ row }) => {
          const capability = row.original as unknown as CapabilityCatalogItem
          return <span className="text-sm">{capability.group_label}</span>
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
            <span className="font-medium">{accountLabel(account, locale)}</span>
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
            <span className="text-sm">{roleDisplayName(role, locale)}</span>
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
            <span className="text-sm">
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
          return <span className="text-sm" dir="ltr">{start} – {end || '—'}</span>
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
            <div className="flex items-center gap-2">
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
    <div className="space-y-4">
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
        <div className="flex items-center gap-2">
          {resource === 'roles' && canManageRoles && canReconstructRole ? (
            <Button
              size="sm"
              onClick={() => {
                setMutationError(null)
                setRoleSheet({ open: true, role: null })
              }}
            >
              {text.create}
            </Button>
          ) : null}
          {resource === 'role-assignments' && canManageAssignments ? (
            <Button size="sm" onClick={() => setAssignmentSheetOpen(true)}>
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
        empty={
          <div className="py-12 text-center">
            <p className="text-foreground font-medium">{emptyCopy}</p>
          </div>
        }
      />

      <RoleSheet
        open={roleSheet.open}
        role={roleSheet.role}
        onClose={() => setRoleSheet({ open: false, role: null })}
        onSaved={() => setRoleSheet({ open: false, role: null })}
      />

      <AssignmentSheet
        open={assignmentSheetOpen}
        accounts={accounts}
        roles={rolesForLabels}
        effectiveScope={
          principal.effectiveScope
            ? {
                scopeType: principal.effectiveScope.scopeType,
                scopeId: principal.effectiveScope.scopeId,
                label: principal.effectiveScope.label,
              }
            : null
        }
        onClose={() => setAssignmentSheetOpen(false)}
        onSaved={() => setAssignmentSheetOpen(false)}
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
