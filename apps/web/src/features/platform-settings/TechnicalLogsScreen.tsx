import { useState } from 'react'

import { Button, Drawer, Field, Panel, Select, StatusBadge } from '../../ui'
import { isAllowed, screenText, stateGate, type PlatformLogsScreenProps } from './screen-support'

type LogRow = { id: string; source: string; severity: 'info' | 'warning' | 'critical'; ar: string; en: string; at: string }

function asText(value: unknown): string | null {
  return typeof value === 'string' ? value : null
}

function readableLogs(logs: PlatformLogsScreenProps['logs']): LogRow[] {
  return (logs?.items ?? []).flatMap((item) => {
    if (!('values' in item) || item.values === undefined) return []
    const source = asText(item.values.source)
    const severity = asText(item.values.severity)
    const ar = asText(item.values.message_ar)
    const en = asText(item.values.message_en)
    const at = asText(item.values.occurred_at)
    if (source === null || ar === null || en === null || at === null || !['info', 'warning', 'critical'].includes(severity ?? '')) return []
    return [{ id: item.id, source, severity: severity as LogRow['severity'], ar, en, at }]
  })
}

export function TechnicalLogsScreen({ locale, state = 'success', allowedActions, logs, onCursorChange }: PlatformLogsScreenProps) {
  const [severity, setSeverity] = useState('all')
  const [source, setSource] = useState('all')
  const [archiveOpen, setArchiveOpen] = useState(false)
  const rows = readableLogs(logs)
  const filteredRows = rows.filter((row) => (severity === 'all' || row.severity === severity) && (source === 'all' || row.source === source))
  const gate = stateGate(locale, state === 'success' && rows.length === 0 ? 'empty' : state, screenText(locale, 'لا توجد سجلات مطابقة', 'No matching logs'))
  if (gate) return gate
  return <div className="platform-screen"><Panel id="logs-filters" title={screenText(locale, 'تصفية السجلات', 'Filter logs')}><div className="platform-policy-grid"><Field id="log-severity" label={screenText(locale, 'الخطورة', 'Severity')}><Select id="log-severity" value={severity} onChange={setSeverity} options={[{ value: 'all', label: screenText(locale, 'الكل', 'All') }, { value: 'warning', label: screenText(locale, 'تحذير', 'Warning') }, { value: 'critical', label: screenText(locale, 'حرج', 'Critical') }]} ariaLabel={screenText(locale, 'الخطورة', 'Severity')} /></Field><Field id="log-source" label={screenText(locale, 'النوع', 'Source')}><Select id="log-source" value={source} onChange={setSource} options={[{ value: 'all', label: screenText(locale, 'الكل', 'All') }, ...[...new Set(rows.map((row) => row.source))].map((value) => ({ value, label: value }))]} ariaLabel={screenText(locale, 'النوع', 'Source')} /></Field><p className="platform-filter-summary">{screenText(locale, 'من/إلى وcorrelation id والنص المنقى تُطبق في حد API.', 'Range, correlation ID, and sanitized text apply at the API boundary.')}</p></div></Panel><Panel id="technical-log-results" title={screenText(locale, 'النتائج', 'Results')}><ul className="platform-log-list">{filteredRows.map((row) => <li key={row.id}><StatusBadge variant={row.severity === 'critical' ? 'danger' : row.severity === 'warning' ? 'warning' : 'info'}>{row.source}</StatusBadge><span>{screenText(locale, row.ar, row.en)}</span><time>{row.at}</time></li>)}</ul>{filteredRows.length === 0 ? <p>{screenText(locale, 'لا توجد نتائج لهذه التصفية.', 'No results match these filters.')}</p> : null}<div className="platform-action-row">{logs?.next_cursor ? <Button variant="secondary" onClick={() => onCursorChange?.(logs.next_cursor)}>{screenText(locale, 'الصفحة التالية', 'Next page')}</Button> : null}{isAllowed(allowedActions, 'platform_operations.logs.restore') ? <Button onClick={() => setArchiveOpen(true)}>{screenText(locale, 'طلب استرجاع أرشيف', 'Request archive restore')}</Button> : null}</div></Panel><Drawer open={archiveOpen} onClose={() => setArchiveOpen(false)} title={screenText(locale, 'استرجاع الأرشيف', 'Archive restore')}><p>{screenText(locale, 'سيتم طلب الأرشيف المصرح به فقط.', 'Only the authorized archive will be requested.')}</p><Button onClick={() => setArchiveOpen(false)}>{screenText(locale, 'إرسال الطلب', 'Submit request')}</Button></Drawer></div>
}
