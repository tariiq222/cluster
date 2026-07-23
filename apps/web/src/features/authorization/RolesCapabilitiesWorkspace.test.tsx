// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../api/r1', () => ({ listAuthorization: vi.fn(() => new Promise(() => undefined)) }))

import { SessionProvider } from '../../app/session-context'
import { RolesCapabilitiesWorkspace } from './RolesCapabilitiesWorkspace'

describe('RolesCapabilitiesWorkspace', () => {
  afterEach(cleanup)

  it('renders exactly roles and capabilities as workspace tabs', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="ar" activeResource="roles" navigate={vi.fn()} capabilities={['authorization.role.read', 'authorization.capability.read']} />
      </SessionProvider>,
    )

    expect(screen.getAllByRole('link', { name: /الأدوار|الصلاحيات/ })).toHaveLength(2)
    expect(screen.getByRole('link', { name: 'الصلاحيات' })).toBeTruthy()
  })

  it('shows each authorization tab only to its own capability', () => {
    const { rerender, getByRole, queryByRole } = render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="ar" activeResource="roles" navigate={vi.fn()} capabilities={['authorization.role.read']} />
      </SessionProvider>,
    )

    expect(getByRole('link', { name: 'الأدوار' })).toBeTruthy()
    expect(queryByRole('link', { name: 'الصلاحيات' })).toBeNull()

    rerender(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="ar" activeResource="capabilities" navigate={vi.fn()} capabilities={['authorization.capability.read']} />
      </SessionProvider>,
    )
    expect(queryByRole('link', { name: 'الأدوار' })).toBeNull()
    expect(getByRole('link', { name: 'الصلاحيات' })).toBeTruthy()
  })
})
