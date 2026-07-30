// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen, waitFor } from '@testing-library/react'

import { ApiError } from '../../api'
import { PermissionDecisionInspector } from './PermissionDecisionInspector'

const explainAccessDecision = vi.fn()

vi.mock('../../app/session-context', () => ({ useToken: () => 'test-token' }))
vi.mock('../../api/r1', () => ({
  explainAccessDecision: (...args: unknown[]) => explainAccessDecision(...args),
  simulateAccessDecision: vi.fn(),
}))

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('PermissionDecisionInspector deep links', () => {
  it('loads and renders the saved decision without submitting the simulator form', async () => {
    explainAccessDecision.mockResolvedValue({
      decision_id: 'decision-7', decision: 'allow', action: 'records.read', resource_type: 'records',
      reason_codes: ['records.read.granted'], evaluated_at: '2026-07-30T00:00:00Z',
      applies_in_plain_language: 'A finance officer may view records in scope North.',
    })

    render(<PermissionDecisionInspector locale="en" decisionId="decision-7" />)

    await waitFor(() => expect(explainAccessDecision).toHaveBeenCalledWith('decision-7', 'test-token'))
    expect(screen.getByText('A finance officer may view records in scope North.')).toBeTruthy()
    expect(screen.getByText('records.read.granted')).toBeTruthy()
  })

  it('renders the typed API error from a deep-link lookup', async () => {
    explainAccessDecision.mockRejectedValue(new ApiError(403, {
      type: 'https://example.test/problems/forbidden', title: 'Forbidden', status: 403, detail: 'You cannot inspect this decision.',
    }))

    render(<PermissionDecisionInspector locale="en" decisionId="decision-7" />)

    expect((await screen.findByRole('alert')).textContent).toContain('You cannot inspect this decision.')
  })
})
