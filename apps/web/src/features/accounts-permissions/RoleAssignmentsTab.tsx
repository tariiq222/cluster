import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'

import { directionForLocale, type Locale } from '../../app/copy'
import { useToken } from '../../app/session-context'
import { listUserAccounts } from '../../api/identity'
import { ApiError } from '../../api'
import { createRoleAssignment, expireRoleAssignment, listRoleAssignments, listRoles, revokeRoleAssignment, updateRoleAssignment } from '../../api/r1'
import type { AuthorizationRoleAssignment } from '../../api/generated/cluster'
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
    scopeLevel: 'مستوى النطاق',
    scopeTarget: 'هدف النطاق',
    endAt: 'تاريخ الانتهاء',
    save: 'حفظ الإسناد',
    revoke: 'إلغاء الإسناد',
    expire: 'إنهاء الإسناد',
    status: 'الحالة',
    actions: 'الإجراءات',
    empty: 'لا توجد إسنادات أدوار لعرضها.',
    loading: 'جارٍ تحميل إسنادات الأدوار…',
    error: 'تعذر تحميل إسنادات الأدوار.',
    retry: 'إعادة المحاولة',
    scopeCluster: 'التجمع', scopeFacility: 'المنشأة', scopeUnit: 'الوحدة', scopeRecordSet: 'مجموعة السجلات',
  },
  en: {
    heading: 'Role assignments',
    intro: 'Create, update, revoke, or expire role assignments inside your administrative scope.',
    actor: 'Account',
    role: 'Role',
    scopeLevel: 'Scope level',
    scopeTarget: 'Scope target',
    endAt: 'End at',
    save: 'Save assignment',
    revoke: 'Revoke assignment',
    expire: 'Expire assignment',
    status: 'Status',
    actions: 'Actions',
    empty: 'No role assignments to display.',
    loading: 'Loading role assignments…',
    error: 'Could not load role assignments.',
    retry: 'Try again',
    scopeCluster: 'Cluster', scopeFacility: 'Facility', scopeUnit: 'Unit', scopeRecordSet: 'Record set',
  },
} as const satisfies Record<Locale, Record<string, string>>

/**
 * Read listings of role assignments with same-row destructive controls. The
 * destructive actions share a single drawer-equivalent confirmation pattern
 * (no reason is collected). Capability checks come from the matrix helper.
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
  const [editingId, setEditingId] = useState<string | null>(null)
  const [editingEndAt, setEditingEndAt] = useState('')
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

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!canAssign) return
    setPendingId('create'); setMutationError(null)
    try {
      await createRoleAssignment(token, { resource_type: 'role_assignment', code: 'role-assignment', subject_user_id: actor.trim(), role_id: roleCode.trim(), scope_type: 'cluster', ...(endAt ? { end_at: new Date(endAt).toISOString() } : {}) })
      await load()
    } catch (caught) { setMutationError(caught instanceof ApiError ? caught.message : labels.error) }
    finally { setPendingId(null) }
  }

  async function mutate(assignment: AuthorizationRoleAssignment, action: 'update' | 'revoke' | 'expire') {
    const lockVersion = 'lock_version' in assignment && typeof assignment.lock_version === 'number' ? assignment.lock_version : undefined
    if (lockVersion === undefined) { setMutationError(labels.error); return }
    setPendingId(assignment.id); setMutationError(null)
    try {
      if (action === 'update') await updateRoleAssignment(token, assignment.id, { end_at: new Date(editingEndAt).toISOString() }, lockVersion)
      else if (action === 'revoke') await revokeRoleAssignment(token, assignment.id, lockVersion)
      else await expireRoleAssignment(token, assignment.id, lockVersion)
      await load()
    } catch (caught) { setMutationError(caught instanceof ApiError ? caught.message : labels.error) }
    finally { setPendingId(null) }
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

  return (
    <div dir={directionForLocale(locale)}>
      <Panel id="role-assignments-panel" title={labels.heading} level={2}>
        <p>{labels.intro}</p>
        {canAssign ? (
          <form className="inline-form" onSubmit={handleSubmit}>
            <Field id="assignment-actor" label={labels.actor}>
              <Select id="assignment-actor" value={actor} options={accounts.map((account) => ({ value: account.id, label: locale === 'ar' ? (account.display_name_ar ?? account.display_name_en ?? account.username) : (account.display_name_en ?? account.display_name_ar ?? account.username) }))} onChange={setActor} />
            </Field>
            <Field id="assignment-role" label={labels.role}>
              <Select id="assignment-role" value={roleCode} options={roles.map((role) => ({ value: role.id, label: locale === 'ar' ? (role.name_ar ?? role.name_en ?? role.code) : (role.name_en ?? role.name_ar ?? role.code) }))} onChange={setRoleCode} />
            </Field>
            <p className="field-hint" role="note">{labels.scopeCluster}</p>
            <Field id="assignment-end-at" label={labels.endAt}>
              <input
                id="assignment-end-at"
                value={endAt}
                onChange={(event) => setEndAt(event.target.value)}
                dir="ltr"
              />
            </Field>
            <Button type="submit" disabled={pendingId === 'create' || !actor || !roleCode}>{labels.save}</Button>
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
              return (
                <li key={assignment.id} className="assignment-row">
                  <header className="assignment-row-header">
                    <span>{locale === 'ar' ? (roles.find((role) => role.id === assignment.role_id)?.name_ar ?? roles.find((role) => role.id === assignment.role_id)?.name_en ?? labels.role) : (roles.find((role) => role.id === assignment.role_id)?.name_en ?? roles.find((role) => role.id === assignment.role_id)?.name_ar ?? labels.role)}</span>
                    <span>{locale === 'ar' ? (accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_ar ?? accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_en ?? labels.actor) : (accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_en ?? accounts.find((account) => account.id === assignment.subject_user_id)?.display_name_ar ?? labels.actor)}</span>
                    <span>{assignment.effective_status}</span>
                  </header>
                  <div className="assignment-row-actions">
                    {canMutateAdminResource('role-assignments', 'edit', capabilities, actions) && typeof lockVersion === 'number' ? (editingId === assignment.id ? <><Field id={`assignment-end-${assignment.id}`} label={labels.endAt}><input id={`assignment-end-${assignment.id}`} type="datetime-local" value={editingEndAt} onChange={(event) => setEditingEndAt(event.target.value)} required /></Field><Button variant="quiet" disabled={pendingId === assignment.id} onClick={() => void mutate(assignment, 'update')}>{labels.save}</Button><Button variant="quiet" disabled={pendingId === assignment.id} onClick={() => { setEditingId(null); setEditingEndAt('') }}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</Button></> : <Button variant="quiet" disabled={pendingId === assignment.id} onClick={() => { setEditingId(assignment.id); setEditingEndAt(assignment.end_at ? assignment.end_at.slice(0, 16) : '') }}>{locale === 'ar' ? 'تعديل الانتهاء' : 'Edit end date'}</Button>) : null}
                    {canRevoke && typeof lockVersion === 'number' ? <Button variant="quiet" disabled={pendingId === assignment.id} onClick={() => void mutate(assignment, 'revoke')}>{labels.revoke}</Button> : null}
                    {canExpire && typeof lockVersion === 'number' ? <Button variant="quiet" disabled={pendingId === assignment.id} onClick={() => void mutate(assignment, 'expire')}>{labels.expire}</Button> : null}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Panel>
    </div>
  )
}
