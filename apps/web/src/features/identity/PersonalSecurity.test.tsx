// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { PersonalSecurity } from './PersonalSecurity'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'en',
  useToken: () => 'test-token',
}))

const changeIdentityPassword = vi.fn()

vi.mock('../../api', () => ({
  changeIdentityPassword: (...args: unknown[]) => changeIdentityPassword(...args),
}))

function fill(label: RegExp, value: string) {
  fireEvent.change(screen.getByLabelText(label), { target: { value } })
}

function submit() {
  fireEvent.click(screen.getByRole('button', { name: 'Save password' }))
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('Personal security screen', () => {
  it('carries only the signed-in user’s own password, not anyone else’s account', () => {
    render(<PersonalSecurity />)

    expect(screen.getByRole('heading', { name: 'Security and password' })).toBeTruthy()
    expect(screen.queryByLabelText(/Employee/)).toBeNull()
  })

  it('names the length rule before sending anything to the server', async () => {
    render(<PersonalSecurity />)

    fill(/Current password/, 'old-password-value')
    fill(/^New password/, 'short')
    fill(/Confirm new password/, 'short')
    submit()

    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      'The new password is too short — use at least 14 characters.',
    )
    expect(changeIdentityPassword).not.toHaveBeenCalled()
  })

  it('separates a mismatched confirmation from a rejected password', async () => {
    render(<PersonalSecurity />)

    fill(/Current password/, 'old-password-value')
    fill(/^New password/, 'a-long-enough-passphrase')
    fill(/Confirm new password/, 'a-different-passphrase')
    submit()

    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      'The two entries do not match. Check the confirmation.',
    )
    expect(changeIdentityPassword).not.toHaveBeenCalled()
  })

  it('warns that the session ends once the password is accepted', async () => {
    changeIdentityPassword.mockResolvedValue(undefined)
    render(<PersonalSecurity />)

    fill(/Current password/, 'old-password-value')
    fill(/^New password/, 'a-long-enough-passphrase')
    fill(/Confirm new password/, 'a-long-enough-passphrase')
    submit()

    expect(await screen.findByRole('status')).toHaveProperty(
      'textContent',
      'Password changed. You will be asked to sign in again.',
    )
    await waitFor(() => {
      expect((screen.getByLabelText(/^New password/) as HTMLInputElement).value).toBe('')
    })
  })
})
