// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { ApiError, type Session } from '../../api'
import * as tasksApi from '../../api/tasks'
import { TaskCreateScreen } from './TaskCreateScreen'

vi.mock('../../api/tasks', () => ({ createTask: vi.fn() }))

const session = { access_token: 'token', user_id: 'user' } as unknown as Session
const createTaskMock = vi.mocked(tasksApi.createTask)

function setNativeValue(element: HTMLInputElement | HTMLTextAreaElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(element.constructor.prototype, 'value')?.set
  setter?.call(element, value)
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

describe('TaskCreateScreen', () => {
  it('blocks submission when the title is missing and never calls the wrapper', async () => {
    const navigate = vi.fn()
    render(<TaskCreateScreen locale="en" session={session} onNavigate={navigate} />)
    const form = document.querySelector('form') as HTMLFormElement
    form.requestSubmit()
    expect(createTaskMock).not.toHaveBeenCalled()
    expect(await screen.findByText('Task title is required.')).toBeTruthy()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('submits a self-task with no assignee and no participants when fields are empty', async () => {
    createTaskMock.mockResolvedValue(makeTask({ id: 'new-1', title: 'Self task' }))
    const navigate = vi.fn()
    const onCreated = vi.fn()
    render(<TaskCreateScreen locale="en" session={session} onNavigate={navigate} onCreated={onCreated} />)
    const title = document.getElementById('task-title') as HTMLInputElement
    setNativeValue(title, 'Self task')
    title.dispatchEvent(new Event('input', { bubbles: true }))
    const form = document.querySelector('form') as HTMLFormElement
    form.requestSubmit()
    await vi.waitFor(() => expect(createTaskMock).toHaveBeenCalledTimes(1))
    expect(createTaskMock).toHaveBeenCalledWith('token', expect.objectContaining({
      title: 'Self task',
      description: undefined,
      assignee_user_id: undefined,
      participant_user_ids: undefined,
      priority: 'normal',
      due_at: undefined,
    }))
    expect(navigate).toHaveBeenCalledWith('/tasks/new-1')
    expect(onCreated).toHaveBeenCalledWith('new-1')
  })

  it('submits an assigned task with participant ids and a due date', async () => {
    createTaskMock.mockResolvedValue(makeTask({ id: 'new-2' }))
    const navigate = vi.fn()
    render(<TaskCreateScreen locale="en" session={session} onNavigate={navigate} />)
    const title = document.getElementById('task-title') as HTMLInputElement
    setNativeValue(title, 'Assigned task')
    title.dispatchEvent(new Event('input', { bubbles: true }))
    const assignee = document.getElementById('task-assignee') as HTMLInputElement
    setNativeValue(assignee, '018f0000-0000-7000-8000-000000000099')
    assignee.dispatchEvent(new Event('input', { bubbles: true }))
    const due = document.getElementById('task-due-at') as HTMLInputElement
    setNativeValue(due, '2026-08-15T10:30')
    due.dispatchEvent(new Event('input', { bubbles: true }))
    const parts = document.getElementById('task-participants') as HTMLInputElement
    setNativeValue(parts, '018f0000-0000-7000-8000-0000000000aa, 018f0000-0000-7000-8000-0000000000bb')
    parts.dispatchEvent(new Event('input', { bubbles: true }))
    const form = document.querySelector('form') as HTMLFormElement
    form.requestSubmit()
    await vi.waitFor(() => expect(createTaskMock).toHaveBeenCalledTimes(1))
    const call = createTaskMock.mock.calls[0][1]
    expect(call.title).toBe('Assigned task')
    expect(call.assignee_user_id).toBe('018f0000-0000-7000-8000-000000000099')
    expect(call.participant_user_ids).toEqual([
      '018f0000-0000-7000-8000-0000000000aa',
      '018f0000-0000-7000-8000-0000000000bb',
    ])
    expect(typeof call.due_at).toBe('string')
  })

  it('shows the team-scope error message on a 422 response and does not navigate', async () => {
    createTaskMock.mockRejectedValue(new ApiError(422, { type: 'about:blank', title: 'Unprocessable', status: 422 }))
    const navigate = vi.fn()
    render(<TaskCreateScreen locale="en" session={session} onNavigate={navigate} />)
    const title = document.getElementById('task-title') as HTMLInputElement
    setNativeValue(title, 'Out of team')
    title.dispatchEvent(new Event('input', { bubbles: true }))
    const form = document.querySelector('form') as HTMLFormElement
    form.requestSubmit()
    expect(await screen.findByText('Assignee must belong to a team you manage.')).toBeTruthy()
    expect(navigate).not.toHaveBeenCalled()
  })

  it('shows the forbidden copy on a 403 response', async () => {
    createTaskMock.mockRejectedValue(new ApiError(403, { type: 'about:blank', title: 'Forbidden', status: 403 }))
    render(<TaskCreateScreen locale="en" session={session} />)
    const title = document.getElementById('task-title') as HTMLInputElement
    setNativeValue(title, 'Forbidden task')
    title.dispatchEvent(new Event('input', { bubbles: true }))
    const form = document.querySelector('form') as HTMLFormElement
    form.requestSubmit()
    expect(await screen.findByText('You need the tasks.read capability to view this page.')).toBeTruthy()
  })

  it('navigates back to the list when the back button is clicked', () => {
    const navigate = vi.fn()
    render(<TaskCreateScreen locale="en" session={session} onNavigate={navigate} />)
    const backButton = screen.getByRole('button', { name: /My tasks/ })
    backButton.click()
    expect(navigate).toHaveBeenCalledWith('/tasks')
  })

  it('renders the form with all required field labels and labels match their inputs', () => {
    render(<TaskCreateScreen locale="en" session={session} />)
    expect(screen.getByLabelText('Task title *')).toBeTruthy()
    expect(screen.getByLabelText('Description')).toBeTruthy()
    expect(screen.getByLabelText('Assignee')).toBeTruthy()
    expect(screen.getByLabelText('Priority')).toBeTruthy()
    expect(screen.getByLabelText('Due at')).toBeTruthy()
    expect(screen.getByLabelText('Participant user ids')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Create task' })).toBeTruthy()
  })

  it('renders Arabic copy and sets dir="rtl" on the wrapper', () => {
    let resolve!: (v: tasksApi.Task) => void; const promise = new Promise<tasksApi.Task>((r) => { resolve = r })
    createTaskMock.mockReturnValue(promise as unknown as Promise<tasksApi.Task>)
    const { container } = render(<TaskCreateScreen locale="ar" session={session} />)
    expect(container.firstChild && (container.firstChild as HTMLElement).getAttribute('dir')).toBe('rtl')
    expect(screen.getByLabelText('عنوان المهمة *')).toBeTruthy()
  })

  it('only calls the tasks wrapper when the form is submitted', async () => {
    createTaskMock.mockResolvedValue(makeTask())
    render(<TaskCreateScreen locale="en" session={session} />)
    expect(createTaskMock).not.toHaveBeenCalled()
    const title = document.getElementById('task-title') as HTMLInputElement
    setNativeValue(title, 'Only once')
    title.dispatchEvent(new Event('input', { bubbles: true }))
    const form = document.querySelector('form') as HTMLFormElement
    form.requestSubmit()
    await vi.waitFor(() => expect(createTaskMock).toHaveBeenCalledTimes(1))
  })
})
