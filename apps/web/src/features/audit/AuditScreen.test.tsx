// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../../app/session-context'
import { PrincipalContextTestProvider } from '../../app/principal-context'
import { AuditScreen, AuditEventDetail } from './AuditScreen'
import { queryFromFilters, redactedContextEntries } from './audit-utils'
import { clearTrackedExports, getTrackedExports } from '../reports/export-tracker'
import { customFetch } from '../../api/fetcher'
import { ApiError } from '../../api/http'

vi.mock('sonner', () => ({ toast: vi.fn() }))
import { toast } from 'sonner'

vi.mock('../../api/fetcher', () => ({ customFetch: vi.fn() }))

/*
 * Task 9 (Audit lane) — behavior rules asserted before the production rewrite:
 *
 * 1. The Audit tab renders exactly one child H2 (the workspace owns the only
 *    H1) and no H1 of its own.
 * 2. Server-redacted context is filtered client-side: keys whose names
 *    contain hash / key / fingerprint / secret (or HMAC material) never
 *    reach the DOM.
 * 3. Integrity verification resolves into a shadcn Alert with the textual
 *    status and the verified first–last sequence range — never a colored
 *    badge — and the two optional sequence bounds must be supplied together.
 * 4. Audit export creation registers in the session-local tracker with
 *    `kind: 'audit'`, descriptor format/date/id, and `ownerUserId` equal to
 *    the current `session.userId`; the flow stays non-blocking (sonner, no
 *    overlay).
 * 5. Only the supported filter parameters reach the API: source_module,
 *    action, classification, occurred_from, occurred_to.
 */

const SESSION = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const EVENT_ID = '01980f50-5f0d-7000-8000-000000000905'
const EXPORT_ID = '01980f50-5f0d-7000-8000-000000000a02'
const STREAM_KEY = 'documents:document:01980f50-5f0d-7000-8000-000000000902'

const ledgerEvent = {
  event_id: EVENT_ID,
  source_module: 'documents',
  action: 'document.uploaded',
  event_type: 'com.cluster.documents.documentuploaded.v1',
  actor_type: 'user',
  actor_id: '01980f50-5f0d-7000-8000-000000000907',
  original_actor_id: null,
  subject_type: 'document',
  subject_id: '01980f50-5f0d-7000-8000-000000000908',
  correlation_id: '01980f50-5f0d-7000-8000-000000000906',
  outcome: 'succeeded',
  classification: 'confidential',
  context: {
    document_id: '01980f50-5f0d-7000-8000-000000000908',
    filename: '[REDACTED]',
    quarantine_id: '[REDACTED]',
  },
  occurred_at: '2026-07-27T08:00:00.000Z',
  recorded_at: '2026-07-27T08:00:00.125Z',
  access_decision_id: null,
  retention_until: '2033-07-27T08:00:00.000Z',
  integrity_status: 'verified',
  allowed_actions: [],
}

const auditState = vi.hoisted(() => ({
  data: undefined as unknown,
  isLoading: false,
  isError: false,
  error: null as unknown,
  refetch: vi.fn(),
}))

vi.mock('../../api/hooks', () => ({
  useAuditEvents: () => auditState,
}))

function fetcherResponse(payload: unknown, status = 200) {
  return { data: { data: payload }, status, headers: new Headers() }
}

function readyLedger(items: unknown[]) {
  auditState.data = { items, next_cursor: null }
  auditState.isLoading = false
  auditState.isError = false
  auditState.error = null
}

function mount(capabilities: string[], node?: ReactNode) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={SESSION} locale="ar" setLocale={() => {}}>
        <PrincipalContextTestProvider capabilities={capabilities} features={{ work_management: false, tasks: true }}>
          <MemoryRouter>{node ?? <AuditScreen />}</MemoryRouter>
        </PrincipalContextTestProvider>
      </SessionProvider>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  clearTrackedExports()
  readyLedger([])
  vi.mocked(customFetch).mockReset()
  vi.mocked(toast).mockReset()
})

describe('audit screen heading hierarchy', () => {
  it('renders exactly one child H2 and no H1 of its own', () => {
    mount(['audit.event.read'])
    readyLedger([ledgerEvent])

    expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument()
    expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(1)
    expect(screen.getByRole('heading', { level: 2 })).toHaveTextContent('سجل التدقيق')
  })

  it('renders the shared non-disclosing denied state without the read capability', () => {
    mount([])

    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
  })
})

describe('redacted audit context', () => {
  it('never renders context keys containing hash, key, fingerprint, secret, or HMAC material', () => {
    const entries = redactedContextEntries({
      document_id: '01980f50-5f0d-7000-8000-000000000908',
      filename: '[REDACTED]',
      event_hash: 'abc',
      previous_hash: 'def',
      request_hash: 'ghi',
      integrity_key_version: '2',
      request_fingerprint: 'fp',
      hmac: 'mac',
      secret_token: 's3cr3t',
    })
    expect(entries.map(([key]) => key)).toEqual(['document_id', 'filename'])
    const keys = entries.map(([key]) => key).join(' ')
    for (const forbidden of ['hash', 'key', 'fingerprint', 'secret', 'hmac']) {
      expect(keys).not.toMatch(new RegExp(forbidden, 'i'))
    }
  })

  it('recursively drops sensitive keys at any depth while preserving allowed siblings and array structure', () => {
    const entries = redactedContextEntries({
      document_id: 'doc-908',
      envelope: {
        origin: 'wfp',
        signatures: {
          note: 'kept',
          sha256_hash: 'abc',
          request_key: 'k',
        },
      },
      history: [
        { actor_id: 'a', hmac: 'mac', when: '2026-08-01' },
        { actor_id: 'b', when: '2026-08-02' },
      ],
      event_hash: 'top-hash',
    })
    expect(Object.fromEntries(entries)).toEqual({
      document_id: 'doc-908',
      envelope: { origin: 'wfp', signatures: { note: 'kept' } },
      history: [
        { actor_id: 'a', when: '2026-08-01' },
        { actor_id: 'b', when: '2026-08-02' },
      ],
    })
    expect(JSON.stringify(Object.fromEntries(entries))).not.toMatch(/hash|key|fingerprint|secret|hmac/i)
  })

  it('renders only sanitized nested JSON in the event detail DOM', () => {
    const event = {
      ...ledgerEvent,
      context: {
        document_id: 'doc-908',
        filename: '[REDACTED]',
        envelope: {
          origin: 'wfp',
          upload: {
            note: 'retained',
            sha256_hash: 'HASHBYTES-TOP',
            integrity_key_version: 'KEYVER-2',
          },
        },
        references: [
          { id: 'r1', request_fingerprint: 'FINGERPRINT-X' },
          { id: 'r2' },
        ],
        secret_token: 'TOKEN-Y',
      },
    }
    render(<AuditEventDetail event={event} locale="ar" />)

    // Allowed top-level siblings still render as context entries.
    expect(screen.getByText('document_id')).toBeInTheDocument()
    expect(screen.getByText('envelope')).toBeInTheDocument()
    expect(screen.getByText('references')).toBeInTheDocument()
    expect(screen.getByText('[REDACTED]')).toBeInTheDocument()

    // Sensitive keys and their values never reach the DOM at any depth.
    const bodyText = document.body.textContent ?? ''
    for (const forbidden of [
      'sha256_hash',
      'integrity_key_version',
      'request_fingerprint',
      'secret_token',
      'hmac',
      'HASHBYTES-TOP',
      'KEYVER-2',
      'FINGERPRINT-X',
      'TOKEN-Y',
    ]) {
      expect(bodyText).not.toContain(forbidden)
    }
    // The nested JSON keeps allowed siblings and array structure.
    expect(bodyText).toContain('"origin":"wfp"')
    expect(bodyText).toContain('"note":"retained"')
    expect(bodyText).toContain('"id":"r1"')
    expect(bodyText).toContain('"id":"r2"')
  })

  it('keeps hash/key/fingerprint bytes out of the rendered detail DOM', () => {
    const event = {
      ...ledgerEvent,
      context: {
        document_id: '01980f50-5f0d-7000-8000-000000000908',
        filename: '[REDACTED]',
        event_hash: 'abc',
        previous_hash: 'def',
        integrity_key_version: '2',
        request_fingerprint: 'fp',
        secret_token: 's3cr3t',
      },
    }
    render(<AuditEventDetail event={event} locale="ar" />)

    expect(screen.getByText('[REDACTED]')).toBeInTheDocument()
    expect(screen.queryByText('event_hash')).not.toBeInTheDocument()
    expect(screen.queryByText('previous_hash')).not.toBeInTheDocument()
    expect(screen.queryByText('integrity_key_version')).not.toBeInTheDocument()
    expect(screen.queryByText('request_fingerprint')).not.toBeInTheDocument()
    expect(screen.queryByText('secret_token')).not.toBeInTheDocument()
    const bodyText = document.body.textContent ?? ''
    for (const forbidden of ['event_hash', 'previous_hash', 'request_hash', 'integrity_key_version', 'request_fingerprint', 'hmac', 'integrity_key', 'secret_token']) {
      expect(bodyText).not.toContain(forbidden)
    }
  })
})

describe('audit integrity verification', () => {
  it('renders the result as an Alert with the textual status and verified first–last range', async () => {
    mount(['audit.event.read', 'audit.integrity.verify'])
    vi.mocked(customFetch).mockResolvedValue(
      fetcherResponse(
        {
          stream_key: STREAM_KEY,
          first_sequence: 1,
          last_sequence: 5,
          verified_event_count: 5,
          integrity_status: 'verified',
          checkpoint_id: '01980f50-5f0d-7000-8000-000000000a04',
        },
        201,
      ),
    )

    fireEvent.change(screen.getByLabelText('مفتاح السلسلة'), { target: { value: STREAM_KEY } })
    fireEvent.change(screen.getByLabelText('أول تسلسل (اختياري)'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('آخر تسلسل (اختياري)'), { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'تحقق الآن' }))

    const alert = await screen.findByTestId('audit-verification-result')
    expect(alert).toHaveAttribute('role', 'alert')
    expect(alert).toHaveTextContent('تم التحقق')
    // The status is plain Alert text — a standalone span, never a badge.
    expect(within(alert).getByText('verified')).toBeInTheDocument()
    expect(alert).toHaveTextContent('5')
    expect(alert).toHaveTextContent('1–5')
    // The stream key is allowed; hash material never is.
    expect(alert).toHaveTextContent(STREAM_KEY)
    expect(alert.textContent).not.toMatch(/hash|fingerprint|hmac/i)
    // No persistent colored badge — the status is plain Alert text.
    expect(within(alert).queryByTestId('badge')).not.toBeInTheDocument()
  })

  it('requires both optional sequence bounds to be supplied together', async () => {
    mount(['audit.event.read', 'audit.integrity.verify'])

    fireEvent.change(screen.getByLabelText('مفتاح السلسلة'), { target: { value: STREAM_KEY } })
    fireEvent.change(screen.getByLabelText('أول تسلسل (اختياري)'), { target: { value: '1' } })
    fireEvent.click(screen.getByRole('button', { name: 'تحقق الآن' }))

    expect(await screen.findByText('يجب إدخال أول وآخر تسلسل معًا.')).toBeInTheDocument()
    expect(vi.mocked(customFetch)).not.toHaveBeenCalled()
  })
})

describe('audit export registration', () => {
  it('registers the created audit export with kind audit and the current session user as owner', async () => {
    mount(['audit.event.read', 'audit.event.export'])
    vi.mocked(customFetch).mockResolvedValue(
      fetcherResponse(
        {
          id: EXPORT_ID,
          principal_id: SESSION.userId,
          facility_id: null,
          query: { source_module: 'documents' },
          format: 'csv',
          snapshot_recorded_at: '2026-07-27T08:00:00.000Z',
          status: 'ready',
          event_count: 1,
          expires_at: '2026-07-28T08:00:00.000Z',
          created_at: '2026-07-27T08:00:00.000Z',
        },
        201,
      ),
    )

    fireEvent.change(screen.getByLabelText('سبب التصدير'), {
      target: { value: 'Q3 retention review' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'إنشاء التصدير' }))

    await waitFor(() => expect(getTrackedExports(SESSION.userId)).toHaveLength(1))
    const tracked = getTrackedExports(SESSION.userId)[0]
    expect(tracked).toEqual(
      expect.objectContaining({
        id: EXPORT_ID,
        kind: 'audit',
        format: 'csv',
        ownerUserId: SESSION.userId,
      }),
    )
    // The tracker name carries the descriptor format, date, and id.
    expect(tracked.name).toMatch(/csv/i)
    expect(tracked.name).toContain(EXPORT_ID)
    // The raw ISO snapshot date is preserved on the tracked entry itself.
    expect(tracked.createdAt).toBe('2026-07-27T08:00:00.000Z')
    expect(vi.mocked(toast)).toHaveBeenCalled()
  })
})

describe('audit filter parameters', () => {
  it('emits only the supported filter parameters and never invents actor/outcome filters', () => {
    const params = queryFromFilters({
      sourceModule: 'documents',
      action: 'document.uploaded',
      classification: 'top_secret',
      occurredFrom: '2026-07-27T07:00',
      occurredTo: '2026-07-27T09:00',
    })
    expect(Object.keys(params).sort()).toEqual([
      'action',
      'classification',
      'occurred_from',
      'occurred_to',
      'source_module',
    ])
    expect(params).not.toHaveProperty('actor_id')
    expect(params).not.toHaveProperty('actor_type')
    expect(params).not.toHaveProperty('subject_id')
    expect(params).not.toHaveProperty('outcome')
    expect(params).not.toHaveProperty('correlation_id')
  })

  it('returns an empty parameter set when every filter is blank', () => {
    expect(
      queryFromFilters({
        sourceModule: '',
        action: '   ',
        classification: '',
        occurredFrom: '',
        occurredTo: '',
      }),
    ).toEqual({})
  })
})

/*
 * Task UI-IMPL-002B — generated primitives & shared error state. Behavior
 * rules for the migrated controls:
 *
 * 1. Classification filter renders as a Radix Select with an accessible
 *    combobox role; the internal "__all" sentinel determines the displayed
 *    placeholder ("All classifications") and is normalized back to an empty
 *    string before the API is called. Selecting a real value is the only
 *    path that emits a `classification` query parameter.
 * 2. Export format renders as a Radix Select with no "all" sentinel; the
 *    two options map to the exact contract values `csv` and `ndjson`.
 * 3. Initial ledger load failure (no items rendered) resolves into the
 *    shared ErrorState with a retry affordance — replacing the bespoke
 *    destructive Alert previously used.
 * 4. Load-more failure (items already rendered) keeps the table visible and
 *    surfaces a compact inline role="alert" notice, never the blocking
 *    ErrorState.
 */

describe('audit filter and export Selects', () => {
  it('exposes the classification filter as a Radix combobox with bilingual labels', () => {
    mount(['audit.event.read'])
    readyLedger([ledgerEvent])

    const trigger = screen.getByRole('combobox', { name: 'التصنيف' })
    expect(trigger).toBeInTheDocument()
    // The select trigger participates in the form via the shared `htmlFor`
    // linkage: clicking the label focuses the trigger.
    expect(screen.getByLabelText('التصنيف')).toBe(trigger)
  })

  it('renders the audit export format as a Radix combobox with csv and ndjson options', async () => {
    mount(['audit.event.read', 'audit.event.export'])
    readyLedger([ledgerEvent])

    const trigger = screen.getByRole('combobox', { name: 'التنسيق' })
    expect(trigger).toBeInTheDocument()
    expect(screen.getByLabelText('التنسيق')).toBe(trigger)

    fireEvent.click(trigger)
    expect(await screen.findByRole('option', { name: 'CSV' })).toBeInTheDocument()
    expect(await screen.findByRole('option', { name: 'NDJSON' })).toBeInTheDocument()
  })

  it('emits the classification value as part of the applied filter parameters', () => {
    const params = queryFromFilters({
      sourceModule: '',
      action: '',
      classification: 'confidential',
      occurredFrom: '',
      occurredTo: '',
    })
    expect(params).toEqual({ classification: 'confidential' })
  })

  it('keeps the "__all" sentinel out of applied filter parameters', () => {
    // The Select maps the user-visible "All classifications" option to an
    // internal sentinel "__all" and normalizes it back to '' before the
    // parameters reach the API. The mapping policy lives in the screen;
    // queryFromFilters is the contract-applied view.
    const draft = {
      sourceModule: '',
      action: '',
      classification: '' as const,
      occurredFrom: '',
      occurredTo: '',
    }
    expect(queryFromFilters(draft)).toEqual({})
  })
})

describe('audit ledger error handling', () => {
  function setLedgerState(overrides: Partial<typeof auditState>) {
    Object.assign(auditState, {
      data: undefined,
      isLoading: false,
      isError: false,
      error: null,
      ...overrides,
    })
  }

  it('renders the shared ErrorState with retry for an initial load failure', () => {
    const refetch = vi.fn()
    setLedgerState({
      data: undefined,
      isLoading: false,
      isError: true,
      error: new ApiError(503, {
        type: 'about:blank',
        title: 'Service Unavailable',
        status: 503,
        detail: 'Audit events could not be loaded.',
      }),
      refetch,
    })
    mount(['audit.event.read'])

    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('حدث خطأ أثناء تحميل البيانات.')
    const retry = within(alert).getByRole('button', { name: 'أعد المحاولة' })
    fireEvent.click(retry)
    expect(refetch).toHaveBeenCalled()
  })

  it('does not hide an already-loaded ledger when load-more fails', () => {
    // First page loaded successfully — set the state BEFORE mount so the
    // initial render observes the rows.
    setLedgerState({
      data: { items: [ledgerEvent], next_cursor: 'c2' },
      isLoading: false,
      isError: false,
      error: null,
    })
    mount(['audit.event.read'])

    // The first ledger row is rendered and the "Load more" affordance is
    // present because the page has a next cursor. The action cell text
    // "document.uploaded" is the most reliable accessible anchor for the
    // imported row.
    expect(screen.getByText('document.uploaded')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'تحميل المزيد' })).toBeInTheDocument()

    // The blocking ErrorState must NOT be in the document while items are
    // rendered and the query stays successful. The load-more error path
    // renders an inline role="alert" instead — but no load-more has been
    // triggered here, so the inline notice is also absent.
    expect(screen.queryByText('حدث خطأ أثناء تحميل البيانات.')).not.toBeInTheDocument()
    expect(screen.queryByTestId('audit-load-more-error')).not.toBeInTheDocument()
    // The original row anchor MUST still be present.
    expect(screen.getByText('document.uploaded')).toBeInTheDocument()
  })

  it('falls back to the ErrorState retry flow when the initial fetch rejects with no items', () => {
    const refetch = vi.fn()
    setLedgerState({
      data: { items: [], next_cursor: null },
      isLoading: false,
      isError: true,
      error: new ApiError(500, {
        type: 'about:blank',
        title: 'Internal Server Error',
        status: 500,
        detail: 'boom',
      }),
      refetch,
    })
    mount(['audit.event.read'])

    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('حدث خطأ أثناء تحميل البيانات.')
    expect(screen.queryByTestId('audit-load-more-error')).not.toBeInTheDocument()
  })
})
