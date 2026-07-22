// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { IdentityAccounts } from './IdentityAccounts'

vi.mock('../../app/session-context', () => ({
  useLocale: () => 'en',
  useToken: () => 'test-token',
}))

const listUserAccounts = vi.fn()
const listPeople = vi.fn()
const transitionUserAccount = vi.fn()
const issueIdentityActivation = vi.fn()
const createUserAccount = vi.fn()

vi.mock('../../api', () => ({
  ApiError: class ApiError extends Error { status = 0 },
  createUserAccount: (...args: unknown[]) => createUserAccount(...args),
  issueIdentityActivation: (...args: unknown[]) => issueIdentityActivation(...args),
  listPeople: (...args: unknown[]) => listPeople(...args),
  listUserAccounts: (...args: unknown[]) => listUserAccounts(...args),
  transitionUserAccount: (...args: unknown[]) => transitionUserAccount(...args),
  stateFromError: () => 'error',
}))

function account(overrides: Record<string, unknown> = {}) {
  return {
    id: 'account-1',
    person_id: 'person-1',
    username: 'n.alrashidi',
    display_name_ar: 'د. نوال الرشيدي',
    display_name_en: 'Dr. Nawal Alrashidi',
    status: 'active',
    must_change_password: false,
    ...overrides,
  }
}

function mount(accounts: Array<Record<string, unknown>>, people: Array<Record<string, unknown>> = []) {
  listUserAccounts.mockResolvedValue({ items: accounts })
  listPeople.mockResolvedValue({ items: people })
  return render(<IdentityAccounts />)
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('Identity accounts screen', () => {
  it('lands on the list alone and only reveals the create form inside a drawer', async () => {
    mount([account()], [{ id: 'person-2', status: 'active', person_version: 3, display_name_ar: 'م. سارة' }])

    expect(await screen.findByText('n.alrashidi')).toBeTruthy()
    // No form is competing with the list before the user asks for one.
    expect(screen.queryByLabelText('Username')).toBeNull()
    expect(screen.queryByRole('dialog')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Add account' }))

    expect(screen.getByRole('dialog', { name: 'Add a sign-in account' })).toBeTruthy()
    expect(screen.getByLabelText(/Username/)).toBeTruthy()
  })

  it('offers a locked account only the moves its state allows', async () => {
    mount([account({ status: 'locked' })])

    fireEvent.click(await screen.findByRole('button', { name: /Manage/ }))

    expect(screen.getByRole('button', { name: 'Unlock' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Disable account' })).toBeTruthy()
    // Already-locked accounts cannot be activated or re-sent an activation link.
    expect(screen.queryByRole('button', { name: 'Activate account' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Send activation link' })).toBeNull()
  })

  it('leads an account awaiting activation to the activation link rather than a transition', async () => {
    mount([account({ status: 'pending' })])

    fireEvent.click(await screen.findByRole('button', { name: /Manage/ }))

    expect(screen.getByRole('button', { name: 'Send activation link' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'End open sessions' })).toBeNull()
  })

  it('acts on the account the row opened, without asking the user to pick it again', async () => {
    transitionUserAccount.mockResolvedValue(account({ status: 'disabled' }))
    mount([account()])

    fireEvent.click(await screen.findByRole('button', { name: /Manage/ }))
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'left the department' } })
    fireEvent.click(screen.getByRole('button', { name: 'Disable account' }))

    await waitFor(() => {
      expect(transitionUserAccount).toHaveBeenCalledWith('test-token', 'account-1', 'disable', 'left the department')
    })
  })

  it('says so plainly when every active employee already has an account', async () => {
    mount([account()], [{ id: 'person-1', status: 'active', person_version: 1, display_name_ar: 'د. نوال الرشيدي' }])

    expect(await screen.findByText('Every active employee already has a sign-in account.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Add account' })).toBeNull()
  })
})
