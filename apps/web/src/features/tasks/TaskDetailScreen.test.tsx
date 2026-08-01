// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { TaskDetailScreen } from './TaskDetailScreen'
import { SessionProvider } from '../../app/session-context'
import { PrincipalContextTestProvider } from '../../app/principal-context'

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

vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: { items: [] }, isPending: false, isError: false, refetch: vi.fn() }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}))

vi.mock('../../api/hooks', () => ({
  useTask: () => ({ data: task, isPending: false, isError: false, error: null, refetch: vi.fn() }),
  useTaskMutations: () => ({
    update: { isPending: false, mutateAsync: vi.fn() },
    transition: { isPending: false, mutateAsync: vi.fn() },
    addComment: { isPending: false, mutateAsync: vi.fn() },
    addParticipant: { isPending: false, mutateAsync: vi.fn() },
  }),
}))

function mount() {
  return render(
    <MemoryRouter>
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <PrincipalContextTestProvider capabilities={['tasks.read']} features={{ work_management: false, tasks: true }}>
          <TaskDetailScreen taskId="t1" />
        </PrincipalContextTestProvider>
      </SessionProvider>
    </MemoryRouter>,
  )
}

describe('task detail', () => {
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
})
