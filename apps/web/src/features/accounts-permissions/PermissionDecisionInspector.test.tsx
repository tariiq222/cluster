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

const UUID_PATTERN = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i

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

  it('renders contract-backed role and policy summaries without exposing UUIDs', async () => {
    explainAccessDecision.mockResolvedValue({
      decision_id: 'decision-9', decision: 'allow', action: 'records.read', resource_type: 'records',
      reason_codes: ['records.read.granted'], evaluated_at: '2026-07-30T00:00:00Z',
      applies_in_plain_language: 'A finance officer may view records in scope North.',
      assignment_summaries: [
        { role_code: 'finance_officer', effective_status: 'active', scope_type: 'facility' },
        { role_code: 'records_reviewer', effective_status: 'active', scope_type: 'organization_unit', scope_id: '019fb028-87db-7171-814a-4ba8b24e937a' },
      ],
      policy_references: [
        { policy_code: 'records.classification', policy_version: '2026-Q3', excerpt: 'Field-level redaction applies.' },
        { policy_code: 'records.access_window', policy_version: '2026-Q2' },
      ],
    })

    const { container } = render(<PermissionDecisionInspector locale="en" decisionId="decision-9" />)

    await waitFor(() => expect(explainAccessDecision).toHaveBeenCalledWith('decision-9', 'test-token'))
    const result = await screen.findByRole('region', { name: /decision result/i })
    const text = result.textContent ?? ''
    expect(text).toContain('finance_officer')
    expect(text).toContain('records_reviewer')
    expect(text).toContain('records.classification 2026-Q3')
    expect(text).toContain('records.access_window 2026-Q2')
    expect(text).not.toMatch(UUID_PATTERN)
    expect(container.querySelectorAll('[data-testid="assignment-summary"]')).toHaveLength(2)
    expect(container.querySelectorAll('[data-testid="policy-reference"]')).toHaveLength(2)
  })
})
