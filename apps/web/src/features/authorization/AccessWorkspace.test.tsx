// @vitest-environment jsdom

import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { AccessWorkspace } from './AccessWorkspace'
import { SessionProvider } from '../../app/session-context'
import type { Session } from '../../api'
import type { AppRoute } from '../../shell/routes'

const session: Session = {
  csrf_token: 'csrf-token',
  access_token: 'csrf-token',
  user_id: '018f6f7d-0c00-7000-8000-000000000021',
  expires_at: '2026-07-17T12:00:00Z',
  restricted: false,
  principal: { user_id: '018f6f7d-0c00-7000-8000-000000000021' },
}

function renderRoute(activeRoute: AppRoute) {
  return render(
    <SessionProvider locale="en" session={session}>
      <AccessWorkspace locale="en" activeRoute={activeRoute} navigate={vi.fn()} />
    </SessionProvider>,
  )
}

describe('AccessWorkspace decision route', () => {
  it('opens the access-decision simulator from the test-decision tab', () => {
    renderRoute({ name: 'access-explanation' })

    expect(screen.getByRole('heading', { name: 'Access decision simulator' })).toBeTruthy()
    expect(screen.getByRole('textbox', { name: /Server-provided simulation request \(JSON\)/ })).toBeTruthy()
  })

  it('keeps decision audit deep links on the explanation lookup', () => {
    renderRoute({ name: 'access-explanation', decisionId: 'decision-7' })

    expect(screen.getByRole('heading', { name: 'Audit and explanation view' })).toBeTruthy()
    expect((screen.getByLabelText('Decision ID') as HTMLInputElement).value).toBe('decision-7')
  })
})
