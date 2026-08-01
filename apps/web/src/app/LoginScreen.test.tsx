// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
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
})
