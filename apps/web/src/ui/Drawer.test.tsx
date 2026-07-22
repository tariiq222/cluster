// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { Drawer } from './Drawer'

afterEach(() => {
  cleanup()
})

describe('Drawer keyboard focus', () => {
  it('moves focus to the heading when opened', async () => {
    render(
      <Drawer open onClose={() => {}} title="عنوان">
        <button>إجراء</button>
      </Drawer>,
    )

    await waitFor(() => {
      expect(document.activeElement).toBe(screen.getByText('عنوان'))
    })
  })

  it('wraps Tab from the last focusable back to the first', async () => {
    render(
      <Drawer open onClose={() => {}} title="عنوان">
        <input data-testid="body-input" />
        <button data-testid="body-action">إجراء داخلي</button>
      </Drawer>,
    )

    await waitFor(() => {
      expect(document.activeElement).toBe(screen.getByText('عنوان'))
    })

    const closeButton = screen.getByRole('button', { name: /إغلاق/ })
    const bodyAction = screen.getByTestId('body-action')

    bodyAction.focus()
    fireEvent.keyDown(bodyAction, { key: 'Tab' })

    expect(document.activeElement).toBe(closeButton)
  })

  it('wraps Shift+Tab from the first focusable to the last', async () => {
    render(
      <Drawer open onClose={() => {}} title="عنوان">
        <input data-testid="body-input" />
        <button data-testid="body-action">إجراء داخلي</button>
      </Drawer>,
    )

    await waitFor(() => {
      expect(document.activeElement).toBe(screen.getByText('عنوان'))
    })

    const closeButton = screen.getByRole('button', { name: /إغلاق/ })
    const bodyAction = screen.getByTestId('body-action')

    closeButton.focus()
    fireEvent.keyDown(closeButton, { key: 'Tab', shiftKey: true })

    expect(document.activeElement).toBe(bodyAction)
  })

  it('calls onClose on Escape when dismissable is true', () => {
    const onClose = vi.fn()
    render(<Drawer open onClose={onClose} title="عنوان" />)

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('does not call onClose on Escape when dismissable is false', () => {
    const onClose = vi.fn()
    render(<Drawer open onClose={onClose} title="عنوان" dismissable={false} />)

    fireEvent.keyDown(document, { key: 'Escape' })

    expect(onClose).not.toHaveBeenCalled()
  })

  it('restores focus to the previously focused element on close', async () => {
    const { rerender } = render(
      <>
        <button data-testid="opener">فتح</button>
        <Drawer open onClose={() => {}} title="عنوان" />
      </>,
    )

    const opener = screen.getByTestId('opener')
    opener.focus()
    expect(document.activeElement).toBe(opener)

    rerender(
      <>
        <button data-testid="opener">فتح</button>
        <Drawer open={false} onClose={() => {}} title="عنوان" />
      </>,
    )

    await waitFor(() => {
      expect(document.activeElement).toBe(opener)
    })
  })
})
