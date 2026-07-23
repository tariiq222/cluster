import { useState } from 'react'

import { Button, Drawer, Field, Panel, StatusBadge } from '../../ui'
import { isAllowed, screenText, stateGate, type PlatformScreenProps } from './screen-support'

const policyRows = [
  ['الحد الأدنى لطول كلمة المرور', 'Minimum password length', '12', '8–64'],
  ['مهلة الخمول بالدقائق', 'Idle timeout in minutes', '30', '5–120'],
  ['محاولات الدخول الفاشلة', 'Failed login attempts', '5', '3–10'],
]

export function SecuritySettingsScreen({ locale, state = 'success', allowedActions }: PlatformScreenProps) {
  const [publishOpen, setPublishOpen] = useState(false)
  const [notice, setNotice] = useState<'draft' | 'validated' | 'published' | null>(null)
  const gate = stateGate(locale, state, screenText(locale, 'لا توجد سياسة منشورة', 'No published policy'))
  if (gate) return gate
  const canManage = isAllowed(allowedActions, 'platform_settings.manage')
  const canPublish = isAllowed(allowedActions, 'platform_settings.publish')
  return <div className="platform-screen">
    <Panel id="security-versions" title={screenText(locale, 'إصدارات الإعدادات', 'Settings versions')}><dl className="platform-definition-list"><div><dt>{screenText(locale, 'الإصدار الفعال', 'Active version')}</dt><dd>v3 <StatusBadge variant="success">{screenText(locale, 'منشور', 'Published')}</StatusBadge></dd></div><div><dt>{screenText(locale, 'المسودة', 'Draft')}</dt><dd>v4 <StatusBadge variant="info">{screenText(locale, 'مسودة', 'Draft')}</StatusBadge></dd></div><div><dt>{screenText(locale, 'اللغة الافتراضية', 'Default language')}</dt><dd>{screenText(locale, 'العربية', 'Arabic')}</dd></div><div><dt>{screenText(locale, 'المنطقة الزمنية', 'Time zone')}</dt><dd>Asia/Riyadh</dd></div></dl></Panel>
    <Panel id="security-policy" title={screenText(locale, 'سياسة الأمان', 'Security policy')}><div className="platform-policy-grid">{policyRows.map(([ar, en, value, limits]) => <Field key={en} id={`security-${en}`} label={screenText(locale, ar, en)} help={screenText(locale, `الحدود الثابتة: ${limits}`, `Fixed range: ${limits}`)}><p className="platform-readonly-value" id={`security-${en}`}>{value}</p></Field>)}</div></Panel>
    <div className="platform-action-row">{canManage ? <Button variant="secondary" onClick={() => setNotice('draft')}>{screenText(locale, 'إنشاء مسودة', 'Create draft')}</Button> : null}{canManage ? <Button variant="secondary" onClick={() => setNotice('validated')}>{screenText(locale, 'التحقق من المسودة', 'Validate draft')}</Button> : null}{canPublish ? <Button onClick={() => setPublishOpen(true)}>{screenText(locale, 'نشر الإعدادات', 'Publish settings')}</Button> : null}</div>
    {notice === 'draft' ? <p role="status">{screenText(locale, 'تم إنشاء مسودة الإعدادات v5.', 'Settings draft v5 created.')}</p> : null}
    {notice === 'validated' ? <p role="status">{screenText(locale, 'تم التحقق من المسودة ولا توجد مخالفات.', 'Draft validated with no violations.')}</p> : null}
    {notice === 'published' ? <p role="status">{screenText(locale, 'تم نشر إعدادات الأمان بنجاح.', 'Security settings published successfully.')}</p> : null}
    <Drawer open={publishOpen} onClose={() => setPublishOpen(false)} title={screenText(locale, 'تأكيد نشر الإعدادات', 'Confirm settings publication')}><p>{screenText(locale, 'سيصبح الإصدار v4 فعالاً لجميع المستخدمين.', 'Version v4 will become active for all users.')}</p><div className="platform-action-row"><Button onClick={() => { setPublishOpen(false); setNotice('published') }}>{screenText(locale, 'تأكيد النشر', 'Confirm publication')}</Button><Button variant="quiet" onClick={() => setPublishOpen(false)}>{screenText(locale, 'إلغاء', 'Cancel')}</Button></div></Drawer>
  </div>
}
