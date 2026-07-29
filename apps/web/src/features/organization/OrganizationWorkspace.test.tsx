// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { SessionProvider } from '../../app/session-context'
import { OrganizationWorkspace } from './OrganizationWorkspace'

describe('OrganizationWorkspace', () => {
  afterEach(cleanup)

  it('renders the three consolidated section tabs in Arabic for a fully-capable principal', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRoute={{ name: 'organization' }}
          navigate={vi.fn()}
          capabilities={[
            'organization.facility.read',
            'organization.unit.read',
            'organization.person.read',
          ]}
        />
      </SessionProvider>,
    )

    expect(
      screen.getByRole('link', { name: 'المنشآت والهيكل التنظيمي' }),
    ).toBeTruthy()
    expect(
      screen.getByRole('link', { name: 'الموظفون والتكليفات الوظيفية' }),
    ).toBeTruthy()
    expect(
      screen.getByRole('link', { name: 'العلاقات الإشرافية' }),
    ).toBeTruthy()
    expect(
      screen.queryByRole('link', { name: 'التكليفات المؤقتة' }),
    ).toBeNull()
    expect(
      screen.queryByRole('link', { name: 'استيراد البيانات' }),
    ).toBeNull()
  })

  it('does not surface the employee import as a separate top-level tab', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRoute={{ name: 'organization-import' }}
          navigate={vi.fn()}
          capabilities={['organization.facility.read', 'organization.unit.read', 'organization.import.read']}
        />
      </SessionProvider>,
    )

    expect(
      screen.queryByRole('link', { name: 'استيراد البيانات' }),
    ).toBeNull()
  })

  it('renders only the facilities-and-structure tab for a facilities-only principal', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRoute={{ name: 'organization' }}
          navigate={vi.fn()}
          capabilities={['organization.facility.read']}
        />
      </SessionProvider>,
    )

    expect(
      screen.getByRole('link', { name: 'المنشآت والهيكل التنظيمي' }),
    ).toBeTruthy()
    expect(
      screen.queryByRole('link', { name: 'الموظفون والتكليفات الوظيفية' }),
    ).toBeNull()
    expect(
      screen.queryByRole('link', { name: 'العلاقات الإشرافية' }),
    ).toBeNull()
  })

  it('renders the employees section tab when person-read is present even without facility/unit capability', () => {
    render(
      <SessionProvider locale="ar" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="ar"
          activeRoute={{ name: 'people-assignments' }}
          navigate={vi.fn()}
          capabilities={['organization.person.read']}
        />
      </SessionProvider>,
    )

    expect(
      screen.getByRole('link', { name: 'الموظفون والتكليفات الوظيفية' }),
    ).toBeTruthy()
  })

  it('renders the English labels with the consolidated copy', () => {
    render(
      <SessionProvider locale="en" session={{ access_token: 'test-token' } as never}>
        <OrganizationWorkspace
          locale="en"
          activeRoute={{ name: 'organization' }}
          navigate={vi.fn()}
          capabilities={[
            'organization.facility.read',
            'organization.unit.read',
            'organization.person.read',
          ]}
        />
      </SessionProvider>,
    )

    expect(screen.getByRole('link', { name: 'Facilities and structure' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Employees and job assignments' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Supervisory relationships' })).toBeTruthy()
  })
})