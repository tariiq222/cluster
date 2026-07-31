// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { SessionProvider } from '../../app/session-context'
import { RolesCapabilitiesWorkspace } from './RolesCapabilitiesWorkspace'

function renderWorkspace(locale: 'ar' | 'en', capabilities: readonly string[] | null) {
  return render(
    <SessionProvider locale={locale} session={{ access_token: 'test-token' } as never}>
      <RolesCapabilitiesWorkspace locale={locale} capabilities={capabilities} />
    </SessionProvider>,
  )
}

describe('RolesCapabilitiesWorkspace', () => {
  afterEach(cleanup)

  it('redirects the legacy roles and capabilities surface to the current workspace without rendering a passive list', () => {
    renderWorkspace('en', ['authorization.role.read'])

    expect(screen.getByText('Roles and permissions are now managed in the Accounts & Permissions workspace.')).toBeTruthy()
    expect(document.querySelector('.data-list')).toBeNull()
  })

  it('localizes the retirement redirect', () => {
    renderWorkspace('ar', null)

    expect(screen.getByText('تُدار الأدوار والصلاحيات الآن في مساحة الحسابات والصلاحيات.')).toBeTruthy()
  })
})
