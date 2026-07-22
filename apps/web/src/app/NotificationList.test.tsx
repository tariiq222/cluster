// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { NotificationList } from './NotificationList'

const item = {
  id: '01980f50-5f0d-7000-8000-000000000021',
  title: 'اكتملت معالجة الطلب',
  is_read: false,
  created_at: '2026-07-18T11:00:00Z',
  source: { type: 'work_record', id: '01980f50-5f0d-7000-8000-000000000011' },
} as never

describe('NotificationList mark-as-read flow', () => {
  afterEach(() => cleanup())

  it('shows a busy state, blocks duplicate submits, and delegates the mutation', async () => {
    let resolve!: () => void
    const onMarkRead = vi.fn(() => new Promise<void>((done) => { resolve = done }))
    render(<NotificationList locale="ar" items={[item]} loading={false} error={false} onMarkRead={onMarkRead} />)

    const action = screen.getByRole('button', { name: /تحديد الإشعار كمقروء/ })
    fireEvent.click(action)
    expect(action).toHaveProperty('disabled', true)
    fireEvent.click(action)
    expect(onMarkRead).toHaveBeenCalledTimes(1)
    resolve()
    await waitFor(() => expect(action).toHaveProperty('disabled', false))
  })

  it('keeps the row actionable and reports mutation failures', async () => {
    const onMarkRead = vi.fn(async () => { throw new Error('network') })
    render(<NotificationList locale="en" items={[item]} loading={false} error={false} onMarkRead={onMarkRead} />)

    fireEvent.click(screen.getByRole('button', { name: /Mark notification as read/ }))
    await expect(screen.findByText('Could not mark the notification as read. Try again.')).resolves.toBeTruthy()
    expect(screen.getByRole('button', { name: /Mark notification as read/ })).toHaveProperty('disabled', false)
  })
})
