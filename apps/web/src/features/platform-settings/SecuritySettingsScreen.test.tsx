// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { SecuritySettingsScreen } from './SecuritySettingsScreen'
afterEach(cleanup)
describe('SecuritySettingsScreen', () => {
  it('uses RTL Arabic and gates publication with server actions', () => {
    const { container, rerender } = render(<SecuritySettingsScreen locale="ar" allowedActions={[]} />)
    expect(screen.queryByRole('button', { name: 'نشر الإعدادات' })).toBeNull()
    expect(container.querySelector('button')).toBeNull()
    rerender(<SecuritySettingsScreen locale="en" allowedActions={['platform_settings.publish']} />)
    expect(screen.getByRole('button', { name: 'Publish settings' })).toBeTruthy()
  })
})
