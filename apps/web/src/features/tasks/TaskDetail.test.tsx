// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import type { Session } from '../../api'
import { getTask, getTaskComments } from '../workflow/workflow-api'
import { TaskDetail } from './TaskDetail'

vi.mock('../workflow/workflow-api', () => ({ getTask: vi.fn(), getTaskComments: vi.fn() }))
const session = { access_token: 'token', user_id: 'user' } as unknown as Session
const taskMock = vi.mocked(getTask)
const commentsMock = vi.mocked(getTaskComments)

afterEach(() => { cleanup(); vi.clearAllMocks() })

describe('TaskDetail', () => {
  it('shows loading', () => {
    taskMock.mockReturnValue(new Promise(() => undefined))
    commentsMock.mockReturnValue(new Promise(() => undefined))
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(screen.getByLabelText('Loading requests…')).toBeTruthy()
  })

  it('shows error', async () => {
    taskMock.mockRejectedValue(new Error('no'))
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('We could not load the requests. Try again.')).toBeTruthy()
  })

  it('shows success and empty comments', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', description: 'Body', allowed_actions: [] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Task')).toBeTruthy()
    expect(screen.getByText('No comments yet.')).toBeTruthy()
  })

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

  it('renders the status as a StatusBadge (with .status-badge class) for every known status', async () => {
    for (const status of ['completed', 'cancelled', 'pending', 'draft']) {
      taskMock.mockResolvedValueOnce({ id: `t-${status}`, title: 'Task', status, allowed_actions: [] })
      commentsMock.mockResolvedValueOnce({ items: [], total: 0 })
      const { unmount } = render(<TaskDetail locale="en" session={session} taskId={`t-${status}`} scopeReady scopeEpoch={0} />)
      const badge = await screen.findByText(status)
      expect(badge.classList.contains('status-badge')).toBe(true)
      unmount()
    }
  })

  it('falls back to the "—" badge text when the task is missing a status and a state', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', allowed_actions: [] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    const dashes = await screen.findAllByText('—')
    expect(dashes.some((node) => node.classList.contains('status-badge'))).toBe(true)
  })

  it('renders the allowed_actions array as a comma-separated list of action names', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', status: 'pending', allowed_actions: ['complete', 'return-completion', 'start'] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('complete, return-completion, start')).toBeTruthy()
  })

  it('renders "—" when allowed_actions is missing or empty', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', status: 'completed' })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Task')).toBeTruthy()
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('renders the task description inside the detail panel', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', description: 'Detailed description text', status: 'pending', allowed_actions: [] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Detailed description text')).toBeTruthy()
  })

  it('shows the no-details fallback when description is missing', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', status: 'pending', allowed_actions: [] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Task')).toBeTruthy()
    expect(screen.getByText('No details are available for this record.')).toBeTruthy()
  })

  it('navigates back to the list via the in-app router instead of window.location.href', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'Task', status: 'pending', allowed_actions: [] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    const onNavigate = vi.fn()
    render(<TaskDetail locale="en" session={session} taskId="t" scopeReady scopeEpoch={0} onNavigate={onNavigate} />)
    await screen.findByText('Task')
    screen.getByRole('button', { name: 'Back to list' }).click()
    expect(onNavigate).toHaveBeenCalledWith('/tasks')
  })

  it('does not force dir=ltr on the actions paragraph in Arabic locale', async () => {
    taskMock.mockResolvedValue({ id: 't', title: 'مهمة', status: 'pending', allowed_actions: ['complete', 'return-completion'] })
    commentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="ar" session={session} taskId="t" scopeReady scopeEpoch={0} />)
    const actions = await screen.findByText('complete, return-completion')
    expect(actions.getAttribute('dir')).not.toBe('ltr')
  })
 })
