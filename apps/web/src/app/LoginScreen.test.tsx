// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { LoginScreen } from './LoginScreen'

function mount(overrides: Partial<Parameters<typeof LoginScreen>[0]> = {}) {
  return render(
    <LoginScreen
      locale="ar"
      setLocale={() => {}}
      onLogin={async () => {}}
      {...overrides}
    />,
  )
}

const seededPersonaUsernames = [
  'platform-admin',
  'w13-e2e-account-a',
  'w13-e2e-account-b',
] as const

describe('login screen', () => {
  it('labels both credential fields in Arabic', () => {
    const { getByLabelText } = mount()
    expect(getByLabelText('اسم المستخدم')).toBeTruthy()
    expect(getByLabelText('كلمة المرور')).toBeTruthy()
  })

  it('offers a language switch', () => {
    const { getByRole } = mount()
    expect(getByRole('button', { name: /English/i })).toBeTruthy()
  })

  it('uses no directional utility classes', () => {
    const { container } = mount()
    expect(container.innerHTML).not.toMatch(/\b(ml|mr|pl|pr)-\d/)
    expect(container.innerHTML).not.toMatch(/\btext-(left|right)\b/)
  })

  it('renders the empty-submit error in an alert under the fields', async () => {
    const { getByRole, findAllByRole } = mount()
    fireEvent.submit(getByRole('form', { name: 'تسجيل الدخول' }))
    const alerts = await findAllByRole('alert')
    expect(alerts[0]!.textContent).toContain('حدث خطأ غير متوقع.')
  })

  it('renders the field error inside the field group, not as a page-level alert', async () => {
    const { getByRole, findAllByRole, container } = mount()
    fireEvent.submit(getByRole('form', { name: 'تسجيل الدخول' }))
    const alerts = await findAllByRole('alert')
    expect(alerts[0]!.closest('[data-slot="form-item"]')).not.toBeNull()
    expect(container.firstElementChild?.querySelector('[data-slot="card"]')).not.toBeNull()
  })

  it('keeps the session-expiry notice out of the login markup — it belongs to the toast layer', () => {
    const { container } = mount()
    expect(container.textContent).not.toContain('انتهت الجلسة')
  })

  it('renders the development account chooser heading and instruction in Arabic', () => {
    const { getByRole, getByText } = mount()
    expect(getByRole('heading', { level: 2, name: 'حسابات التطوير' })).toBeTruthy()
    expect(getByText('اختر حساباً لتعبئة بيانات الدخول.')).toBeTruthy()
    expect(getByText(/بيانات اختبار مهيّأة/).textContent).toMatch(/اختبار/)
  })

  it('renders every seeded persona button with a localized role label and the username in mono LTR', () => {
    const { getByRole } = mount()
    for (const username of seededPersonaUsernames) {
      const button = getByRole('button', { name: new RegExp(username) })
      expect(button.getAttribute('type')).toBe('button')
      expect(button.className.split(/\s+/)).toContain('h-11')
      expect(button.className.split(/\s+/)).toContain('w-full')
      const usernameNode = button.querySelector('[dir="ltr"]')
      expect(usernameNode).not.toBeNull()
      expect(usernameNode!.className.split(/\s+/)).toContain('font-mono')
      expect(usernameNode!.textContent).toBe(username)
      expect(button.getAttribute('data-persona-username')).toBe(username)
    }
  })

  it('keeps the password input obscured as type="password" after selecting a persona', () => {
    const { getByLabelText, getByRole } = mount()
    const passwordInput = getByLabelText('كلمة المرور', { exact: true }) as HTMLInputElement
    expect(passwordInput.type).toBe('password')
    fireEvent.click(getByRole('button', { name: /w13-e2e-account-a/ }))
    expect(passwordInput.type).toBe('password')
    expect(passwordInput.value.length).toBeGreaterThan(0)
  })

  it('does not surface any password anywhere in the persona button (no text, data, title, or aria-label)', () => {
    const { getByRole, container } = mount()
    const button = getByRole('button', { name: /w13-e2e-account-a/ })
    expect(button.textContent).not.toMatch(/River/i)
    expect(button.textContent).not.toMatch(/Cedar/i)
    expect(button.textContent).not.toMatch(/Admin!Cluster/i)
    expect(button.textContent).not.toMatch(/Orbit/i)
    expect(button.textContent).not.toMatch(/Quartz/i)
    expect(button.textContent).not.toMatch(/Harbor/i)
    expect(button.getAttribute('title')).toBeNull()
    const ariaLabel = button.getAttribute('aria-label')
    if (ariaLabel !== null) {
      expect(ariaLabel).not.toMatch(/River|Cedar|Admin|Orbit|Quartz|Harbor/i)
    }
    expect(button.getAttribute('data-password')).toBeNull()
    expect(button.getAttribute('data-credentials')).toBeNull()
    expect(button.getAttribute('data-secret')).toBeNull()
    expect(container.innerHTML).not.toMatch(/River/i)
    expect(container.innerHTML).not.toMatch(/Cedar/i)
    expect(container.innerHTML).not.toMatch(/Admin!Cluster/i)
    expect(container.innerHTML).not.toMatch(/Orbit/i)
    expect(container.innerHTML).not.toMatch(/Quartz/i)
    expect(container.innerHTML).not.toMatch(/Harbor/i)
  })

  it('fills the username and password without auto-submitting when a persona is selected', () => {
    const onLogin = vi.fn(async () => {})
    const { getByLabelText, getByRole } = mount({ onLogin })
    const usernameInput = getByLabelText('اسم المستخدم', { exact: true }) as HTMLInputElement
    const passwordInput = getByLabelText('كلمة المرور', { exact: true }) as HTMLInputElement
    fireEvent.click(getByRole('button', { name: /w13-e2e-account-a/ }))
    expect(usernameInput.value).toBe('w13-e2e-account-a')
    expect(passwordInput.value.length).toBeGreaterThan(0)
    expect(onLogin).not.toHaveBeenCalled()
  })

  it('resets password visibility to hidden and clears stale errors when a persona is selected', () => {
    const { getByLabelText, getByRole, findAllByRole } = mount()
    const passwordInput = getByLabelText('كلمة المرور', { exact: true }) as HTMLInputElement
    fireEvent.click(getByRole('button', { name: /إظهار كلمة المرور/ }))
    expect(passwordInput.type).toBe('text')
    fireEvent.submit(getByRole('form', { name: 'تسجيل الدخول' }))
    void findAllByRole('alert')
    fireEvent.click(getByRole('button', { name: /w13-e2e-account-a/ }))
    expect(passwordInput.type).toBe('password')
  })

  it('calls onLogin with the selected persona credentials only after the user presses Sign in', async () => {
    const onLogin = vi.fn(async (_username: string, password: string) => {
      expect(password.length).toBeGreaterThan(0)
    })
    const { getByRole } = mount({ onLogin })
    fireEvent.click(getByRole('button', { name: /w13-e2e-account-a/ }))
    expect(onLogin).not.toHaveBeenCalled()
    fireEvent.click(getByRole('button', { name: 'تسجيل الدخول' }))
    expect(onLogin).toHaveBeenCalledTimes(1)
    expect(onLogin.mock.calls[0]![0]).toBe('w13-e2e-account-a')
    expect(onLogin.mock.calls[0]![1].length).toBeGreaterThan(0)
  })

  it('renders the chooser heading and instruction in English when locale is en', () => {
    const { getByRole, getByText } = mount({ locale: 'en' })
    expect(getByRole('heading', { level: 2, name: 'Development accounts' })).toBeTruthy()
    expect(getByText('Choose an account to fill the sign-in fields.')).toBeTruthy()
  })

  it('renders English persona buttons with English role labels', () => {
    const { getByRole } = mount({ locale: 'en' })
    expect(getByRole('button', { name: /Platform administrator.*platform-admin/ })).toBeTruthy()
    expect(getByRole('button', { name: /R1 operator.*w13-e2e-account-a/ })).toBeTruthy()
    expect(getByRole('button', { name: /R1 operator.*w13-e2e-account-b/ })).toBeTruthy()
  })
})
