// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { PlatformOverviewScreen } from './PlatformOverviewScreen'
import { platformSettingsMockFor } from './PlatformSettingsMockData'

afterEach(cleanup)

describe('PlatformOverviewScreen', () => {
  it('renders the Arabic control-center order and never offers maintenance as a quick action', () => {
    render(<PlatformOverviewScreen locale="ar" />)

    expect(screen.getByRole('heading', { name: 'الإجراء المطلوب' })).toBeTruthy()
    expect(screen.getByText('الخدمات')).toBeTruthy()
    expect(screen.getByText('آخر نسخة')).toBeTruthy()
    expect(screen.queryByRole('button', { name: /صيانة/i })).toBeNull()
  })

  it('renders the server-shaped action projection supplied by the local adapter', () => {
    const screenData = platformSettingsMockFor('overview', ['platform_operations.health.read'])
    render(<PlatformOverviewScreen locale="en" {...screenData} />)

    expect(screen.getByRole('button', { name: 'Refresh check' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Run backup now' })).toBeNull()
  })
})
