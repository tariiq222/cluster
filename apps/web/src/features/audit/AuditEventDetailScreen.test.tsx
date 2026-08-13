// @vitest-environment jsdom
import type { ReactNode } from 'react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { SessionProvider } from '../../app/session-context'
import { AuditEventDetailScreen } from './AuditEventDetailScreen'
import { ApiError } from '../../api/http'

/*
 * The detail Sheet moved to the full page (route
 * `/reports/audit/events/:eventId`):
 *   - the event is fetched by id directly (GET /audit/events/{id})
 *   - hash / key / fingerprint / secret context is redacted recursively and
 *     never reaches the DOM
 *   - without audit.event.read the page renders the shared non-disclosing
 *     DeniedState and never fetches
 *   - 403/404 → DeniedState; other failures → ErrorState with retry
 *   - keyboard-accessible back link to the audit ledger tab
 */

const navigateMock = vi.hoisted(() => vi.fn())
vi.mock('../../app/navigation-context', () => ({
  useNavigate: () => navigateMock,
}))

const principalState = vi.hoisted(() => ({ capabilities: [] as string[] }))

vi.mock('../../app/principal-context', () => ({
  usePrincipal: () => ({
    state: 'ready',
    capabilities: principalState.capabilities,
    features: { tasks: true },
    effectiveScope: null,
    availableScopes: [],
    revision: 0,
    scopeEpoch: 0,
    scopeReady: true,
    refresh: () => {},
    selectScope: async () => {},
  }),
}))

const EVENT_ID = '01980f50-5f0d-7000-8000-000000000905'

const eventFixture = {
  event_id: EVENT_ID,
  source_module: 'documents',
  action: 'document.uploaded',
  event_type: 'com.cluster.documents.documentuploaded.v1',
  actor_type: 'user',
  actor_id: null,
  original_actor_id: null,
  subject_type: 'document',
  subject_id: '01980f50-5f0d-7000-8000-000000000908',
  correlation_id: '01980f50-5f0d-7000-8000-000000000906',
  outcome: 'succeeded',
  classification: 'confidential',
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
  occurred_at: '2026-07-27T08:00:00.000Z',
  recorded_at: '2026-07-27T08:00:00.125Z',
  access_decision_id: null,
  retention_until: '2033-07-27T08:00:00.000Z',
  integrity_status: 'verified',
  allowed_actions: [],
}

const getAuditEventMock = vi.fn()
vi.mock('../../api/generated/cluster', () => ({
  getAuditEvent: (...args: unknown[]) => getAuditEventMock(...args),
}))

const session = {
  csrfToken: 'x',
  userId: 'u',
  expiresAt: '2026-12-31T00:00:00Z',
  restricted: false,
}

function mount(node: ReactNode) {
  cleanup()
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        {node}
      </SessionProvider>
    </QueryClientProvider>,
  )
}

function readyEvent() {
  getAuditEventMock.mockReset()
  getAuditEventMock.mockResolvedValue({
    status: 200,
    data: eventFixture,
    headers: new Headers(),
  })
}

beforeEach(() => {
  navigateMock.mockReset()
  principalState.capabilities = ['audit.event.read']
  readyEvent()
})

describe('audit event detail page — fetch by id', () => {
  it('loads the event by id and renders the full detail with correlation id', async () => {
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)

    await waitFor(() => expect(getAuditEventMock).toHaveBeenCalledTimes(1))
    expect(getAuditEventMock).toHaveBeenCalledWith(EVENT_ID, expect.any(Object))

    expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('تفاصيل الحدث')
    expect(screen.getByText('معرّف الارتباط')).toBeInTheDocument()
    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000906')).toBeInTheDocument()
    expect(screen.getByText('com.cluster.documents.documentuploaded.v1')).toBeInTheDocument()
    // The outcome / integrity / classification badges render.
    expect(screen.getByText('succeeded')).toBeInTheDocument()
    expect(screen.getByText('verified')).toBeInTheDocument()
    expect(screen.getByText('confidential')).toBeInTheDocument()
  })

  it('redacts hash, key, fingerprint and secret material recursively at any depth', async () => {
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)

    await screen.findByRole('heading', { level: 1 })
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

  it('renders the shared non-disclosing denied state without the read capability and never fetches', () => {
    principalState.capabilities = []
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)

    expect(screen.getByTestId('audit-event-detail-screen')).toBeInTheDocument()
    expect(screen.getByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
    expect(getAuditEventMock).not.toHaveBeenCalled()
  })

  it('collapses 403 and 404 into the shared non-disclosing denied state', async () => {
    getAuditEventMock.mockRejectedValue(
      new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }),
    )
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)
    expect(await screen.findByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()

    cleanup()
    getAuditEventMock.mockRejectedValue(
      new ApiError(404, { type: 'about:blank', title: 'Not Found', status: 404 }),
    )
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)
    expect(await screen.findByText('لا يمكن الوصول إلى هذا المحتوى.')).toBeInTheDocument()
  })

  it('renders the shared ErrorState with a retry that reloads the event', async () => {
    getAuditEventMock.mockRejectedValue(
      new ApiError(503, {
        type: 'about:blank',
        title: 'Service Unavailable',
        status: 503,
        detail: 'boom',
      }),
    )
    // A fresh QueryClient per mount keeps the rejection visible; the retry
    // control calls the screen's refetch, which re-runs the query fn.
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const { unmount } = render(
      <QueryClientProvider client={client}>
        <SessionProvider session={session} locale="ar" setLocale={() => {}}>
          <AuditEventDetailScreen eventId={EVENT_ID} />
        </SessionProvider>
      </QueryClientProvider>,
    )
    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('حدث خطأ أثناء تحميل البيانات.')
    fireEvent.click(within(alert).getByRole('button', { name: 'أعد المحاولة' }))
    await waitFor(() => expect(getAuditEventMock.mock.calls.length).toBeGreaterThan(1))
    unmount()
  })

  it('renders the loading skeleton while the event query is pending', () => {
    getAuditEventMock.mockImplementation(
      () => new Promise((resolve) => setTimeout(() => resolve({ status: 200, data: eventFixture, headers: new Headers() }), 50)),
    )
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)
    expect(screen.getByTestId('loading-state')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument()
  })

  it('offers a keyboard-accessible back link to the audit ledger tab', async () => {
    mount(<AuditEventDetailScreen eventId={EVENT_ID} />)
    await screen.findByRole('heading', { level: 1 })

    const back = screen.getByRole('button', { name: 'عودة إلى سجل التدقيق' })
    expect(back).toBeInTheDocument()
    fireEvent.click(back)
    expect(navigateMock).toHaveBeenCalledWith('/reports?tab=audit')
  })
})
