// @vitest-environment jsdom
if (typeof Element !== 'undefined' && !Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = function () {}
}
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'

import { ApiError, type Session } from '../../api'
import * as tasksApi from '../../api/tasks'
import { TaskListScreen } from './TaskListScreen'

vi.mock('../../api/tasks', () => ({ listTasks: vi.fn() }))

const session = { access_token: 'token', user_id: 'user' } as unknown as Session
const listTasksMock = vi.mocked(tasksApi.listTasks)

function pendingTasks() {
  const { promise } = Promise.withResolvers<{ items: tasksApi.Task[]; total: number }>()
  return promise
}

function makeTask(overrides: Partial<tasksApi.Task> = {}): tasksApi.Task {
  return {
    id: '018f0000-0000-7000-8000-000000000001',
    title: 'تجهيز تقرير السفر',
    description: 'تقرير شهري',
    state: 'open',
    classification: 'internal',
    priority: 'normal',
    assignee_user_id: '018f0000-0000-7000-8000-000000000002',
    creator_user_id: '018f0000-0000-7000-8000-000000000003',
    participant_user_ids: [],
    allowed_actions: ['start'],
    attachments: [],
    lock_version: 1,
    created_at: '2026-07-01T00:00:00Z',
    updated_at: '2026-07-01T00:00:00Z',
    ...overrides,
  } as tasksApi.Task
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('TaskListScreen', () => {
  it('shows the loading skeleton while tasks are loading', () => {
    listTasksMock.mockReturnValue(pendingTasks() as unknown as ReturnType<typeof tasksApi.listTasks>)
    render(<TaskListScreen locale="en" session={session} />)
    expect(screen.getByLabelText('Loading tasks…')).toBeTruthy()
  })

  it('renders the empty state when the API returns no items', async () => {
    listTasksMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskListScreen locale="en" session={session} />)
    expect(await screen.findByText('No tasks yet')).toBeTruthy()
    expect(screen.getByText('Any task assigned to you, created by you, or with you as a participant will appear here.')).toBeTruthy()
  })

  it('forbidden: shows the forbidden empty state and calls listTasks with the all relationship filter', async () => {
    listTasksMock.mockRejectedValue(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))
    render(<TaskListScreen locale="en" session={session} />)
    expect(await screen.findByText('You need the tasks.read capability to view this page.')).toBeTruthy()
    expect(listTasksMock).toHaveBeenCalledWith('token', {})
  })

  it('renders list rows with state badges for every task state', async () => {
    listTasksMock.mockResolvedValue({
      items: [
        makeTask({ id: '1', state: 'open', title: 'Open task' }),
        makeTask({ id: '2', state: 'in_progress', title: 'In progress task' }),
        makeTask({ id: '3', state: 'blocked', title: 'Blocked task' }),
        makeTask({ id: '4', state: 'completed', title: 'Completed task' }),
        makeTask({ id: '5', state: 'cancelled', title: 'Cancelled task' }),
      ],
      total: 5,
    })
    render(<TaskListScreen locale="en" session={session} />)
    expect(await screen.findByText('Open task')).toBeTruthy()
    expect(screen.getByText('In progress task')).toBeTruthy()
    expect(screen.getByText('Blocked task')).toBeTruthy()
    expect(screen.getByText('Completed task')).toBeTruthy()
    expect(screen.getByText('Cancelled task')).toBeTruthy()
    expect(screen.getAllByText(/Open|In progress|Blocked|Completed|Cancelled/).length).toBeGreaterThanOrEqual(5)
  })

  it('passes the assigned relationship filter to the API when changed', async () => {
    listTasksMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskListScreen locale="en" session={session} />)
    expect(await screen.findByText('No tasks yet')).toBeTruthy()
    expect(listTasksMock).toHaveBeenLastCalledWith('token', {})
    const trigger = document.getElementById('task-relationship-filter') as HTMLButtonElement
    trigger.click()
    const option = await screen.findByRole('option', { name: 'Assigned to me' })
    await act(async () => { option.click() })
    expect(listTasksMock).toHaveBeenLastCalledWith('token', { relationship: 'assigned' })
  })

  it('passes the state filter to the API when changed', async () => {
    listTasksMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskListScreen locale="en" session={session} />)
    expect(await screen.findByText('No tasks yet')).toBeTruthy()
    const trigger = document.getElementById('task-state-filter') as HTMLButtonElement
    trigger.click()
    const option = await screen.findByRole('option', { name: 'Blocked' })
    await act(async () => { option.click() })
    expect(listTasksMock).toHaveBeenLastCalledWith('token', { state: 'blocked' })
  })

  it('clears a task and ignores its late response after the scope becomes pending', async () => {
    const { promise, resolve } = Promise.withResolvers<{ items: tasksApi.Task[]; total: number }>()
    listTasksMock.mockImplementationOnce(() => promise as unknown as ReturnType<typeof tasksApi.listTasks>)
    const { rerender } = render(<TaskListScreen locale="en" session={session} />)
    expect(screen.getByLabelText('Loading tasks…')).toBeTruthy()
    listTasksMock.mockResolvedValue({ items: [], total: 0 })
    rerender(<TaskListScreen locale="en" session={{ ...session, access_token: 'token2' } as unknown as Session} />)
    expect(await screen.findByText('No tasks yet')).toBeTruthy()
    resolve({ items: [makeTask({ id: 'stale', title: 'Stale task' })], total: 1 })
    expect(screen.queryByText('Stale task')).toBeNull()
  })

  it('renders with dir="rtl" in Arabic locale', () => {
    listTasksMock.mockReturnValue(pendingTasks() as unknown as ReturnType<typeof tasksApi.listTasks>)
    const { container } = render(<TaskListScreen locale="ar" session={session} />)
    expect(container.firstChild && (container.firstChild as HTMLElement).getAttribute('dir')).toBe('rtl')
  })

  it('surfaces a retryable error message when the API throws', async () => {
    listTasksMock.mockRejectedValue(new Error('boom'))
    render(<TaskListScreen locale="en" session={session} />)
    expect(await screen.findByText('Could not load tasks')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeTruthy()
  })

  it('calls the navigate callback with the create route when the create button is clicked', async () => {
    listTasksMock.mockResolvedValue({ items: [], total: 0 })
    const navigate = vi.fn()
    render(<TaskListScreen locale="en" session={session} onNavigate={navigate} />)
    const createButtons = await screen.findAllByRole('button', { name: 'Create task' })
    createButtons[0].click()
    expect(navigate).toHaveBeenCalledWith('/tasks/new')
  })

  it('calls the navigate callback with the task detail route when an item is opened', async () => {
    listTasksMock.mockResolvedValue({ items: [makeTask({ id: 't1' })], total: 1 })
    const navigate = vi.fn()
    render(<TaskListScreen locale="en" session={session} onNavigate={navigate} />)
    const openButton = await screen.findByRole('button', { name: 'Open' })
    openButton.click()
    expect(navigate).toHaveBeenCalledWith('/tasks/t1')
  })
})
