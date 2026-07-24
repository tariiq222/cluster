// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { PersonalSecurity } from './PersonalSecurity'

const testSession = vi.hoisted(() => ({ locale: 'en' as 'ar' | 'en', token: 'test-token' }))

vi.mock('../../app/session-context', () => ({
  useLocale: () => testSession.locale,
  useToken: () => testSession.token,
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
  testSession.locale = 'en'
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
  it('marks the invalid password field for assistive technology', async () => {
    render(<PersonalSecurity />)

    fill(/Current password/, 'old-password-value')
    fill(/^New password/, 'short')
    fill(/Confirm new password/, 'short')
    submit()

    await screen.findByRole('alert')
    expect(screen.getByLabelText(/^New password/).getAttribute('aria-invalid')).toBe('true')
    expect(screen.getByLabelText(/Confirm new password/).getAttribute('aria-invalid')).toBe('false')
  })

  it('renders server failures in Arabic when the session locale is Arabic', async () => {
    testSession.locale = 'ar'
    changeIdentityPassword.mockRejectedValue(new Error('expired'))
    render(<PersonalSecurity />)

    fill(/كلمة المرور الحالية/, 'old-password-value')
    fill(/^كلمة المرور الجديدة/, 'a-long-enough-passphrase')
    fill(/تأكيد كلمة المرور الجديدة/, 'a-long-enough-passphrase')
    fireEvent.click(screen.getByRole('button', { name: 'حفظ كلمة المرور' }))

    expect((await screen.findByRole('alert')).textContent).toContain('تعذر تغيير كلمة المرور')
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
