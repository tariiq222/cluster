import { useState } from 'react'
import { Activity, DatabaseBackup, HardDrive, ShieldAlert } from 'lucide-react'

import { Button, DataFreshness, MetricTile, Panel, PanelGrid, StatusBadge } from '../../ui'
import { isAllowed, screenText, stateGate, type PlatformScreenProps } from './screen-support'

export function PlatformOverviewScreen({ locale, state = 'success', allowedActions, resource }: PlatformScreenProps) {
  const [actionStatus, setActionStatus] = useState<'health' | 'backup' | null>(null)
  const gate = stateGate(locale, state, screenText(locale, 'لا يوجد نشاط حديث', 'No recent activity'))
  if (gate) return gate
  const stale = state === 'stale'
  return (
    <div className="platform-screen">
      {stale ? <DataFreshness state="stale" updatedAt={screenText(locale, 'آخر تحديث: 09:30', 'Last updated: 09:30')} staleAfterMinutes={15} /> : null}
      <Panel id="platform-required-action" title={screenText(locale, 'الإجراء المطلوب', 'Required action')}>
        <p>{resource !== undefined ? screenText(locale, 'لا توجد إجراءات حرجة معلقة. راجع خدمة الطابور المتدهورة.', 'No critical action is pending. Review the degraded queue service.') : ''}</p>
        <StatusBadge variant="warning">{screenText(locale, 'يحتاج متابعة', 'Needs attention')}</StatusBadge>
      </Panel>
      <PanelGrid className="platform-metrics">
        <MetricTile label={screenText(locale, 'الخدمات', 'Services')} value="7/8" variant="stale" source={screenText(locale, 'مصدر: فحص المنصة', 'Source: platform check')} />
        <MetricTile label={screenText(locale, 'آخر نسخة', 'Last backup')} value={screenText(locale, 'ناجحة', 'Succeeded')} variant="ready" updatedAt="2026-07-23T06:00:00+03:00" />
        <MetricTile label={screenText(locale, 'التنبيهات', 'Alerts')} value="2" variant="empty" period={screenText(locale, 'آخر 24 ساعة', 'Last 24 hours')} />
        <MetricTile label={screenText(locale, 'التخزين', 'Storage')} value="68%" variant="ready" source={screenText(locale, 'مصدر: التخزين', 'Source: storage')} />
      </PanelGrid>
      <Panel id="platform-service-status" title={screenText(locale, 'حالة الخدمات', 'Service status')}>
        <ul className="platform-status-list">
          <li><Activity aria-hidden="true" /><span>{screenText(locale, 'قاعدة البيانات', 'Database')}</span><StatusBadge variant="success">{screenText(locale, 'سليم', 'Healthy')}</StatusBadge></li>
          <li><HardDrive aria-hidden="true" /><span>{screenText(locale, 'الطابور', 'Queue')}</span><StatusBadge variant="warning">{screenText(locale, 'متدهور', 'Degraded')}</StatusBadge></li>
          <li><DatabaseBackup aria-hidden="true" /><span>{screenText(locale, 'النسخ الاحتياطي', 'Backups')}</span><StatusBadge variant="success">{screenText(locale, 'سليم', 'Healthy')}</StatusBadge></li>
        </ul>
      </Panel>
      <Panel id="platform-safe-actions" title={screenText(locale, 'إجراءات سريعة آمنة', 'Safe quick actions')}>
        <div className="platform-action-row">
          {isAllowed(allowedActions, 'platform_operations.health.read') ? <Button variant="secondary" onClick={() => setActionStatus('health')}>{screenText(locale, 'تحديث الفحص', 'Refresh check')}</Button> : null}
          {isAllowed(allowedActions, 'platform_operations.backup.run') ? <Button onClick={() => setActionStatus('backup')}>{screenText(locale, 'تشغيل نسخة الآن', 'Run backup now')}</Button> : null}
        </div>
        {actionStatus === 'health' ? <p role="status">{screenText(locale, 'تم تحديث فحص صحة المنصة.', 'Platform health check refreshed.')}</p> : null}
        {actionStatus === 'backup' ? <p role="status">{screenText(locale, 'تمت جدولة النسخة الاحتياطية باستخدام مفتاح idempotency.', 'Backup queued with an idempotency key.')}</p> : null}
      </Panel>
      <Panel id="platform-recent-activity" title={screenText(locale, 'النشاط الأخير', 'Recent activity')}>
        <ul className="platform-activity-list"><li><ShieldAlert aria-hidden="true" />{screenText(locale, 'تنبيه طابور متدهور — 09:18', 'Degraded queue alert — 09:18')}</li><li><DatabaseBackup aria-hidden="true" />{screenText(locale, 'اكتملت النسخة المجدولة — 06:00', 'Scheduled backup completed — 06:00')}</li></ul>
      </Panel>
    </div>
  )
}
