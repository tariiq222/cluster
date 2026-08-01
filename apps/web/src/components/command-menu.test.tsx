// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { CommandMenu } from './command-menu'

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CommandMenu locale="ar" />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('command menu', () => {
  it('is closed until the keyboard shortcut fires', () => {
    const { queryByRole } = mount()
    expect(queryByRole('dialog')).toBeNull()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    expect(queryByRole('dialog')).not.toBeNull()
  })

  it('closes on Escape', () => {
    const { queryByRole } = mount()
    fireEvent.keyDown(document, { key: 'k', metaKey: true })
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(queryByRole('dialog')).toBeNull()
  })
})
