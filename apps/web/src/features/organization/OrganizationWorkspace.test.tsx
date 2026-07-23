// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { SessionProvider } from '../../app/session-context'
import { OrganizationWorkspace } from './OrganizationWorkspace'

describe('OrganizationWorkspace', () => {
  afterEach(cleanup)

  it('renders only the facilities and structure workspace tabs', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRouteName="organization"
          navigate={vi.fn()}
          capabilities={['organization.facility.read', 'organization.unit.read']}
        />
      </SessionProvider>,
    )

    expect(screen.getAllByRole('link')).toHaveLength(2)
    expect(screen.getByRole('link', { name: 'الملخص' }).getAttribute('href')).toBe('/admin/organization')
    expect(screen.getByRole('link', { name: 'الهيكل التنظيمي' }).getAttribute('href')).toBe('/admin/organization/structure')
    expect(screen.queryByRole('link', { name: 'الموظفون' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'التكليفات المؤقتة' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'إضافة من ملف' })).toBeNull()
  })

  it('withholds the structure tab unless its unit-read capability is present', () => {
    const { getByRole, queryByRole } = render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRouteName="organization"
          navigate={vi.fn()}
          capabilities={['organization.facility.read']}
        />
      </SessionProvider>,
    )

    expect(getByRole('link', { name: 'الملخص' })).toBeTruthy()
    expect(queryByRole('link', { name: 'الهيكل التنظيمي' })).toBeNull()
  })

  it('falls back to the structure tab for a unit-only principal instead of rendering facilities', () => {
    const { getByRole, queryByRole, queryByText } = render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRouteName="organization"
          navigate={vi.fn()}
          capabilities={['organization.unit.read']}
        />
      </SessionProvider>,
    )

    expect(queryByRole('link', { name: 'الملخص' })).toBeNull()
    expect(getByRole('link', { name: 'الهيكل التنظيمي' })).toBeTruthy()
    expect(getByRole('heading', { name: 'الهيكل التنظيمي' })).toBeTruthy()
    expect(queryByText('منشآت التجمع')).toBeNull()
  })
})
