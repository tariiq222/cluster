import { useEffect, useState } from 'react'

import { ApiError } from '../../api'
import { Button, Drawer, Field, Panel, StatusBadge } from '../../ui'
import { isAllowed, screenText, stateGate, type PlatformScreenProps } from './screen-support'
import { useSecuritySettingsLive } from './useSecuritySettingsLive'

type SecuritySettingsScreenProps = PlatformScreenProps & {
  token?: string
  onConflict?: () => void
  onCreateDraft?: () => Promise<void> | void
  onValidateDraft?: () => Promise<void> | void
  onPublish?: () => Promise<void> | void
  activeVersionLabel?: string
  draftVersionLabel?: string
  securityValues?: Record<string, unknown>
  defaultLocale?: string
  timezone?: string
}

const policyRows = [
  ['الحد الأدنى لطول كلمة المرور', 'Minimum password length', 'security.minimum_password_length', '8–128'],
  ['مهلة الخمول بالدقائق', 'Idle timeout in minutes', 'security.idle_timeout_minutes', '5–60'],
  ['محاولات الدخول الفاشلة', 'Failed login attempts', 'security.failed_login_attempts', '3–10'],
  ['مدة القفل بالدقائق', 'Lockout duration in minutes', 'security.lockout_minutes', '5–1440'],
] as const

export function SecuritySettingsScreen({
  locale,
  state = 'success',
  allowedActions,
  token,
  onConflict,
  onCreateDraft,
  onValidateDraft,
  onPublish,
  activeVersionLabel = 'v3',
  draftVersionLabel = 'v4',
  securityValues = {},
  defaultLocale = 'ar',
  timezone = 'Asia/Riyadh',
}: SecuritySettingsScreenProps) {
  const [publishOpen, setPublishOpen] = useState(false)
  const [notice, setNotice] = useState<'draft' | 'validated' | 'published' | 'error' | 'stale' | null>(null)
  const [busy, setBusy] = useState<'draft' | 'validated' | 'published' | null>(null)
  const [edits, setEdits] = useState<Record<string, string>>({})
  const [saveError, setSaveError] = useState<string | null>(null)
  const [settingBusy, setSettingBusy] = useState<string | null>(null)
  const live = useSecuritySettingsLive(token ?? '__no_token__', { onConflict })

  useEffect(() => {
    if (live.state.kind === 'ready') {
      setNotice(live.state.notice)
    } else if (live.state.kind === 'error') {
      setNotice('error')
    }
  }, [live.state])

  const gate = stateGate(locale, state, screenText(locale, 'لا توجد سياسة منشورة', 'No published policy'))
  if (gate) return gate
  const canManage = isAllowed(allowedActions, 'platform_settings.manage')
  const canPublish = isAllowed(allowedActions, 'platform_settings.publish')
  // The API returns `security` as a nested object; the screen reads
  // values via dotted keys (`security.minimum_password_length`), so
  // flatten the nested object into a single dictionary.
  const liveSecurity: Record<string, unknown> = (() => {
    const liveEntity = live.state.kind === 'ready'
      ? live.state.entity as unknown as { security?: Record<string, unknown> }
      : undefined
    const fromLive = liveEntity?.security !== undefined
      ? Object.entries(liveEntity.security).reduce<Record<string, unknown>>((acc, [k, v]) => {
          acc[`security.${k}`] = v
          return acc
        }, {})
      : {}
    const fromProps: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(securityValues)) fromProps[k] = v
    return Object.keys(fromLive).length > 0 ? { ...fromProps, ...fromLive } : fromProps
  })()
  const readValue = (key: string): string => {
    const edited = edits[key]
    if (edited !== undefined) return edited
    const value = liveSecurity[key]
    return value === undefined || value === null ? '' : String(value)
  }
  function setEdit(key: string, value: string): void {
    setEdits((prev) => ({ ...prev, [key]: value }))
  }
  async function saveSetting(key: string): Promise<void> {
    if (token === undefined || !canManage) return
    const raw = (edits[key] ?? '').trim()
    if (raw === '') {
      setSaveError(screenText(locale, 'القيمة مطلوبة.', 'Value is required.'))
      return
    }
    const numeric = Number(raw)
    if (!Number.isFinite(numeric)) {
      setSaveError(screenText(locale, 'القيمة يجب أن تكون رقماً.', 'Value must be a number.'))
      return
    }
    setSaveError(null)
    setSettingBusy(key)
    try {
      await live.setValue(key, { value_type: 'integer', value: numeric })
      setEdits((prev) => {
        const next = { ...prev }
        delete next[key]
        return next
      })
    } catch (error) {
      if (error instanceof ApiError) {
        setSaveError(error.problem.title ?? error.problem.detail ?? 'Update failed')
      } else {
        setSaveError(screenText(locale, 'فشل تحديث الإعداد.', 'Update failed.'))
      }
    } finally {
      setSettingBusy(null)
    }
  }

  async function runAction(
    kind: 'draft' | 'validated' | 'published',
    action: (() => Promise<void> | void) | undefined,
  ) {
    setBusy(kind)
    setSaveError(null)
    try {
      if (token !== undefined) {
        if (kind === 'draft') await live.createDraft()
        else if (kind === 'validated') await live.validate()
        else await live.publish()
      } else {
        await action?.()
        setNotice(kind)
      }
      if (kind === 'published') setPublishOpen(false)
    } catch (error) {
      if (error instanceof ApiError && (error.status === 409 || error.status === 412)) {
        setNotice('stale')
      } else {
        setNotice('error')
      }
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="platform-screen">
      <Panel id="security-versions" title={screenText(locale, 'إصدارات الإعدادات', 'Settings versions')}>
        <dl className="platform-definition-list">
          <div>
            <dt>{screenText(locale, 'الإصدار الفعال', 'Active version')}</dt>
            <dd>
              {activeVersionLabel} <StatusBadge variant="success">{screenText(locale, 'منشور', 'Published')}</StatusBadge>
            </dd>
          </div>
          <div>
            <dt>{screenText(locale, 'المسودة', 'Draft')}</dt>
            <dd>
              {draftVersionLabel} <StatusBadge variant="info">{screenText(locale, 'مسودة', 'Draft')}</StatusBadge>
            </dd>
          </div>
          <div>
            <dt>{screenText(locale, 'اللغة الافتراضية', 'Default language')}</dt>
            <dd>{defaultLocale === 'en' ? screenText(locale, 'الإنجليزية', 'English') : screenText(locale, 'العربية', 'Arabic')}</dd>
          </div>
          <div>
            <dt>{screenText(locale, 'المنطقة الزمنية', 'Time zone')}</dt>
            <dd>{timezone}</dd>
          </div>
        </dl>
      </Panel>
      <Panel id="security-policy" title={screenText(locale, 'سياسة الأمان', 'Security policy')}>
        <div className="platform-policy-grid">
          {policyRows.map(([ar, en, key, limits]) => {
            const inputId = `security-${en}-input`
            const currentValue = readValue(key)
            const liveSync = token !== undefined && live.state.kind === 'ready' && (live.state.entity.status === 'draft' || live.state.entity.status === 'validated')
            return (
              <Field
                key={en}
                id={`security-${en}`}
                label={screenText(locale, ar, en)}
                help={screenText(locale, `الحدود الثابتة: ${limits}`, `Fixed range: ${limits}`)}
              >
                <p className="platform-readonly-value" id={`security-${en}`} data-setting-key={key}>
                  {String(liveSecurity[key] ?? '—')}
                </p>
                {liveSync && canManage ? (
                  <div className="platform-policy-edit">
                    <input
                      id={inputId}
                      type="number"
                      inputMode="numeric"
                      value={currentValue}
                      onChange={(event) => setEdit(key, event.target.value)}
                      disabled={settingBusy === key}
                      aria-label={screenText(locale, ar, en)}
                      data-setting-key={key}
                    />
                    <Button
                      variant="secondary"
                      onClick={() => void saveSetting(key)}
                      disabled={settingBusy === key || (edits[key] ?? '') === String(liveSecurity[key] ?? '')}
                    >
                      {screenText(locale, 'حفظ', 'Save')}
                    </Button>
                  </div>
                ) : null}
              </Field>
            )
          })}
        </div>
        {saveError !== null ? (
          <p role="alert" className="platform-error">{saveError}</p>
        ) : null}
      </Panel>
      <div className="platform-action-row">
        {canManage ? (
          <Button variant="secondary" disabled={busy !== null} onClick={() => { void runAction('draft', onCreateDraft) }}>
            {screenText(locale, 'إنشاء مسودة', 'Create draft')}
          </Button>
        ) : null}
        {canManage ? (
          <Button variant="secondary" disabled={busy !== null} onClick={() => { void runAction('validated', onValidateDraft) }}>
            {screenText(locale, 'التحقق من المسودة', 'Validate draft')}
          </Button>
        ) : null}
        {canPublish ? (
          <Button disabled={busy !== null} onClick={() => setPublishOpen(true)}>
            {screenText(locale, 'نشر الإعدادات', 'Publish settings')}
          </Button>
        ) : null}
      </div>
      {notice === 'draft' ? <p role="status">{screenText(locale, 'تم إنشاء مسودة الإعدادات v5.', 'Settings draft v5 created.')}</p> : null}
      {notice === 'validated' ? <p role="status">{screenText(locale, 'تم التحقق من المسودة ولا توجد مخالفات.', 'Draft validated with no violations.')}</p> : null}
      {notice === 'published' ? <p role="status">{screenText(locale, 'تم نشر إعدادات الأمان بنجاح.', 'Security settings published successfully.')}</p> : null}
      {notice === 'stale' ? <p role="status">{screenText(locale, 'النسخة قديمة. أعد التحميل لإعادة المحاولة.', 'The version is stale. Refresh and try again.')}</p> : null}
      {notice === 'error' ? <p role="status">{screenText(locale, 'تعذر حفظ التغيير. أعد المحاولة بعد تحديث البيانات.', 'The change could not be saved. Refresh and try again.')}</p> : null}
      <Drawer open={publishOpen} onClose={() => setPublishOpen(false)} title={screenText(locale, 'تأكيد نشر الإعدادات', 'Confirm settings publication')}>
        <p>{screenText(locale, 'سيصبح الإصدار v4 فعالاً لجميع المستخدمين.', 'Version v4 will become active for all users.')}</p>
        <div className="platform-action-row">
          <Button disabled={busy !== null} onClick={() => { void runAction('published', onPublish) }}>
            {screenText(locale, 'تأكيد النشر', 'Confirm publication')}
          </Button>
          <Button variant="quiet" disabled={busy !== null} onClick={() => setPublishOpen(false)}>
            {screenText(locale, 'إلغاء', 'Cancel')}
          </Button>
        </div>
      </Drawer>
    </div>
  )
}
