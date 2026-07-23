import { useState } from 'react'
import { CalendarDays } from 'lucide-react'

import { Button, Drawer, Field, Panel, Select, StatusBadge } from '../../ui'
import { isAllowed, screenText, stateGate, type PlatformScreenProps } from './screen-support'

export function BusinessCalendarsScreen({ locale, state = 'success', allowedActions }: PlatformScreenProps) {
  const [scope, setScope] = useState('platform')
  const [overrideOpen, setOverrideOpen] = useState(false)
  const gate = stateGate(locale, state, screenText(locale, 'لا يوجد تقويم في هذا النطاق', 'No calendar exists in this scope'))
  if (gate) return gate
  return <div className="platform-screen">
    <Panel id="calendar-scope" title={screenText(locale, 'نطاق التقويم', 'Calendar scope')}><Field id="calendar-scope-select" label={screenText(locale, 'النطاق', 'Scope')}><Select id="calendar-scope-select" value={scope} onChange={setScope} options={[{ value: 'platform', label: screenText(locale, 'المنصة', 'Platform') }, { value: 'cluster', label: screenText(locale, 'التجمع', 'Cluster') }, { value: 'facility', label: screenText(locale, 'المنشأة', 'Facility') }]} ariaLabel={screenText(locale, 'نطاق التقويم', 'Calendar scope')} /></Field></Panel>
    <Panel id="calendar-workweek" title={screenText(locale, 'أسبوع العمل', 'Working week')}><ul className="platform-status-list"><li><CalendarDays aria-hidden="true" /><span>{screenText(locale, 'الأحد–الخميس، 08:00–16:00', 'Sunday–Thursday, 08:00–16:00')}</span><StatusBadge variant="info">{screenText(locale, 'مصدر: المنصة', 'Source: platform')}</StatusBadge></li><li><CalendarDays aria-hidden="true" /><span>{screenText(locale, 'الجمعة–السبت، عطلة', 'Friday–Saturday, non-working')}</span><StatusBadge variant="neutral">{screenText(locale, 'مصدر: المنصة', 'Source: platform')}</StatusBadge></li></ul></Panel>
    <Panel id="calendar-exceptions" title={screenText(locale, 'العطل والفترات الموسمية', 'Holidays and seasonal periods')}><ul className="platform-activity-list"><li>{screenText(locale, 'اليوم الوطني — عطلة رسمية', 'National Day — official holiday')}</li><li>{screenText(locale, 'رمضان 1448 — 10:00–15:00', 'Ramadan 1448 — 10:00–15:00')}</li></ul>{isAllowed(allowedActions, 'platform_settings.calendar.override_official_holiday') ? <Button variant="secondary" onClick={() => setOverrideOpen(true)}>{screenText(locale, 'طلب العمل أثناء عطلة رسمية', 'Request official-holiday work')}</Button> : null}</Panel>
    <Drawer open={overrideOpen} onClose={() => setOverrideOpen(false)} title={screenText(locale, 'سبب العمل أثناء العطلة', 'Reason for official-holiday work')}><p>{screenText(locale, 'يتطلب هذا الاستثناء سبباً وتأكيداً مستقلاً.', 'This exception requires a reason and separate confirmation.')}</p><div className="platform-action-row"><Button onClick={() => setOverrideOpen(false)}>{screenText(locale, 'تأكيد الطلب', 'Confirm request')}</Button><Button variant="quiet" onClick={() => setOverrideOpen(false)}>{screenText(locale, 'إلغاء', 'Cancel')}</Button></div></Drawer>
  </div>
}
