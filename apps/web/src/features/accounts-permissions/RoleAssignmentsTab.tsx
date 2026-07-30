import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'

import { directionForLocale, type Locale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { listUserAccounts } from '../../api/identity'
import { ApiError } from '../../api'
import {
  createRoleAssignment,
  expireRoleAssignment,
  listRoleAssignments,
  listRoles,
  revokeRoleAssignment,
  updateRoleAssignment,
} from '../../api/r1'
import type { AuthorizationRoleAssignment } from '../../api/r1'
import {
  Button,
  EmptyState,
  Field,
  InlineError,
  Panel,
  Select,
  SkeletonList,
} from '../../ui'
import { canMutateAdminResource } from './canMutateAdminResource'
import {
  AssignmentScopePicker,
  type AssignmentScopeValue,
} from './AssignmentScopePicker'

export type RoleAssignmentsTabProps = {
  locale: Locale
  capabilities: readonly string[]
}

type LoadState = 'loading' | 'ready' | 'error'

const COPY = {
  ar: {
    heading: 'إسنادات الأدوار',
    intro: 'إنشاء وتعديل وإلغاء إسنادات الأدوار ضمن نطاق الإدارة.',
    actor: 'الموظف',
    role: 'الدور',
    endAt: 'تاريخ الانتهاء',
    save: 'حفظ الإسناد',
    revoke: 'إلغاء الإسناد',
    expire: 'إنهاء الإسناد',
    cancel: 'إلغاء',
    status: 'الحالة',
    actions: 'الإجراءات',
    empty: 'لا توجد إسنادات أدوار لعرضها.',
    loading: 'جارٍ تحميل إسنادات الأدوار…',
    error: 'تعذر تحميل إسنادات الأدوار.',
    createError: 'تعذر إنشاء إسناد الدور.',
    updateError: 'تعذر تحديث إسناد الدور.',
    revokeError: 'تعذر إلغاء إسناد الدور.',
    expireError: 'تعذر إنهاء إسناد الدور.',
    edit: 'تعديل',
    retry: 'إعادة المحاولة',
    scopeNotSelected: 'اختر هدف النطاق قبل الحفظ.',
    editEndAtLabel: 'تاريخ الانتهاء',
    editDrawerHeading: 'تعديل الإسناد',
    editScopeHeading: 'نطاق الإسناد',
  },
  en: {
    heading: 'Role assignments',
    intro: 'Create, update, revoke, or expire role assignments inside your administrative scope.',
    actor: 'Account',
    role: 'Role',
    endAt: 'End at',
    save: 'Save assignment',
    revoke: 'Revoke assignment',
    expire: 'Expire assignment',
    cancel: 'Cancel',
    status: 'Status',
    actions: 'Actions',
    empty: 'No role assignments to display.',
    loading: 'Loading role assignments…',
    error: 'Could not load role assignments.',
    createError: 'Could not create the role assignment.',
    updateError: 'Could not update the role assignment.',
    revokeError: 'Could not revoke the role assignment.',
    expireError: 'Could not expire the role assignment.',
    edit: 'Edit',
    retry: 'Try again',
    scopeNotSelected: 'Select a scope target before saving.',
    editEndAtLabel: 'End at',
    editDrawerHeading: 'Edit assignment',
    editScopeHeading: 'Assignment scope',
  },
} as const satisfies Record<Locale, Record<string, string>>

type ScopeDraft = AssignmentScopeValue | null

/**
 * Read listings of role assignments with same-row destructive controls, plus
 * a create form (and an edit drawer) that resolve `scope_type` and `scope_id`
 * through the catalog-driven `<AssignmentScopePicker>`. Users never type a
 * UUID; the picker fails closed on loading / empty / forbidden / 422 / error.
 *
 * The wrong-delimiter discriminator regression (`resource_type: 'role-assignment'`)
 * is prevented by always sending `'role_assignment'` from this single submit
 * path.
 */
export function RoleAssignmentsTab({ locale, capabilities }: RoleAssignmentsTabProps) {
  const token = useToken()
  const labels = COPY[locale]
  const [assignments, setAssignments] = useState<AuthorizationRoleAssignment[]>([])
  const [roles, setRoles] = useState<Array<{ id: string; code: string; name_en?: string; name_ar?: string }>>([])
  const [accounts, setAccounts] = useState<Array<{ id: string; username: string; display_name_en: string | null; display_name_ar: string }>>([])
  const [state, setState] = useState<LoadState>('loading')
  const [error, setError] = useState<string | null>(null)
  const [actor, setActor] = useState('')
  const [roleCode, setRoleCode] = useState('')
  const [endAt, setEndAt] = useState('')
  const [createScope, setCreateScope] = useState<ScopeDraft>(null)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [editingEndAt, setEditingEndAt] = useState('')
  const [editScope, setEditScope] = useState<ScopeDraft>(null)
  const [pendingId, setPendingId] = useState<string | null>(null)
  const [mutationError, setMutationError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    setError(null)
    try {
      const [items, roleRows, accountRows] = await Promise.all([listRoleAssignments(token), listRoles(token), listUserAccounts(token)])
      setAssignments(items); setRoles(roleRows); setAccounts(accountRows.items)
      setState('ready')
    } catch (caught) {
      setState('error')
      setError(caught instanceof ApiError ? caught.message : 'load_failed')
    }
  }, [token])

  useEffect(() => {
    void load()
  }, [load])

  const canAssign = canMutateAdminResource('role-assignments', 'create', capabilities)

  const accountOptions = useMemo(
    () =>
      accounts.map((account) => ({
        value: account.id,
        label:
          locale === 'ar'
            ? (account.display_name_ar ?? account.display_name_en ?? account.username)
            : (account.display_name_en ?? account.display_name_ar ?? account.username),
      })),
    [accounts, locale],
  )

  const roleOptions = useMemo(
    () =>
      roles.map((role) => ({
        value: role.id,
        label:
          locale === 'ar'
            ? (role.name_ar ?? role.name_en ?? role.code)
            : (role.name_en ?? role.name_ar ?? role.code),
      })),
    [roles, locale],
  )

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canAssign) return
    if (!createScope) {
      setMutationError(labels.scopeNotSelected)
      return
    }
    setPendingId('create')
    setMutationError(null)
    try {
      await createRoleAssignment(token, {
        resource_type: 'role_assignment',
        code: 'role-assignment',
        subject_user_id: actor.trim(),
        role_id: roleCode.trim(),
        scope_type: createScope.scope_type,
        scope_id: createScope.scope_id,
        ...(endAt ? { end_at: new Date(endAt).toISOString() } : {}),
      })
      setActor('')
      setRoleCode('')
      setEndAt('')
      setCreateScope(null)
      await load()
    } catch (caught) {
      setMutationError(caught instanceof ApiError ? caught.message : labels.createError)
    } finally {
      setPendingId(null)
    }
  }

  function startEdit(assignment: AuthorizationRoleAssignment) {
    setEditingId(assignment.id)
    setEditingEndAt(assignment.end_at ? toLocalInputValue(assignment.end_at) : '')
    const scopeType = assignment.scope_type
    const scopeId = assignment.scope_id
    setEditScope(scopeType && scopeId ? { scope_type: scopeType, scope_id: scopeId } : null)
    setMutationError(null)
  }

  function cancelEdit() {
    setEditingId(null)
    setEditingEndAt('')
    setEditScope(null)
  }

  async function mutate(assignment: AuthorizationRoleAssignment, action: 'update' | 'revoke' | 'expire') {
    const lockVersion = 'lock_version' in assignment && typeof assignment.lock_version === 'number' ? assignment.lock_version : undefined
    if (lockVersion === undefined) {
      setMutationError(labels.error)
      return
    }
    setPendingId(assignment.id)
    setMutationError(null)
    try {
      if (action === 'update') {
        const patch: Record<string, unknown> = {
          end_at: new Date(editingEndAt).toISOString(),
        }
        if (editScope) {
          patch.scope_type = editScope.scope_type
          patch.scope_id = editScope.scope_id
        }
        await updateRoleAssignment(token, assignment.id, patch, lockVersion)
      } else if (action === 'revoke') {
        await revokeRoleAssignment(token, assignment.id, lockVersion)
      } else {
        await expireRoleAssignment(token, assignment.id, lockVersion)
      }
      await load()
      cancelEdit()
    } catch (caught) {
      setMutationError(caught instanceof ApiError ? caught.message : labels[action === 'update' ? 'updateError' : action === 'revoke' ? 'revokeError' : 'expireError'])
    } finally {
      setPendingId(null)
    }
  }

  if (state === 'loading') {
    return (
      <div dir={directionForLocale(locale)}>
        <SkeletonList label={labels.loading} />
      </div>
    )
  }

  if (state === 'error') {
    return (
      <div dir={directionForLocale(locale)}>
        <InlineError message={error ?? labels.error} retryLabel={labels.retry} onRetry={() => void load()} />
      </div>
    )
  }

  const createSubmitDisabled =
    pendingId === 'create' || !actor || !roleCode || !createScope

  return (
    <div dir={directionForLocale(locale)}>
      <Panel id="role-assignments-panel" title={labels.heading} level={2}>
        <p>{labels.intro}</p>
        {canAssign ? (
          <form className="inline-form" onSubmit={handleSubmit} aria-describedby="role-assignment-create-help">
            <p id="role-assignment-create-help" className="visually-hidden">
              {labels.intro}
            </p>
            <Field id="assignment-actor" label={labels.actor}>
              <Select
                id="assignment-actor"
                value={actor}
                options={accountOptions}
                onChange={setActor}
              />
            </Field>
            <Field id="assignment-role" label={labels.role}>
              <Select
                id="assignment-role"
                value={roleCode}
                options={roleOptions}
                onChange={setRoleCode}
              />
            </Field>
            <AssignmentScopePicker
              value={createScope}
              onChange={setCreateScope}
              locale={locale}
              token={token}
              canAssign={canAssign}
              idPrefix="assignment-create-scope"
            />
            <Field id="assignment-end-at" label={labels.endAt}>
              <input
                id="assignment-end-at"
                value={endAt}
                onChange={(event) => setEndAt(event.target.value)}
                dir="ltr"
              />
            </Field>
            <Button type="submit" disabled={createSubmitDisabled}>{labels.save}</Button>
          </form>
        ) : null}
        {mutationError ? <p role="alert" className="error-summary">{mutationError}</p> : null}
        {assignments.length === 0 ? (
          <EmptyState icon={<span aria-hidden="true">🧩</span>} title={labels.empty} />
        ) : (
          <ul className="data-list">
            {assignments.map((assignment) => {
              const actions = assignment.allowed_actions
              const canRevoke = canMutateAdminResource('role-assignments', 'revoke', capabilities, actions)
              const canExpire = canMutateAdminResource('role-assignments', 'expire', capabilities, actions)
              const lockVersion = assignment.lock_version
              const canEdit =
                canMutateAdminResource('role-assignments', 'edit', capabilities, actions) && typeof lockVersion === 'number'
              const editing = editingId === assignment.id
              return (
                <li key={assignment.id} className="assignment-row">
                  <header className="assignment-row-header">
                    <span>
                      {locale === 'ar'
                        ? (roles.find((role) => role.id === assignment.role_id)?.name_ar
                          ?? roles.find((role) => role.id === assignment.role_id)?.name_en
                          ?? labels.role)
                        : (roles.find((role) => role.id === assignment.role_id)?.name_en
                          ?? roles.find((role) => role.id === assignment.role_id)?.name_ar
                          ?? labels.role)}
                    </span>
                    <span>
                      {locale === 'ar'
                        ? (accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_ar
                          ?? accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_en
                          ?? labels.actor)
                        : (accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_en
                          ?? accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_ar
                          ?? labels.actor)}
                    </span>
                    <span>{assignment.effective_status}</span>
                  </header>
                  {editing ? (
                    <div
                      role="region"
                      aria-label={labels.editDrawerHeading}
                      className="assignment-edit-drawer"
                    >
                      <fieldset className="assignment-edit-end-at">
                        <legend>{labels.editEndAtLabel}</legend>
                        <Field id={`assignment-end-${assignment.id}`} label={labels.editEndAtLabel}>
                          <input
                            id={`assignment-end-${assignment.id}`}
                            type="datetime-local"
                            value={editingEndAt}
                            onChange={(event) => setEditingEndAt(event.target.value)}
                            required
                          />
                        </Field>
                      </fieldset>
                      <AssignmentScopePicker
                        value={editScope}
                        onChange={setEditScope}
                        locale={locale}
                        token={token}
                        canAssign={canAssign}
                        initialAncestry={
                          assignment.scope_type && assignment.scope_id
                            ? [{ scope_type: assignment.scope_type, scope_id: assignment.scope_id }]
                            : []
                        }
                        idPrefix={`assignment-edit-scope-${assignment.id}`}
                      />
                      <div className="assignment-edit-actions">
                        <Button
                          variant="primary"
                          disabled={pendingId === assignment.id || !editScope || !editingEndAt}
                          onClick={() => void mutate(assignment, 'update')}
                        >
                          {labels.save}
                        </Button>
                        <Button
                          variant="quiet"
                          disabled={pendingId === assignment.id}
                          onClick={cancelEdit}
                        >
                          {labels.cancel}
                        </Button>
                      </div>
                    </div>
                  ) : null}
                  {!editing ? (
                    <div className="assignment-row-actions">
                      {canEdit ? (
                        <Button
                          variant="quiet"
                          disabled={pendingId === assignment.id}
                          onClick={() => startEdit(assignment)}
                        >
                          {labels.edit}
                        </Button>
                      ) : null}
                      {canRevoke ? (
                        <Button
                          variant="quiet"
                          disabled={pendingId === assignment.id}
                          onClick={() => void mutate(assignment, 'revoke')}
                        >
                          {labels.revoke}
                        </Button>
                      ) : null}
                      {canExpire ? (
                        <Button
                          variant="quiet"
                          disabled={pendingId === assignment.id}
                          onClick={() => void mutate(assignment, 'expire')}
                        >
                          {labels.expire}
                        </Button>
                      ) : null}
                    </div>
                  ) : null}
                </li>
              )
            })}
          </ul>
        )}
      </Panel>
    </div>
  )
}

function toLocalInputValue(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n: number) => n.toString().padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
