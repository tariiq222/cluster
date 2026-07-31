import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { directionForLocale, type Locale } from '../../app/copy'
import { archiveRole, cloneRoleFromSystemRole, createRole, getRole, listCapabilities, listRoles, updateRole } from '../../api/r1'
import type { AuthorizationCapability, AuthorizationRole } from '../../api/generated/cluster'
import { useToken } from '../../app/session-context'
import { ApiError } from '../../api'
import { Button, EmptyState, Field, InlineError, Panel, SkeletonList } from '../../ui'
import { canMutateAdminResource } from './canMutateAdminResource'
import { pluralizeCapabilities } from './copy'
import { useAuthorizationMutationFeedback } from './AuthorizationMutationFeedback'

export type RolesPermissionsTabProps = { locale: Locale; capabilities: readonly string[]; allowedActionsByRole?: Readonly<Record<string, readonly string[]>> }
type LoadState = 'loading' | 'ready' | 'error'
type Draft = { code: string; name: string; capabilities: string[] }
type Recovery = { roleId: string }
const EMPTY: Draft = { code: '', name: '', capabilities: [] }
const COPY = {
  ar: {
    heading: 'الأدوار والصلاحيات', code: 'رمز الدور', name: 'اسم الدور', capabilities: 'الصلاحيات', create: 'إنشاء دور',
    save: 'حفظ التغييرات', archive: 'أرشفة', clone: 'نسخ كدور مخصص', edit: 'تعديل الدور', cancel: 'إلغاء',
    empty: 'لا توجد أدوار لعرضها.', error: 'تعذر حفظ الدور.', loading: 'جارٍ تحميل الأدوار…', reload: 'إعادة التحميل', retry: 'إعادة المحاولة',
  },
  en: {
    heading: 'Roles & Permissions', code: 'Role code', name: 'Role name', capabilities: 'Capabilities', create: 'Create role',
    save: 'Save changes', archive: 'Archive', clone: 'Clone as custom role', edit: 'Edit role', cancel: 'Cancel',
    empty: 'No roles to display.', error: 'Could not save the role.', loading: 'Loading roles…', reload: 'Reload', retry: 'Retry',
  },
} as const satisfies Record<Locale, Record<string, string>>

function roleDraft(role: AuthorizationRole): Draft {
  const preferred = role.name_en ?? role.name_ar ?? role.code
  return { code: role.code, name: preferred, capabilities: role.capability_codes ?? [] }
}

export function RolesPermissionsTab({ locale, capabilities, allowedActionsByRole }: RolesPermissionsTabProps) {
  const token = useToken()
  const labels = COPY[locale]
  const feedback = useAuthorizationMutationFeedback()
  const listRef = useRef<HTMLUListElement>(null)
  const canReadCapabilities = capabilities.includes('authorization.capability.read')
  const [roles, setRoles] = useState<AuthorizationRole[]>([])
  const [catalog, setCatalog] = useState<AuthorizationCapability[]>([])
  const [state, setState] = useState<LoadState>('loading')
  const [error, setError] = useState<string | null>(null)
  const [draft, setDraft] = useState<Draft>(EMPTY)
  const [editing, setEditing] = useState<AuthorizationRole | null>(null)
  const [pending, setPending] = useState(false)
  const [recovery, setRecovery] = useState<Recovery | null>(null)

  const load = useCallback(async () => {
    setState('loading')
    setError(null)
    try {
      const [loadedRoles, loadedCapabilities] = await Promise.all([
        listRoles(token),
        canReadCapabilities ? listCapabilities(token) : Promise.resolve([]),
      ])
      setRoles(loadedRoles)
      setCatalog(loadedCapabilities)
      setState('ready')
    } catch (caught) {
      setState('error')
      setError(caught instanceof ApiError ? caught.message : labels.error)
    }
  }, [canReadCapabilities, labels.error, token])

  useEffect(() => { void load() }, [load])

  const manage = canMutateAdminResource('roles-permissions', 'create', capabilities)
  const actions = (role: AuthorizationRole) => allowedActionsByRole?.[role.id] ?? role.allowed_actions
  const announceError = (caught: unknown) => {
    const message = caught instanceof ApiError ? caught.message : labels.error
    setError(message)
    feedback.announceError(message)
    return message
  }

  const beginEdit = (role: AuthorizationRole) => {
    if (role.is_system_role) return
    setRecovery(null)
    setEditing(role)
    setDraft(roleDraft(role))
  }

  const reloadStaleRole = async (roleId: string) => {
    setPending(true)
    try {
      const refreshedRole = await getRole(token, roleId)
      setRoles((current) => current.map((role) => role.id === refreshedRole.id ? refreshedRole : role))
      setEditing(refreshedRole)
      setError(null)
    } catch (reloadError) {
      announceError(reloadError)
    } finally {
      setPending(false)
    }
  }

  const submitMutation = async () => {
    if (!manage) return
    const editedRole = editing
    setPending(true)
    setError(null)
    setRecovery(null)
    try {
      if (editedRole) {
        await updateRole(token, editedRole.id, { name: draft.name, capability_codes: draft.capabilities }, editedRole.lock_version)
      } else {
        await createRole(token, { resource_type: 'role', code: draft.code, name: draft.name, capability_codes: draft.capabilities })
      }
      setDraft(EMPTY)
      setEditing(null)
    } catch (caught) {
      const message = announceError(caught)
      if (caught instanceof ApiError && caught.status === 409 && editedRole && caught.problem.type === 'urn:cluster:problem:system-role-immutable') {
        setDraft(EMPTY)
        setEditing(null)
        listRef.current?.focus()
      } else if (caught instanceof ApiError && caught.status === 412 && editedRole) {
        setRecovery({ roleId: editedRole.id })
      } else if (message === '') {
        setError(labels.error)
      }
    } finally {
      setPending(false)
    }
  }

  const submit = (event: FormEvent) => {
    event.preventDefault()
    void submitMutation()
  }

  const archive = async (role: AuthorizationRole) => {
    setPending(true)
    setError(null)
    try {
      await archiveRole(token, role.id, role.lock_version)
      await load()
    } catch (caught) {
      announceError(caught)
    } finally {
      setPending(false)
    }
  }

  const clone = async (role: AuthorizationRole) => {
    setPending(true)
    setError(null)
    try {
      await cloneRoleFromSystemRole(token, role.id, undefined, role.lock_version)
      await load()
    } catch (caught) {
      announceError(caught)
    } finally {
      setPending(false)
    }
  }

  if (state === 'loading') return <div dir={directionForLocale(locale)}><SkeletonList label={labels.loading} /></div>
  if (state === 'error') return <div dir={directionForLocale(locale)}><InlineError message={error ?? labels.error} retryLabel={locale === 'ar' ? 'إعادة المحاولة' : 'Try again'} onRetry={() => void load()} /></div>

  return (
    <div dir={directionForLocale(locale)}>
      <Panel id="roles-and-permissions-panel" title={labels.heading} level={2}>
        <p>{pluralizeCapabilities(locale, catalog.length)}</p>
        {manage ? (
          <form className="inline-form" onSubmit={submit}>
            <Field id="role-code" label={labels.code}>
              <input id="role-code" value={draft.code} disabled={Boolean(editing) || pending} required onChange={(event) => setDraft((value) => ({ ...value, code: event.target.value }))} />
            </Field>
            <Field id="role-name" label={labels.name}>
              <input id="role-name" value={draft.name} disabled={pending} required onChange={(event) => setDraft((value) => ({ ...value, name: event.target.value }))} />
            </Field>
            <fieldset>
              <legend>{labels.capabilities}</legend>
              {catalog.map((capability) => (
                <label key={capability.code}>
                  <input type="checkbox" checked={draft.capabilities.includes(capability.code)} disabled={pending} onChange={(event) => setDraft((value) => ({ ...value, capabilities: event.target.checked ? [...value.capabilities, capability.code] : value.capabilities.filter((code) => code !== capability.code) }))} />
                  {capability.code}
                </label>
              ))}
            </fieldset>
            <Button type="submit" disabled={pending}>{editing ? labels.save : labels.create}</Button>
            {editing ? <Button type="button" variant="quiet" onClick={() => { setEditing(null); setDraft(EMPTY); setRecovery(null) }}>{labels.cancel}</Button> : null}
          </form>
        ) : null}
        {error ? <p role="alert" className="error-summary">{error}</p> : null}
        {recovery ? (
          <div role="group" aria-label={locale === 'ar' ? 'استرداد تعارض الإصدار' : 'Version conflict recovery'}>
            <Button type="button" variant="quiet" disabled={pending} onClick={() => void reloadStaleRole(recovery.roleId)}>{labels.reload}</Button>
            <Button type="button" variant="quiet" disabled={pending} onClick={() => void submitMutation()}>{labels.retry}</Button>
          </div>
        ) : null}
        {roles.length === 0 ? (
          <EmptyState icon={<span aria-hidden="true">🛡️</span>} title={labels.empty} />
        ) : (
          <ul ref={listRef} tabIndex={-1} className="data-list">
            {roles.map((role) => {
              const allowed = actions(role)
              const editable = !role.is_system_role && canMutateAdminResource('roles-permissions', 'edit', capabilities, allowed)
              const archivable = !role.is_system_role && canMutateAdminResource('roles-permissions', 'archive', capabilities, allowed)
              const clonable = role.is_system_role && canMutateAdminResource('roles-permissions', 'clone', capabilities, allowed)
              return (
                <li key={role.id} className="role-row">
                  <strong>{locale === 'ar' ? (role.name_ar ?? role.name_en ?? role.code) : (role.name_en ?? role.name_ar ?? role.code)}</strong>
                  <span className="role-code" dir="ltr">{role.code}</span>
                  <p>{pluralizeCapabilities(locale, (role.capability_codes ?? []).length)}</p>
                  <div className="role-row-actions">
                    {editable ? <Button variant="quiet" disabled={pending} onClick={() => beginEdit(role)}>{labels.edit}</Button> : null}
                    {archivable ? <Button variant="quiet" disabled={pending} onClick={() => void archive(role)}>{labels.archive}</Button> : null}
                    {clonable ? <Button disabled={pending} onClick={() => void clone(role)}>{labels.clone}</Button> : null}
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
