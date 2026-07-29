// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { PlatformSettingsLayout, type PlatformWorkspaceSection } from './PlatformSettingsLayout'

afterEach(cleanup)

function renderLayout({
  section,
  capabilities,
  navigate = vi.fn(),
}: {
  section: PlatformWorkspaceSection
  capabilities: readonly string[] | null
  navigate?: (path: string) => void
}) {
  return render(
    <PlatformSettingsLayout
      locale="ar"
      section={section}
      capabilities={capabilities}
      navigate={navigate}
    >
      <p>محتوى القسم</p>
    </PlatformSettingsLayout>,
  )
}

describe('PlatformSettingsLayout', () => {
  it('renders a semantic internal navigation with the active section', () => {
    renderLayout({
      section: 'calendars',
      capabilities: ['platform_settings.calendar.read', 'platform_operations.health.read'],
    })

    const navigation = screen.getByRole('navigation', { name: 'أقسام إعدادات المنصة' })
    expect(navigation).toBeTruthy()
    expect(screen.getByRole('link', { name: 'تقويم العمل' }).getAttribute('aria-current')).toBe('page')
    expect(screen.queryByRole('link', { name: 'النسخ الاحتياطي والاستعادة' })).toBeNull()
    expect(screen.getByText('محتوى القسم')).toBeTruthy()
    expect(screen.getByRole('region', { name: 'تقويم العمل' })).toBeTruthy()
    expect(screen.queryByRole('article', { name: 'تقويم العمل' })).toBeNull()
  })

  it('fails closed while capabilities are unresolved', () => {
    renderLayout({ section: 'overview', capabilities: null })

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText('محتوى القسم')).toBeTruthy()
  })

  it('shows API reference as a technical platform section only to its capability holder', () => {
    renderLayout({
      section: 'overview',
      capabilities: ['platform_settings.read', 'authorization.audit.read'],
    })

    expect(screen.getByRole('link', { name: 'مرجع API' }).getAttribute('href')).toBe('/api-docs')
  })

  it('does not advertise API reference without its capability', () => {
    renderLayout({ section: 'overview', capabilities: ['platform_settings.read'] })

    expect(screen.queryByRole('link', { name: 'مرجع API' })).toBeNull()
  })
})