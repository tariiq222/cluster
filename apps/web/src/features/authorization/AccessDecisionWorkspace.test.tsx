// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { SessionProvider } from '../../app/session-context'
import type { Session } from '../../api'
import { AccessDecisionWorkspace } from './AccessDecisionWorkspace'

const session: Session = {
  csrf_token: 'csrf',
  access_token: 'csrf',
  user_id: '018f6f7d-0c00-7000-8000-000000000021',
  expires_at: '2026-07-17T12:00:00Z',
  restricted: false,
  principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
}

function decisionPayload(overrides: Record<string, unknown> = {}) {
  return {
    decision_id: '01980f50-5f0d-7000-8000-000000000001',
    decision: 'allow',
    action: 'read',
    resource_type: 'work_record',
    reason_codes: ['role.scope.ok'],
    evaluated_at: '2026-07-27T12:00:00Z',
    ...overrides,
  }
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

afterEach(() => { cleanup(); vi.restoreAllMocks() })

describe('AccessDecisionWorkspace', () => {
  beforeEach(() => {
    vi.spyOn(globalThis, 'fetch').mockReset()
  })

  it('renders the structured decision panel when a decision is fetched successfully', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      jsonResponse(200, { data: decisionPayload() }) as never,
    )

    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" decisionId="01980f50-5f0d-7000-8000-000000000001" />
      </SessionProvider>,
    )

    expect(await screen.findByText('allow — read (work_record)')).toBeTruthy()
    const panelHeading = document.getElementById('access-decision-panel')
    expect(panelHeading).not.toBeNull()
    expect(panelHeading?.closest('article')).not.toBeNull()
    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000001')).toBeTruthy()
  })

  it('renders joined reason codes and the evaluation timestamp', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      jsonResponse(200, {
        data: decisionPayload({ reason_codes: ['role.scope.ok', 'classification.match'] }),
      }) as never,
    )

    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" decisionId="01980f50-5f0d-7000-8000-000000000001" />
      </SessionProvider>,
    )

    expect(await screen.findByText('role.scope.ok, classification.match')).toBeTruthy()
    expect(screen.getByText('2026-07-27T12:00:00Z')).toBeTruthy()
  })

  it('shows the empty state when no decision id is provided', () => {
    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" />
      </SessionProvider>,
    )

    expect(screen.getByText('No decision id supplied.')).toBeTruthy()
  })

  it('shows a distinct denied (403) empty state when the API rejects with 403', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response('', { status: 403 }) as never,
    )

    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" decisionId="01980f50-5f0d-7000-8000-000000000001" />
      </SessionProvider>,
    )

    const deniedHeading = await screen.findByRole('heading', { name: '403' })
    expect(deniedHeading.id).toBe('access-decision-denied')
    expect(document.getElementById('access-decision-panel')).toBeNull()
  })

  it('shows a distinct error empty state when the API rejects with 404 (not the denied panel)', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      new Response('', { status: 404 }) as never,
    )

    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" decisionId="01980f50-5f0d-7000-8000-000000000001" />
      </SessionProvider>,
    )

    const errorAlert = await screen.findByRole('alert')
    expect(errorAlert.textContent ?? '').toContain('We could not load the decision.')
    expect(document.getElementById('access-decision-denied')).toBeNull()
    expect(document.getElementById('access-decision-panel')).toBeNull()
  })
})