import { useState, type FormEvent } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import * as generated from '../../../api/generated/cluster'
import { ApiError, requestInit, unwrap } from '../../../api/http'
import { useLocale, useSessionToken } from '../../../app/session-context'
import { statusLabel } from '../../../i18n'
import { usePrincipal } from '../../../app/principal-context'
import { useCapabilitiesList, useRolesList } from '../../../api/hooks'
import {
  Button,
  EmptyState,
  Field,
  InlineError,
  Panel,
  SkeletonList,
  StatusBadge,
} from '../../../ui'
import { accountsCopy, roleCopy } from '../accounts-copy'

/* ------------------------------------------------------------------ */
/* Roles tab                                                           */
/* ------------------------------------------------------------------ */

type CapabilityCatalogItem = generated.AuthorizationCapability & { id: string }

interface RoleDraft {
  code: string
  name: string
  capabilityCodes: string[]
}

const EMPTY_DRAFT: RoleDraft = { code: '', name: '', capabilityCodes: [] }

function roleDisplayName(
  role: generated.AuthorizationRole,
  locale: 'ar' | 'en',
): string {
  return locale === 'ar'
    ? (role.name_ar ?? role.name_en ?? role.code)
    : (role.name_en ?? role.name_ar ?? role.code)
}

export function RolesTab() {
  const locale = useLocale()
  const csrfToken = useSessionToken()
  const principal = usePrincipal()
  const text = roleCopy[locale]
  const queryClient = useQueryClient()
  const rolesQuery = useRolesList()
  const capabilitiesQuery = useCapabilitiesList()
  const [draft, setDraft] = useState<RoleDraft>(EMPTY_DRAFT)
  const [editing, setEditing] = useState<generated.AuthorizationRole | null>(
    null,
  )
  const [error, setError] = useState<string | null>(null)

  const canReadCapabilities = (principal.capabilities ?? []).includes(
    'authorization.capability.read',
  )

  const roles =
    (rolesQuery.data as { items: generated.AuthorizationRole[] } | undefined)
      ?.items ?? []
  const catalog =
    (capabilitiesQuery.data as { items: CapabilityCatalogItem[] } | undefined)
      ?.items ?? []
  const capabilitiesError =
    capabilitiesQuery.error && canReadCapabilities
      ? capabilitiesQuery.error
      : undefined
  const loadError = rolesQuery.error ?? capabilitiesError
  const loadState: 'loading' | 'ready' | 'error' =
    rolesQuery.isLoading || (canReadCapabilities && capabilitiesQuery.isLoading)
      ? 'loading'
      : loadError
        ? 'error'
        : 'ready'
  const retry = () => {
    void rolesQuery.refetch()
    void capabilitiesQuery.refetch()
  }
  const invalidateRoles = () =>
    void queryClient.invalidateQueries({ queryKey: ['roles'] })

  const submitMutation = useMutation({
    mutationFn: async ({
      nextDraft,
      nextEditing,
    }: {
      nextDraft: RoleDraft
      nextEditing: generated.AuthorizationRole | null
    }) => {
      if (nextEditing) {
        return unwrap<generated.AuthorizationRole>(
          await generated.updateAuthorizationAdminResource(
            'roles',
            nextEditing.id,
            {
              name: nextDraft.name.trim(),
              capability_codes: nextDraft.capabilityCodes,
            },
            requestInit(csrfToken, {
              mutation: true,
              lockVersion: nextEditing.lock_version,
            }),
          ),
        )
      }
      return unwrap<generated.AuthorizationRole>(
        await generated.createAuthorizationAdminResource(
          'roles',
          {
            resource_type: 'role',
            code: nextDraft.code.trim(),
            name: nextDraft.name.trim(),
            capability_codes: nextDraft.capabilityCodes,
          },
          requestInit(csrfToken, {
            command: true,
            idempotency: 'authorization-role',
          }),
        ),
      )
    },
    onSuccess: () => {
      invalidateRoles()
      setDraft(EMPTY_DRAFT)
      setEditing(null)
    },
    onError: (caught) =>
      setError(caught instanceof ApiError ? caught.message : text.roleError),
  })

  const archiveMutation = useMutation({
    mutationFn: async (role: generated.AuthorizationRole) =>
      unwrap<generated.AuthorizationRole>(
        await generated.updateAuthorizationAdminResource(
          'roles',
          role.id,
          { status: 'archived' },
          requestInit(csrfToken, {
            mutation: true,
            lockVersion: role.lock_version,
          }),
        ),
      ),
    onSuccess: () => invalidateRoles(),
    onError: (caught) =>
      setError(caught instanceof ApiError ? caught.message : text.roleError),
  })

  const cloneMutation = useMutation({
    mutationFn: async (role: generated.AuthorizationRole) =>
      unwrap<generated.AuthorizationRole>(
        await generated.transitionAuthorizationAdminResource(
          'roles',
          role.id,
          'clone',
          undefined,
          requestInit(csrfToken, {
            command: true,
            idempotency: 'authorization-role-clone',
            lockVersion: role.lock_version,
          }),
        ),
      ),
    onSuccess: () => invalidateRoles(),
    onError: (caught) =>
      setError(caught instanceof ApiError ? caught.message : text.roleError),
  })

  const revokeMutation = useMutation({
    mutationFn: async ({
      role,
      capability,
    }: {
      role: generated.AuthorizationRole
      capability: CapabilityCatalogItem
    }) =>
      unwrap<generated.AuthorizationRole>(
        await generated.transitionAuthorizationAdminResource(
          'role-capabilities',
          `${role.id}:${capability.id}`,
          'revoke',
          undefined,
          requestInit(csrfToken, {
            command: true,
            idempotency: 'authorization-role-capability-revoke',
            lockVersion: role.lock_version,
          }),
        ),
      ),
    onSuccess: () => invalidateRoles(),
    onError: (caught) =>
      setError(caught instanceof ApiError ? caught.message : text.roleError),
  })

  const pending =
    submitMutation.isPending ||
    archiveMutation.isPending ||
    cloneMutation.isPending ||
    revokeMutation.isPending

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!draft.code.trim() || !draft.name.trim()) return
    setError(null)
    submitMutation.mutate({ nextDraft: draft, nextEditing: editing })
  }

  function archive(role: generated.AuthorizationRole) {
    setError(null)
    archiveMutation.mutate(role)
  }

  function clone(role: generated.AuthorizationRole) {
    setError(null)
    cloneMutation.mutate(role)
  }

  function revokeCapability(
    role: generated.AuthorizationRole,
    capability: CapabilityCatalogItem,
  ) {
    setError(null)
    revokeMutation.mutate({ role, capability })
  }

  function toggleCapability(code: string) {
    setDraft((current) => ({
      ...current,
      capabilityCodes: current.capabilityCodes.includes(code)
        ? current.capabilityCodes.filter((item) => item !== code)
        : [...current.capabilityCodes, code],
    }))
  }

  function beginEdit(role: generated.AuthorizationRole) {
    setEditing(role)
    setDraft({
      code: role.code,
      name: roleDisplayName(role, locale),
      capabilityCodes: role.capability_codes ?? [],
    })
    setError(null)
  }

  if (loadState === 'loading') return <SkeletonList rows={4} />
  if (loadState === 'error')
    return (
      <InlineError
        message={
          error ??
          (loadError instanceof ApiError ? loadError.message : text.rolesError)
        }
        retryLabel={accountsCopy[locale].retry}
        onRetry={retry}
      />
    )

  return (
    <Panel id="roles-tab-panel" title={text.roles} level={2}>
      {canReadCapabilities && (
        <form
          className="resource-form"
          onSubmit={(event) => void submit(event)}
          noValidate
        >
          <Field id="role-code" label={text.code} required>
            <input
              id="role-code"
              value={draft.code}
              required
              aria-required="true"
              disabled={Boolean(editing) || pending}
              onChange={(event) =>
                setDraft((current) => ({
                  ...current,
                  code: event.target.value,
                }))
              }
            />
          </Field>
          <Field id="role-name" label={text.name} required>
            <input
              id="role-name"
              value={draft.name}
              required
              aria-required="true"
              disabled={pending}
              onChange={(event) =>
                setDraft((current) => ({
                  ...current,
                  name: event.target.value,
                }))
              }
            />
          </Field>
          {catalog.length > 0 ? (
            <fieldset>
              <legend className="field__label">{text.capabilities}</legend>
              <div className="badge-row">
                {catalog.map((capability) => (
                  <label key={capability.id} className="capability-toggle">
                    <input
                      type="checkbox"
                      checked={draft.capabilityCodes.includes(capability.code)}
                      disabled={pending}
                      onChange={() => toggleCapability(capability.code)}
                    />
                    <span dir="ltr">{capability.code}</span>
                  </label>
                ))}
              </div>
            </fieldset>
          ) : (
            <p className="field__help">{text.noCatalog}</p>
          )}
          <div className="form-actions">
            <Button type="submit" disabled={pending}>
              {pending
                ? accountsCopy[locale].loading
                : editing
                  ? text.save
                  : text.create}
            </Button>
            {editing && (
              <Button
                variant="secondary"
                type="button"
                disabled={pending}
                onClick={() => {
                  setEditing(null)
                  setDraft(EMPTY_DRAFT)
                  setError(null)
                }}
              >
                {text.cancel}
              </Button>
            )}
          </div>
        </form>
      )}
      {error && (
        <p className="error-summary" role="alert">
          {error}
        </p>
      )}
      {roles.length === 0 ? (
        <EmptyState title={text.empty} />
      ) : (
        <ul className="screen-list">
          {roles.map((role) => {
            const capabilityChips =
              catalog.length > 0 && !role.is_system_role
                ? (role.capability_codes ?? [])
                    .map((code) => catalog.find((item) => item.code === code))
                    .filter(
                      (item): item is CapabilityCatalogItem =>
                        item !== undefined,
                    )
                : []
            return (
              <li key={role.id} className="screen-list__row">
                <span className="screen-list__row-title">
                  {roleDisplayName(role, locale)}
                </span>
                <span className="screen-list__row-meta" dir="ltr">
                  {role.code}
                </span>
                <span className="screen-list__row-meta">
                  <StatusBadge
                    variant={role.is_system_role ? 'info' : 'neutral'}
                  >
                    {role.is_system_role ? text.systemRole : text.customRole}
                  </StatusBadge>
                  <StatusBadge
                    variant={
                      role.status === 'active'
                        ? 'success'
                        : role.status === 'archived'
                          ? 'neutral'
                          : 'warning'
                    }
                  >
                    {statusLabel(role.status, locale)}
                  </StatusBadge>
                  <span>
                    {(role.capability_codes ?? []).length}{' '}
                    {text.countCapabilities}
                  </span>
                </span>
                {capabilityChips.length > 0 && (
                  <span className="screen-list__row-meta">
                    <span className="badge-row">
                      {capabilityChips.map((capability) => (
                        <span
                          key={capability.id}
                          className="status-badge status-badge--info"
                        >
                          <span dir="ltr">{capability.code}</span>
                          <button
                            type="button"
                            className="button button--quiet capability-revoke"
                            aria-label={`${text.revoke} ${capability.code}`}
                            disabled={pending}
                            onClick={() =>
                              void revokeCapability(role, capability)
                            }
                          >
                            ✕
                          </button>
                        </span>
                      ))}
                    </span>
                  </span>
                )}
                <span className="screen-list__row-actions">
                  {!role.is_system_role && (
                    <Button
                      variant="quiet"
                      type="button"
                      disabled={pending}
                      onClick={() => beginEdit(role)}
                    >
                      {text.edit}
                    </Button>
                  )}
                  {!role.is_system_role && (
                    <Button
                      variant="quiet"
                      type="button"
                      disabled={pending}
                      onClick={() => void archive(role)}
                    >
                      {text.archive}
                    </Button>
                  )}
                  {role.is_system_role && (
                    <Button
                      variant="secondary"
                      type="button"
                      disabled={pending}
                      onClick={() => void clone(role)}
                    >
                      {text.clone}
                    </Button>
                  )}
                </span>
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}
