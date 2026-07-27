// @vitest-environment jsdom
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import * as auditApi from '../../api/audit'
import type {
  AuditEvent,
  AuditExportDescriptor,
  AuditIntegrityResult,
} from '../../api/audit'
import { AuditWorkspace } from './AuditWorkspace'

const token = 'csrf-token'
const eventId = '01980f50-5f0d-7000-8000-000000000801'
const correlationId = '01980f50-5f0d-7000-8000-000000000802'

const event: AuditEvent = {
  event_id: eventId,
  source_module: 'documents',
  action: 'document.uploaded',
  event_type: 'com.cluster.documents.documentuploaded.v1',
  actor_type: 'user',
  actor_id: '01980f50-5f0d-7000-8000-000000000803',
  original_actor_id: null,
  subject_type: 'document',
  subject_id: '01980f50-5f0d-7000-8000-000000000804',
  correlation_id: correlationId,
  outcome: 'succeeded',
  classification: 'confidential',
  context: {
    document_id: '01980f50-5f0d-7000-8000-000000000804',
    filename: '[REDACTED]',
  },
  occurred_at: '2026-07-27T08:00:00.000Z',
  recorded_at: '2026-07-27T08:00:00.125Z',
  access_decision_id: null,
  retention_until: '2033-07-27T08:00:00.000Z',
  integrity_status: 'verified',
  allowed_actions: [],
}

beforeEach(() => {
  vi.spyOn(auditApi, 'listAuditEvents').mockResolvedValue({
    items: [event],
    next_cursor: null,
  })
  vi.spyOn(auditApi, 'getAuditEvent').mockResolvedValue(event)
  Object.defineProperty(window, 'requestAnimationFrame', {
    configurable: true,
    value: (callback: FrameRequestCallback) => window.setTimeout(callback, 0),
  })
  Object.defineProperty(window, 'cancelAnimationFrame', {
    configurable: true,
    value: window.clearTimeout,
  })
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('AuditWorkspace', () => {
  it('loads a semantic ledger and shows only projected detail context', async () => {
    render(
      <AuditWorkspace
        locale="en"
        token={token}
        capabilities={['audit.event.read']}
      />,
    )

    expect(
      await screen.findByRole('heading', { name: 'Audit ledger' }),
    ).toBeTruthy()
    expect(await screen.findByRole('table')).toBeTruthy()
    expect(screen.getByText('document.uploaded')).toBeTruthy()

    const inspect = screen.getByRole('button', { name: 'Inspect event' })
    inspect.focus()
    inspect.click()

    expect(
      await screen.findByRole('dialog', { name: 'Audit event detail' }),
    ).toBeTruthy()
    expect(await screen.findByText('[REDACTED]')).toBeTruthy()
    expect(screen.queryByText('event_hash')).toBeNull()
    expect(
      screen.getByText(/Hashes, keys, and request fingerprints never enter/),
    ).toBeTruthy()

    screen.getByRole('button', { name: 'Close' }).click()
    await waitFor(() => expect(document.activeElement).toBe(inspect))
  })

  it('applies source, action, and classification filters through the API boundary', async () => {
    const listSpy = vi.mocked(auditApi.listAuditEvents)
    render(
      <AuditWorkspace
        locale="en"
        token={token}
        capabilities={['audit.event.read']}
      />,
    )
    await screen.findByText('document.uploaded')

    fireEvent.change(screen.getByLabelText('Source module'), {
      target: { value: 'authorization' },
    })
    fireEvent.change(screen.getByLabelText('Action'), {
      target: { value: 'authorization.decision.denied' },
    })
    fireEvent.change(screen.getByLabelText('Classification'), {
      target: { value: 'top_secret' },
    })
    screen.getByRole('button', { name: 'Apply filters' }).click()

    await waitFor(() =>
      expect(listSpy).toHaveBeenLastCalledWith(token, {
        source_module: 'authorization',
        action: 'authorization.decision.denied',
        classification: 'top_secret',
      }),
    )
  })

  it('hides export and integrity controls unless each capability is present', async () => {
    const { rerender } = render(
      <AuditWorkspace
        locale="en"
        token={token}
        capabilities={['audit.event.read']}
      />,
    )
    await screen.findByText('document.uploaded')
    expect(
      screen.queryByRole('heading', { name: 'Export snapshot' }),
    ).toBeNull()
    expect(
      screen.queryByRole('heading', { name: 'Verify stream integrity' }),
    ).toBeNull()

    rerender(
      <AuditWorkspace
        locale="en"
        token={token}
        capabilities={[
          'audit.event.read',
          'audit.event.export',
          'audit.integrity.verify',
        ]}
      />,
    )
    expect(
      screen.getByRole('heading', { name: 'Export snapshot' }),
    ).toBeTruthy()
    expect(
      screen.getByRole('heading', { name: 'Verify stream integrity' }),
    ).toBeTruthy()
  })

  it('creates a reason-bound export and validates integrity sequence pairs', async () => {
    const descriptor: AuditExportDescriptor = {
      id: '01980f50-5f0d-7000-8000-000000000811',
      principal_id: '01980f50-5f0d-7000-8000-000000000812',
      facility_id: null,
      query: {},
      format: 'csv',
      snapshot_recorded_at: '2026-07-27T08:00:00.000Z',
      status: 'ready',
      event_count: 1,
      expires_at: '2026-08-03T08:00:00.000Z',
      created_at: '2026-07-27T08:00:00.000Z',
    }
    const verification: AuditIntegrityResult = {
      stream_key: `documents:document:${event.subject_id}`,
      first_sequence: 1,
      last_sequence: 3,
      verified_event_count: 3,
      integrity_status: 'verified',
      checkpoint_id: '01980f50-5f0d-7000-8000-000000000813',
    }
    const exportSpy = vi
      .spyOn(auditApi, 'createAuditExport')
      .mockResolvedValue(descriptor)
    const verifySpy = vi
      .spyOn(auditApi, 'verifyAuditIntegrity')
      .mockResolvedValue(verification)
    render(
      <AuditWorkspace
        locale="en"
        token={token}
        capabilities={[
          'audit.event.read',
          'audit.event.export',
          'audit.integrity.verify',
        ]}
      />,
    )
    await screen.findByText('document.uploaded')

    fireEvent.change(screen.getByLabelText(/Export reason/), {
      target: { value: 'Quarterly compliance review' },
    })
    screen.getByRole('button', { name: 'Create export' }).click()
    await waitFor(() =>
      expect(exportSpy).toHaveBeenCalledWith(token, {
        format: 'csv',
        reason: 'Quarterly compliance review',
        filters: {},
      }),
    )
    expect(await screen.findByText('Export ready')).toBeTruthy()

    fireEvent.change(screen.getByLabelText(/Stream key/), {
      target: { value: verification.stream_key },
    })
    fireEvent.change(screen.getByLabelText(/First sequence/), {
      target: { value: '1' },
    })
    screen.getByRole('button', { name: 'Verify now' }).click()
    expect((await screen.findByRole('alert')).textContent).toContain(
      'First and last sequence must be supplied together.',
    )
    expect(verifySpy).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText(/Last sequence/), {
      target: { value: '3' },
    })
    screen.getByRole('button', { name: 'Verify now' }).click()
    await waitFor(() =>
      expect(verifySpy).toHaveBeenCalledWith(token, {
        stream_key: verification.stream_key,
        first_sequence: 1,
        last_sequence: 3,
      }),
    )
    expect(await screen.findByText('Verification complete')).toBeTruthy()
  })

  it('renders the Arabic workspace and control labels', async () => {
    render(
      <AuditWorkspace
        locale="ar"
        token={token}
        capabilities={['audit.event.read', 'audit.event.export']}
      />,
    )

    expect(
      await screen.findByRole('heading', { name: 'سجل التدقيق' }),
    ).toBeTruthy()
    expect(screen.getByRole('button', { name: 'تطبيق المرشحات' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'تصدير لقطة' })).toBeTruthy()
  })
})
