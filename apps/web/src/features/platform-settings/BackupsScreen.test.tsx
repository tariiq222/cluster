// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { BackupsScreen } from './BackupsScreen'
afterEach(cleanup)
describe('BackupsScreen', () => {
  it('does not disclose storage paths and reports idempotent backup progress', () => {
    render(<BackupsScreen locale="en" allowedActions={['platform_operations.backup.run']} />)
    expect(screen.queryByText(/s3:|\/var\/|credential/i)).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Run backup now' }))
    expect(screen.getByRole('status').textContent).toMatch(/idempotency key/i)
  })
})
