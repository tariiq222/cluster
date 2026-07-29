// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { BusinessCalendarsScreen } from './BusinessCalendarsScreen'

afterEach(cleanup)
afterEach(() => vi.restoreAllMocks())

describe('BusinessCalendarsScreen', () => {
  it('renders the platform settings calendar empty state', () => {
    render(
      <BusinessCalendarsScreen
        locale="ar"
        allowedActions={['platform_settings.calendar.read']}
        state="empty"
      />,
    )
    expect(screen.getByText('لا يوجد تقويم في هذا النطاق')).toBeTruthy()
  })
})
