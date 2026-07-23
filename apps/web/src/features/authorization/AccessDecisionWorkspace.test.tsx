// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, waitFor } from '@testing-library/react'

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

  it('renders the decision explanation panel with the loaded explanation when a decision is fetched successfully', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      jsonResponse(200, { explanation: 'Policy match: role.scope.ok' }) as never,
    )

    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" decisionId="01980f50-5f0d-7000-8000-000000000001" />
      </SessionProvider>,
    )

    const explanation = await screen.findByText('Policy match: role.scope.ok')
    expect(explanation).toBeTruthy()
    const panelHeading = document.getElementById('access-decision-panel')
    expect(panelHeading).not.toBeNull()
    expect(panelHeading?.closest('article')).not.toBeNull()
    expect(screen.getByText('01980f50-5f0d-7000-8000-000000000001')).toBeTruthy()
  })

  it('falls back to a JSON string when the response body has no "explanation" field', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
      jsonResponse(200, { decision: 'allow', action: 'read' }) as never,
    )

    render(
      <SessionProvider locale="en" session={session}>
        <AccessDecisionWorkspace locale="en" decisionId="01980f50-5f0d-7000-8000-000000000001" />
      </SessionProvider>,
    )

    await waitFor(() => {
      expect(document.getElementById('access-decision-panel')).not.toBeNull()
    })
    const panelHeading = document.getElementById('access-decision-panel')
    const article = panelHeading?.closest('article') as HTMLElement | null
    expect(article?.textContent ?? '').toContain('"decision"')
    expect(article?.textContent ?? '').toContain('"allow"')
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