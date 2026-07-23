// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { MaintenanceScreen } from './MaintenanceScreen'
afterEach(cleanup)
describe('MaintenanceScreen', () => {
  it('explains impact and hides maintenance mutations until allowed', () => {
    render(<MaintenanceScreen locale="ar" allowedActions={[]} />)
    expect(screen.getByText(/رسالة ثنائية اللغة/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'إنشاء نافذة صيانة' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'إلغاء النافذة' })).toBeNull()
  })
})
