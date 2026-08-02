// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { ContextualHelp } from './contextual-help'
import { SidebarProvider } from '@/components/ui/sidebar'

describe('contextual help', () => {
  it('prefers screen-owned state and copies a real correlation ID', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    })

    render(
      <SidebarProvider>
        <ContextualHelp
          locale="en"
          pathname="/tasks/t1"
          scopeLabel="Facility One"
          open
          onOpenChange={() => {}}
          screenHelp={{
            currentState: 'Open',
            activeSection: 'Comments',
            permittedNextAction: 'Start',
            recoveryGuidance: ['Retry after refreshing the task.'],
            correlationId: 'corr-task-1',
          }}
        />
      </SidebarProvider>,
    )

    const dialog = screen.getByRole('dialog', { name: 'Tasks help' })
    expect(within(dialog).getByText('Open')).toBeVisible()
    expect(within(dialog).getByText('Comments')).toBeVisible()
    expect(within(dialog).getByText('Start')).toBeVisible()
    expect(within(dialog).getByText('Retry after refreshing the task.')).toBeVisible()
    expect(within(dialog).getByText('corr-task-1')).toHaveAttribute('dir', 'ltr')

    fireEvent.click(
      within(dialog).getByRole('button', { name: 'Copy correlation ID' }),
    )
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('corr-task-1'))
    expect(within(dialog).getByRole('status')).toHaveTextContent(
      'Correlation ID copied.',
    )
  })
})
