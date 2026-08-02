// @vitest-environment jsdom
import { useMemo } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import {
  useScreenHelp,
  type RegisteredScreenHelp,
} from './screen-help'
import { ScreenHelpProvider } from './screen-help-provider'

function HelpOwner() {
  const help = useMemo(
    () => ({
      currentState: 'Open',
      permittedNextAction: 'Start',
    }),
    [],
  )
  useScreenHelp(help)
  return null
}

describe('screen help context', () => {
  it('registers help for the active path and clears only its own registration', async () => {
    const onChange = vi.fn<(value: RegisteredScreenHelp | null) => void>()
    const view = render(
      <MemoryRouter initialEntries={['/tasks/t1']}>
        <ScreenHelpProvider onChange={onChange}>
          <HelpOwner />
        </ScreenHelpProvider>
      </MemoryRouter>,
    )

    await waitFor(() =>
      expect(onChange).toHaveBeenLastCalledWith({
        pathname: '/tasks/t1',
        help: { currentState: 'Open', permittedNextAction: 'Start' },
      }),
    )

    view.unmount()
    expect(onChange).toHaveBeenLastCalledWith(null)
  })
})
