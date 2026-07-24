import { useState } from 'react'

import { ApiError } from '../../api'
import { requestPlatformTechnicalLogsRestore } from '../../api/platform-settings'
import { Button, Drawer, Field, Panel, Select, StatusBadge } from '../../ui'
import { isAllowed, screenText, stateGate, type PlatformLogsScreenProps } from './screen-support'

type LogRow = {
  id: string
  source: string
  severity: 'info' | 'warning' | 'critical'
  ar: string
  en: string
  at: string
}

type Notice =
  | { kind: 'idle' }
  | { kind: 'success'; message: string }
  | { kind: 'error'; message: string }

function asText(value: unknown): string | null {
  return typeof value === 'string' ? value : null
}

function readableLogs(logs: PlatformLogsScreenProps['logs']): LogRow[] {
  return (logs?.items ?? []).flatMap((item) => {
    const row = item as unknown as Record<string, unknown>
    const source = asText(row.source)
    const severity = asText(row.severity)
    const ar = asText(row.message_ar)
    const en = asText(row.message_en)
    const at = asText(row.occurred_at)
    if (
      source === null ||
      ar === null ||
      en === null ||
      at === null ||
      !['info', 'warning', 'critical'].includes(severity ?? '')
    ) {
      return []
    }
    return [{
      id: String(row.id ?? ''),
      source,
      severity: severity as LogRow['severity'],
      ar,
      en,
      at,
    }]
  })
}

export function TechnicalLogsScreen({
  locale,
  state = 'success',
  allowedActions,
  logs,
  onCursorChange,
  token,
}: PlatformLogsScreenProps & { token?: string }) {
  const [severity, setSeverity] = useState('all')
  const [source, setSource] = useState('all')
  const [archiveOpen, setArchiveOpen] = useState(false)
  const [manifestId, setManifestId] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<Notice>({ kind: 'idle' })
  const rows = readableLogs(logs)
  const filteredRows = rows.filter(
    (row) =>
      (severity === 'all' || row.severity === severity) &&
      (source === 'all' || row.source === source),
  )
  const gate = stateGate(
    locale,
    state === 'success' && rows.length === 0 ? 'empty' : state,
    screenText(locale, 'لا توجد سجلات مطابقة', 'No matching logs'),
  )
  if (gate) return gate

  async function submitRestore(): Promise<void> {
    const normalizedManifestId = manifestId.trim()
    const normalizedReason = reason.trim()
    if (normalizedManifestId === '' || normalizedReason === '') {
      setNotice({
        kind: 'error',
        message: screenText(
          locale,
          'أدخل معرّف بيان الأرشيف وسبب الاسترجاع.',
          'Enter the archive manifest ID and reason.',
        ),
      })
      return
    }
    if (token === undefined || busy) return
    setBusy(true)
    setNotice({ kind: 'idle' })
    try {
      await requestPlatformTechnicalLogsRestore(token, {
        manifest_id: normalizedManifestId,
        reason: normalizedReason,
      })
      setArchiveOpen(false)
      setManifestId('')
      setReason('')
      setNotice({
        kind: 'success',
        message: screenText(locale, 'تم طلب استرجاع الأرشيف.', 'Archive restore requested.'),
      })
    } catch (error) {
      setNotice({
        kind: 'error',
        message: error instanceof ApiError
          ? error.problem.detail ?? error.problem.title
          : screenText(locale, 'تعذر إرسال طلب الاسترجاع.', 'The restore request could not be sent.'),
      })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="platform-screen">
      <Panel id="logs-filters" title={screenText(locale, 'تصفية السجلات', 'Filter logs')}>
        <div className="platform-policy-grid">
          <Field id="log-severity" label={screenText(locale, 'الخطورة', 'Severity')}>
            <Select
              id="log-severity"
              value={severity}
              onChange={setSeverity}
              options={[
                { value: 'all', label: screenText(locale, 'الكل', 'All') },
                { value: 'warning', label: screenText(locale, 'تحذير', 'Warning') },
                { value: 'critical', label: screenText(locale, 'حرج', 'Critical') },
              ]}
              ariaLabel={screenText(locale, 'الخطورة', 'Severity')}
            />
          </Field>
          <Field id="log-source" label={screenText(locale, 'النوع', 'Source')}>
            <Select
              id="log-source"
              value={source}
              onChange={setSource}
              options={[
                { value: 'all', label: screenText(locale, 'الكل', 'All') },
                ...[...new Set(rows.map((row) => row.source))].map((value) => ({
                  value,
                  label: value,
                })),
              ]}
              ariaLabel={screenText(locale, 'النوع', 'Source')}
            />
          </Field>
          <p className="platform-filter-summary">
            {screenText(
              locale,
              'يُطبق النطاق ومعرّف الارتباط والنص المنقّى عند طلب البيانات من الخادم.',
              'Range, correlation ID, and sanitized text are applied by the server query.',
            )}
          </p>
        </div>
      </Panel>
      <Panel id="technical-log-results" title={screenText(locale, 'النتائج', 'Results')}>
        <ul className="platform-log-list">
          {filteredRows.map((row) => (
            <li key={row.id}>
              <StatusBadge
                variant={
                  row.severity === 'critical'
                    ? 'danger'
                    : row.severity === 'warning'
                      ? 'warning'
                      : 'info'
                }
              >
                {row.source}
              </StatusBadge>
              <span>{screenText(locale, row.ar, row.en)}</span>
              <time dateTime={row.at}>{row.at}</time>
            </li>
          ))}
        </ul>
        {filteredRows.length === 0 ? (
          <p>{screenText(locale, 'لا توجد نتائج لهذه التصفية.', 'No results match these filters.')}</p>
        ) : null}
        <div className="platform-action-row">
          {logs?.next_cursor ? (
            <Button variant="secondary" onClick={() => onCursorChange?.(logs.next_cursor)}>
              {screenText(locale, 'الصفحة التالية', 'Next page')}
            </Button>
          ) : null}
          {isAllowed(allowedActions, 'platform_operations.logs.restore') && token !== undefined ? (
            <Button onClick={() => {
              setNotice({ kind: 'idle' })
              setArchiveOpen(true)
            }}>
              {screenText(locale, 'طلب استرجاع أرشيف', 'Request archive restore')}
            </Button>
          ) : null}
        </div>
      </Panel>
      {notice.kind === 'success' ? <p role="status">{notice.message}</p> : null}
      {!archiveOpen && notice.kind === 'error' ? (
        <p role="alert" className="platform-error">{notice.message}</p>
      ) : null}
      <Drawer
        open={archiveOpen}
        onClose={() => {
          if (!busy) setArchiveOpen(false)
        }}
        title={screenText(locale, 'استرجاع الأرشيف', 'Archive restore')}
      >
        <p>
          {screenText(
            locale,
            'سيُرسل الطلب للأرشيف المصرح به فقط، مع تسجيل سبب الاسترجاع.',
            'Only an authorized archive can be requested, and the reason is audited.',
          )}
        </p>
        <Field
          id="technical-log-manifest"
          label={screenText(locale, 'معرّف بيان الأرشيف', 'Archive manifest ID')}
          required
        >
          <input
            id="technical-log-manifest"
            className="platform-input"
            value={manifestId}
            onChange={(event) => setManifestId(event.target.value)}
            disabled={busy}
          />
        </Field>
        <Field
          id="technical-log-restore-reason"
          label={screenText(locale, 'السبب', 'Reason')}
          required
        >
          <textarea
            id="technical-log-restore-reason"
            className="platform-input platform-textarea"
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            disabled={busy}
          />
        </Field>
        {archiveOpen && notice.kind === 'error' ? (
          <p role="alert" className="platform-error">{notice.message}</p>
        ) : null}
        <div className="platform-action-row">
          <Button onClick={() => void submitRestore()} disabled={busy}>
            {busy
              ? screenText(locale, 'جارٍ إرسال الطلب…', 'Submitting request…')
              : screenText(locale, 'إرسال الطلب', 'Submit request')}
          </Button>
          <Button variant="quiet" onClick={() => setArchiveOpen(false)} disabled={busy}>
            {screenText(locale, 'إلغاء', 'Cancel')}
          </Button>
        </div>
      </Drawer>
    </div>
  )
}
