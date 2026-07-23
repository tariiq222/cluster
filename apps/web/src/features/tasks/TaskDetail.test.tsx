// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import type { Session } from '../../api'
import { getTask, getTaskComments } from '../workflow/workflow-api'
import { TaskDetail } from './TaskDetail'
vi.mock('../workflow/workflow-api', () => ({ getTask: vi.fn(), getTaskComments: vi.fn() }))
const session = { access_token: 'token', user_id: 'user' } as unknown as Session
const taskMock = vi.mocked(getTask); const commentsMock = vi.mocked(getTaskComments)
afterEach(() => { cleanup(); vi.clearAllMocks() })
describe('TaskDetail', () => {
  it('shows loading', () => { taskMock.mockReturnValue(new Promise(() => undefined)); commentsMock.mockReturnValue(new Promise(() => undefined)); render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />); expect(screen.getByLabelText('Loading requests…')).toBeTruthy() })
  it('shows error', async () => { taskMock.mockRejectedValue(new Error('no')); commentsMock.mockResolvedValue({ items: [], total: 0 }); render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />); expect(await screen.findByText('We could not load the requests. Try again.')).toBeTruthy() })
  it('shows success and empty comments', async () => { taskMock.mockResolvedValue({ id: 't', title: 'Task', description: 'Body', allowed_actions: [] }); commentsMock.mockResolvedValue({ items: [], total: 0 }); render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />); expect(await screen.findByText('Task')).toBeTruthy(); expect(screen.getByText('No comments yet.')).toBeTruthy() })
  it('clears a task and ignores its late response after the scope becomes pending', async () => {
    let resolveTask!: (value: { id: string; title: string; allowed_actions: string[] }) => void
    taskMock.mockImplementationOnce(() => new Promise((resolve) => { resolveTask = resolve }))
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    const { rerender } = render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={1} />)
    rerender(<TaskDetail locale="en" session={session} taskId="t" scopeReady={false} scopeEpoch={2} />)
    resolveTask({ id: 't', title: 'Old task', allowed_actions: [] })
    await act(async () => { await Promise.resolve() })
    expect(screen.queryByText('Old task')).toBeNull()
    expect(taskMock).toHaveBeenCalledTimes(1)
  })
})
