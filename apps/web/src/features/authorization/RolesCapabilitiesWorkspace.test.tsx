// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../api/r1', () => ({ listAuthorization: vi.fn(() => new Promise(() => undefined)) }))

import { SessionProvider } from '../../app/session-context'
import { RolesCapabilitiesWorkspace } from './RolesCapabilitiesWorkspace'

describe('RolesCapabilitiesWorkspace', () => {
  afterEach(cleanup)

  it('renders both roles and capabilities panels together without nested tabs when both are visible', () => {
    render(
      <SessionProvider locale="en" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="en" capabilities={['authorization.role.read', 'authorization.capability.read']} />
      </SessionProvider>,
    )

    expect(screen.getByRole('heading', { name: 'Roles' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'Capabilities' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: /roles/i })).toBeNull()
    expect(screen.queryByRole('link', { name: /capabilities/i })).toBeNull()
  })

  it('shows only the roles panel when only the roles capability is granted', () => {
    render(
      <SessionProvider locale="en" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="en" capabilities={['authorization.role.read']} />
      </SessionProvider>,
    )

    expect(screen.getByRole('heading', { name: 'Roles' })).toBeTruthy()
    expect(screen.queryByRole('heading', { name: 'Capabilities' })).toBeNull()
  })

  it('shows only the capabilities panel when only the capabilities capability is granted', () => {
    render(
      <SessionProvider locale="en" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="en" capabilities={['authorization.capability.read']} />
      </SessionProvider>,
    )

    expect(screen.queryByRole('heading', { name: 'Roles' })).toBeNull()
    expect(screen.getByRole('heading', { name: 'Capabilities' })).toBeTruthy()
  })

  it('renders Arabic labels for the roles and capabilities panels', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <RolesCapabilitiesWorkspace locale="ar" capabilities={['authorization.role.read', 'authorization.capability.read']} />
      </SessionProvider>,
    )

    expect(screen.getByRole('heading', { name: 'الأدوار' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'الصلاحيات' })).toBeTruthy()
  })
})
