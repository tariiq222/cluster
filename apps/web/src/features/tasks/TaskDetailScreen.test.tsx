// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { TaskDetailScreen } from './TaskDetailScreen'
import { SessionProvider } from '../../app/session-context'
import { PrincipalContextTestProvider } from '../../app/principal-context'
import {
  type RegisteredScreenHelp,
} from '../../app/screen-help'
import { ScreenHelpProvider } from '../../app/screen-help-provider'
import { ApiError } from '../../api/http'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const task = {
  id: 't1',
  title: 'مهمة اختبار',
  description: 'وصف المهمة',
  state: 'open',
  priority: 'normal',
  classification: 'internal',
  created_at: '2026-07-01T00:00:00Z',
  updated_at: '2026-07-01T00:00:00Z',
  lock_version: 1,
  allowed_actions: ['start'],
}

const transitionMutateAsync = vi.hoisted(() => vi.fn())

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: { items: [] }, isPending: false, isError: false, refetch: vi.fn() }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}))

vi.mock('../../api/hooks', () => ({
  useTask: () => ({ data: task, isPending: false, isError: false, error: null, refetch: vi.fn() }),
  useTaskMutations: () => ({
    update: { isPending: false, mutateAsync: vi.fn() },
    transition: { isPending: false, mutateAsync: transitionMutateAsync },
    addComment: { isPending: false, mutateAsync: vi.fn() },
    addParticipant: { isPending: false, mutateAsync: vi.fn() },
  }),
}))

function mount(
  onHelpChange: (value: RegisteredScreenHelp | null) => void = () => {},
) {
  return render(
    <MemoryRouter initialEntries={['/tasks/t1']}>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <PrincipalContextTestProvider capabilities={['tasks.read']} features={{ tasks: true }}>
          <ScreenHelpProvider onChange={onHelpChange}>
            <TaskDetailScreen taskId="t1" />
          </ScreenHelpProvider>
        </PrincipalContextTestProvider>
      </SessionProvider>
    </MemoryRouter>,
  )
}

describe('task detail', () => {
  beforeEach(() => transitionMutateAsync.mockReset())

  it('renders only the transitions the server allows', () => {
    mount()
    expect(screen.getByRole('button', { name: 'بدء' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'إكمال' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'إلغاء' })).toBeNull()
  })

  it('organizes the detail into the three tabs of the workspace', () => {
    mount()
    expect(screen.getByRole('tab', { name: 'التفاصيل' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'التعليقات' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'المشاركون' })).toBeInTheDocument()
  })

  it('publishes server-authoritative state, action, and active tab to help', async () => {
    const registrations: RegisteredScreenHelp[] = []
    mount((value) => {
      if (value) registrations.push(value)
    })

    await waitFor(() =>
      expect(registrations.at(-1)?.help).toMatchObject({
        currentState: 'مفتوحة',
        activeSection: 'التفاصيل',
        permittedNextAction: 'بدء',
      }),
    )

    const commentsTab = screen.getByRole('tab', { name: 'التعليقات' })
    fireEvent.mouseDown(commentsTab, { button: 0, ctrlKey: false })
    fireEvent.click(commentsTab)
    await waitFor(() =>
      expect(registrations.at(-1)?.help.activeSection).toBe('التعليقات'),
    )
  })

  it('publishes the real correlation ID and recovery guidance after an API failure', async () => {
    transitionMutateAsync.mockRejectedValueOnce(
      new ApiError(
        500,
        {
          type: 'about:blank',
          title: 'Task transition failed',
          status: 500,
        },
        'corr-task-failure',
      ),
    )
    const registrations: RegisteredScreenHelp[] = []
    mount((value) => {
      if (value) registrations.push(value)
    })

    fireEvent.click(screen.getByRole('button', { name: 'بدء' }))

    await waitFor(() =>
      expect(registrations.at(-1)?.help).toMatchObject({
        correlationId: 'corr-task-failure',
        recoveryGuidance: [
          'راجع رسالة الخطأ، ثم أعد المحاولة بعد التحقق من حالة المهمة.',
        ],
      }),
    )
  })
})
