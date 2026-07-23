// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { PlatformSettingsLayout } from './PlatformSettingsLayout'

afterEach(cleanup)

describe('PlatformSettingsLayout', () => {
  it('renders a semantic internal navigation with the active section', () => {
    render(
      <PlatformSettingsLayout
        locale="ar"
        section="calendars"
        capabilities={['platform_settings.calendar.read', 'platform_operations.health.read']}
        navigate={vi.fn()}
      >
        <p>محتوى التقويم</p>
      </PlatformSettingsLayout>,
    )

    const navigation = screen.getByRole('navigation', { name: 'أقسام إعدادات المنصة' })
    expect(navigation).toBeTruthy()
    expect(screen.getByRole('link', { name: 'تقويم العمل' }).getAttribute('aria-current')).toBe('page')
    expect(screen.queryByRole('link', { name: 'النسخ الاحتياطي والاستعادة' })).toBeNull()
    expect(screen.getByText('محتوى التقويم')).toBeTruthy()
  })

  it('fails closed while capabilities are unresolved', () => {
    render(
      <PlatformSettingsLayout locale="en" section="overview" capabilities={null} navigate={vi.fn()}>
        <p>Platform content</p>
      </PlatformSettingsLayout>,
    )

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByText('Platform content')).toBeTruthy()
  })
})
