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
  Drawer,
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

type DestructiveAction = 'revoke' | 'expire'

export type RoleAssignmentsTabProps = {
  locale: Locale
  capabilities: readonly string[]
}

type LoadState = 'loading' | 'ready' | 'error'

type PendingDestructive = {
  action: DestructiveAction
  assignment: AuthorizationRoleAssignment
}

const COPY = {
  ar: {
    heading: 'إسنادات الأدوار',
    intro: 'إنشاء وتعديل وإلغاء إسنادات الأدوار ضمن نطاق الإدارة.',
    actor: 'الموظف',
    role: 'الدور',
    scope: 'النطاق',
    scopeFallback: 'هدف النطاق',
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
    changeScope: 'تغيير النطاق',
    keepScope: 'الاحتفاظ بالنطاق الحالي',
    currentScopeLabel: 'النطاق المحفوظ الحالي',
    currentScopeOfCluster: 'نطاق التجمع المحفوظ الحالي',
    currentScopeOfFacility: 'نطاق المنشأة المحفوظ الحالي',
    currentScopeOfUnit: 'نطاق الوحدة المحفوظ الحالي',
    currentScopeOfRecordSet: 'نطاق مجموعة السجلات المحفوظ الحالي',
    scopeLoading: 'جارٍ تحميل تسمية النطاق…',
    scopeIdAria: 'المعرّف التقني للنطاق',
    revokeDialogTitle: 'تأكيد إلغاء الإسناد',
    revokeDialogBody: 'سيتم إلغاء هذا الإسناد نهائيًا. هل تريد المتابعة؟',
    expireDialogTitle: 'تأكيد إنهاء الإسناد',
    expireDialogBody: 'سيتم إنهاء هذا الإسناد. هل تريد المتابعة؟',
    dialogSubject: 'سيُطبَّق الإجراء على:',
    confirm: 'تأكيد',
    keep: 'إبقاء',
  },
  en: {
    heading: 'Role assignments',
    intro: 'Create, update, revoke, or expire role assignments inside your administrative scope.',
    actor: 'Account',
    role: 'Role',
    scope: 'Scope',
    scopeFallback: 'Scope target',
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
    changeScope: 'Change scope',
    keepScope: 'Keep current scope',
    currentScopeLabel: 'Current saved scope',
    currentScopeOfCluster: 'Current saved cluster scope',
    currentScopeOfFacility: 'Current saved facility scope',
    currentScopeOfUnit: 'Current saved unit scope',
    currentScopeOfRecordSet: 'Current saved record-set scope',
    scopeLoading: 'Loading scope label…',
    scopeIdAria: 'Scope technical identifier',
    revokeDialogTitle: 'Confirm revoke assignment',
    revokeDialogBody: 'This will revoke the assignment permanently. Do you want to continue?',
    expireDialogTitle: 'Confirm expire assignment',
    expireDialogBody: 'This will expire the assignment. Do you want to continue?',
    dialogSubject: 'This will apply to:',
    confirm: 'Confirm',
    keep: 'Keep',
  },
} as const satisfies Record<Locale, Record<string, string>>

type ScopeDraft = AssignmentScopeValue | null

type ScopeType = 'cluster' | 'facility' | 'unit' | 'record_set'

type LocalizedScopeLabels = {
  currentScopeOfCluster: string
  currentScopeOfFacility: string
  currentScopeOfUnit: string
  currentScopeOfRecordSet: string
}

/**
 * Map a saved `scope_type` to its localized "current saved scope" copy without
 * indexing `labels` with a dynamic string (which would trip TS7053 and let
 * `scopeFallback` slip in as a real scope name). The exhaustive `switch` keeps
 * the helper in sync with `LocalizedScopeLabels` and the picker contract.
 */
function localizedCurrentScopeLabel(
  labels: LocalizedScopeLabels & { scopeFallback: string },
  scopeType: ScopeType | undefined,
): string {
  switch (scopeType) {
    case 'cluster':
      return labels.currentScopeOfCluster
    case 'facility':
      return labels.currentScopeOfFacility
    case 'unit':
      return labels.currentScopeOfUnit
    case 'record_set':
      return labels.currentScopeOfRecordSet
    default:
      return labels.scopeFallback
  }
}

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
  const [pendingDestructive, setPendingDestructive] = useState<PendingDestructive | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    setError(null)
    try {
      const [items, roleRows, accountRows] = await Promise.all([
        listRoleAssignments(token),
        listRoles(token),
        listUserAccounts(token),
      ])
      setAssignments(items)
      setRoles(roleRows)
      setAccounts(accountRows.items)
      setState('ready')
    } catch (caught) {
      setState('error')
      setError(caught instanceof ApiError ? caught.message : labels.error)
    }
  }, [token, labels.error])

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

  function startEdit(assignment: AuthorizationRoleAssignment, options: { changeScope?: boolean } = {}) {
    setEditingId(assignment.id)
    setEditingEndAt(assignment.end_at ? toLocalInputValue(assignment.end_at) : '')
    const scopeType = assignment.scope_type
    const scopeId = assignment.scope_id
    if (options.changeScope) {
      setEditScope(null)
    } else {
      setEditScope(scopeType && scopeId ? { scope_type: scopeType, scope_id: scopeId } : null)
    }
    setMutationError(null)
  }

  function cancelEdit() {
    setEditingId(null)
    setEditingEndAt('')
    setEditScope(null)
  }

  function openDestructive(action: DestructiveAction, assignment: AuthorizationRoleAssignment) {
    setPendingDestructive({ action, assignment })
    setMutationError(null)
  }

  function closeDestructive() {
    setPendingDestructive(null)
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
      setPendingDestructive(null)
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
              const role = roles.find((role) => role.id === assignment.role_id)
              const account = accounts.find((account) => account.id === assignment.subject_user_id)
              const rowScopeLabel = localizedCurrentScopeLabel(labels, assignment.scope_type)
              const roleLabel =
                locale === 'ar'
                  ? (role?.name_ar ?? role?.name_en ?? labels.role)
                  : (role?.name_en ?? role?.name_ar ?? labels.role)
              const actorLabel =
                locale === 'ar'
                  ? (account?.display_name_ar ?? account?.display_name_en ?? labels.actor)
                  : (account?.display_name_en ?? account?.display_name_ar ?? labels.actor)
              return (
                <li key={assignment.id} className="assignment-row">
                  <header className="assignment-row-header">
                    <span>{roleLabel}</span>
                    <span>{actorLabel}</span>
                    <span data-testid="assignment-row-scope">
                      <span className="visually-hidden">{labels.scope}: </span>
                      {rowScopeLabel}
                    </span>
                    <span>{assignment.effective_status}</span>
                  </header>
                  {editing ? (
                    <div
                      role="region"
                      aria-label={labels.editDrawerHeading}
                      className="assignment-edit-drawer"
                    >
                      <p className="assignment-edit-summary">
                        <strong>{labels.role}:</strong> {roleLabel} ·{' '}
                        <strong>{labels.actor}:</strong> {actorLabel}
                      </p>
                      <p
                        className="assignment-edit-current-scope"
                        data-testid="assignment-current-scope"
                      >
                        <strong>{labels.currentScopeLabel}:</strong>{' '}
                        {localizedCurrentScopeLabel(labels, assignment.scope_type)}
                      </p>
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
                          onClick={() => setEditScope(null)}
                        >
                          {labels.changeScope}
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
                          onClick={() => openDestructive('revoke', assignment)}
                        >
                          {labels.revoke}
                        </Button>
                      ) : null}
                      {canExpire ? (
                        <Button
                          variant="quiet"
                          disabled={pendingId === assignment.id}
                          onClick={() => openDestructive('expire', assignment)}
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
      {pendingDestructive ? (
        <DestructiveDialog
          locale={locale}
          pending={pendingDestructive}
          labels={labels}
          accountLabel={
            accounts.find((a) => a.id === pendingDestructive.assignment.subject_user_id)
              ? (locale === 'ar'
                  ? (accounts.find((a) => a.id === pendingDestructive.assignment.subject_user_id)?.display_name_ar
                    ?? accounts.find((a) => a.id === pendingDestructive.assignment.subject_user_id)?.display_name_en
                    ?? labels.actor)
                  : (accounts.find((a) => a.id === pendingDestructive.assignment.subject_user_id)?.display_name_en
                    ?? accounts.find((a) => a.id === pendingDestructive.assignment.subject_user_id)?.display_name_ar
                    ?? labels.actor))
              : labels.actor
          }
          roleLabel={
            roles.find((r) => r.id === pendingDestructive.assignment.role_id)
              ? (locale === 'ar'
                  ? (roles.find((r) => r.id === pendingDestructive.assignment.role_id)?.name_ar
                    ?? roles.find((r) => r.id === pendingDestructive.assignment.role_id)?.name_en
                    ?? labels.role)
                  : (roles.find((r) => r.id === pendingDestructive.assignment.role_id)?.name_en
                    ?? roles.find((r) => r.id === pendingDestructive.assignment.role_id)?.name_ar
                    ?? labels.role))
              : labels.role
          }
          scopeLabel={localizedCurrentScopeLabel(labels, pendingDestructive.assignment.scope_type)}
          busy={pendingId === pendingDestructive.assignment.id}
          onCancel={closeDestructive}
          onConfirm={() => void mutate(pendingDestructive.assignment, pendingDestructive.action)}
        />
      ) : null}
    </div>
  )
}

function DestructiveDialog({
  locale,
  pending,
  labels,
  accountLabel,
  roleLabel,
  scopeLabel,
  busy,
  onCancel,
  onConfirm,
}: {
  locale: Locale
  pending: PendingDestructive
  labels: typeof COPY['ar'] | typeof COPY['en']
  accountLabel: string
  roleLabel: string
  scopeLabel: string
  busy: boolean
  onCancel: () => void
  onConfirm: () => void
}) {
  const isRevoke = pending.action === 'revoke'
  return (
    <Drawer
      open
      onClose={onCancel}
      title={isRevoke ? labels.revokeDialogTitle : labels.expireDialogTitle}
      ariaLabelClose={labels.cancel}
    >
      <p>{isRevoke ? labels.revokeDialogBody : labels.expireDialogBody}</p>
      <p>
        <strong>{labels.dialogSubject}</strong>
      </p>
      <ul>
        <li><strong>{labels.role}:</strong> {roleLabel}</li>
        <li><strong>{labels.actor}:</strong> {accountLabel}</li>
        <li><strong>{labels.scope}:</strong> {scopeLabel}</li>
      </ul>
      <div className="dialog-actions">
        <Button variant="quiet" disabled={busy} onClick={onCancel}>
          {labels.keep}
        </Button>
        <Button variant="primary" disabled={busy} onClick={onConfirm}>
          {busy ? '…' : labels.confirm}
        </Button>
      </div>
      <span dir={directionForLocale(locale)} hidden />
    </Drawer>
  )
}

function toLocalInputValue(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n: number) => n.toString().padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
