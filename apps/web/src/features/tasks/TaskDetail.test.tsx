// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { Session } from '../../api'
import { ApiError } from '../../api'
import {
  addTaskComment,
  addTaskParticipant,
  attachTaskDocument,
  getTask,
  listTaskComments,
  transitionTask,
  updateTask,
} from '../../api/tasks'
import { TaskDetail } from './TaskDetail'

vi.mock('../../api/tasks', () => ({
  getTask: vi.fn(),
  listTaskComments: vi.fn(),
  addTaskComment: vi.fn(),
  transitionTask: vi.fn(),
  updateTask: vi.fn(),
  addTaskParticipant: vi.fn(),
  attachTaskDocument: vi.fn(),
}))

const session = { access_token: 'token', user_id: 'user' } as unknown as Session

const getTaskMock = vi.mocked(getTask)
const listCommentsMock = vi.mocked(listTaskComments)
const addCommentMock = vi.mocked(addTaskComment)
const transitionMock = vi.mocked(transitionTask)
const updateMock = vi.mocked(updateTask)
const addParticipantMock = vi.mocked(addTaskParticipant)
const attachDocumentMock = vi.mocked(attachTaskDocument)

function problem(status: number, detail = 'problem'): ApiError {
  return new ApiError(status, {
    type: `about:blank/${status}`,
    title: 'Request failed',
    status,
    detail,
  })
}

function makeTask(overrides: Record<string, unknown> = {}) {
  return {
    id: 'task-1',
    title: 'My task',
    description: 'Body text',
    state: 'open',
    classification: 'internal',
    priority: 'normal',
    assignee_user_id: 'assignee-1',
    creator_user_id: 'creator-1',
    participant_user_ids: ['p-1', 'p-2'],
    allowed_actions: [],
    attachments: [],
    lock_version: 7,
    ...overrides,
  }
}

beforeEach(() => {
  getTaskMock.mockReset()
  listCommentsMock.mockReset()
  addCommentMock.mockReset()
  transitionMock.mockReset()
  updateMock.mockReset()
  addParticipantMock.mockReset()
  attachDocumentMock.mockReset()
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

function fillById(id: string, value: string): void {
  const el = document.getElementById(id) as HTMLInputElement | HTMLTextAreaElement | null
  if (!el) throw new Error(`#${id} not found`)
  fireEvent.change(el, { target: { value } })
}

describe('TaskDetail', () => {
  it('shows the loading skeleton while the request is pending', () => {
    getTaskMock.mockReturnValue(new Promise(() => undefined))
    listCommentsMock.mockReturnValue(new Promise(() => undefined))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    expect(screen.getByLabelText('Loading tasks…')).toBeTruthy()
  })

  it('renders metadata, participants, attachments, and the empty comments state when loaded', async () => {
    getTaskMock.mockResolvedValue(makeTask())
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('My task')).toBeTruthy()
    expect(screen.getByText('Open')).toBeTruthy()
    expect(screen.getByText('No comments yet.')).toBeTruthy()
    const empty = screen.getAllByText('No attachments yet.')
    expect(empty.length).toBeGreaterThan(0)
  })

  it('renders existing comments, attachments, and participants with summary counts', async () => {
    const attachments = [{ document_id: 'doc-1', title: 'Spec' }]
    getTaskMock.mockResolvedValue(makeTask({ attachments }))
    listCommentsMock.mockResolvedValue({
      items: [
        { id: 'c-1', author_user_id: 'creator-1', body: 'Looks good @assignee-1' },
        { id: 'c-2', author_user_id: 'creator-1', body: 'Thanks @creator-1' },
      ],
      total: 2,
    })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Looks good @assignee-1')).toBeTruthy()
    expect(screen.getByText('Spec')).toBeTruthy()
    expect(screen.getByText('p-1')).toBeTruthy()
  })

  it('renders the forbidden state when the API returns 403', async () => {
    getTaskMock.mockRejectedValue(problem(403))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Forbidden')).toBeTruthy()
    expect(screen.getByText('You do not have permission to read this task.')).toBeTruthy()
  })

  it('renders the not-found state when the API returns 404', async () => {
    getTaskMock.mockRejectedValue(problem(404))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Not found')).toBeTruthy()
  })

  it('renders the error state with a retry button for generic errors', async () => {
    getTaskMock.mockRejectedValue(problem(500))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    expect(await screen.findByText('Could not complete the request. Try again.')).toBeTruthy()
    const retry = await screen.findByRole('button', { name: 'Try again' })
    retry.click()
    await waitFor(() => {
      expect(getTaskMock.mock.calls.length).toBeGreaterThanOrEqual(2)
    })
  })

  it('renders server-driven action buttons and only those from allowed_actions', async () => {
    getTaskMock.mockResolvedValue(
      makeTask({
        state: 'in_progress',
        allowed_actions: ['block', 'unblock', 'comment'],
      }),
    )
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('In progress')
    expect(screen.getByRole('button', { name: 'Block' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Unblock' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Comment' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Complete' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Cancel' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Start' })).toBeNull()
  })

  it('does not infer actions from the task status alone when allowed_actions is missing', async () => {
    getTaskMock.mockResolvedValue(makeTask({ state: 'in_progress' }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('In progress')
    expect(screen.queryByRole('button', { name: 'Block' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Unblock' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Complete' })).toBeNull()
  })

  it('navigates back to the list via the in-app router', async () => {
    getTaskMock.mockResolvedValue(makeTask())
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    const onNavigate = vi.fn()
    render(
      <TaskDetail
        locale="en"
        session={session}
        taskId="task-1"
        scopeReady
        scopeEpoch={0}
        onNavigate={onNavigate}
      />,
    )
    await screen.findByText('My task')
    screen.getByRole('button', { name: 'My tasks' }).click()
    expect(onNavigate).toHaveBeenCalledWith('/tasks')
  })

  it('ignores a stale task response after the scope becomes pending', async () => {
    let resolveTask!: (value: ReturnType<typeof makeTask>) => void
    getTaskMock.mockImplementationOnce(
      () => new Promise((resolve) => {
        resolveTask = resolve
      }),
    )
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    const { rerender } = render(
      <TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={1} />,
    )
    rerender(
      <TaskDetail locale="en" session={session} taskId="task-1" scopeReady={false} scopeEpoch={2} />,
    )
    resolveTask(makeTask({ title: 'Old task' }))
    await act(async () => {
      await Promise.resolve()
    })
    expect(screen.queryByText('Old task')).toBeNull()
    expect(getTaskMock).toHaveBeenCalledTimes(1)
  })

  it('keeps the page direction inherited from the locale', async () => {
    getTaskMock.mockResolvedValue(makeTask({ title: 'مهمة' }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="ar" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    const root = await screen.findByText('تفاصيل المهمة')
    expect(root.closest('[dir]')?.getAttribute('dir')).toBe('rtl')
  })

  it('opens the block dialog and refuses to dispatch when the reason is empty', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['block'] }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Block' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm block' }))
    expect(transitionMock).not.toHaveBeenCalled()
    expect(await screen.findByText('A block reason is required.')).toBeTruthy()
  })

  it('dispatches the block transition with the reason through the wrapper', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['block'], lock_version: 4 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    transitionMock.mockResolvedValue(makeTask({ state: 'blocked', lock_version: 5 }))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Block' }))
    fillById('task-block-reason', '  waiting on docs  ')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm block' }))
    await waitFor(() => {
      expect(transitionMock).toHaveBeenCalledWith(
        'token',
        'task-1',
        'block',
        { reason: 'waiting on docs' },
        4,
      )
    })
    expect(await screen.findByText('Blocked')).toBeTruthy()
  })

  it('opens the cancel dialog and refuses to dispatch when the reason is empty', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['cancel'] }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm cancellation' }))
    expect(transitionMock).not.toHaveBeenCalled()
    expect(await screen.findByText('A cancellation reason is required.')).toBeTruthy()
  })

  it('dispatches the cancel transition with the reason through the wrapper', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['cancel'], lock_version: 9 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    transitionMock.mockResolvedValue(makeTask({ state: 'cancelled', lock_version: 10 }))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))
    fillById('task-cancel-reason', 'duplicate')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm cancellation' }))
    await waitFor(() => {
      expect(transitionMock).toHaveBeenCalledWith(
        'token',
        'task-1',
        'cancel',
        { reason: 'duplicate' },
        9,
      )
    })
  })

  it('opens the complete dialog and refuses to dispatch when the note is empty', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['complete'] }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Complete' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm completion' }))
    expect(transitionMock).not.toHaveBeenCalled()
    expect(await screen.findByText('A completion note is required.')).toBeTruthy()
  })

  it('dispatches the complete transition with the note through the wrapper', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['complete'], lock_version: 3 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    transitionMock.mockResolvedValue(makeTask({ state: 'completed', lock_version: 4 }))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Complete' }))
    fillById('task-complete-note', '  shipped to staging  ')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm completion' }))
    await waitFor(() => {
      expect(transitionMock).toHaveBeenCalledWith(
        'token',
        'task-1',
        'complete',
        { note: 'shipped to staging' },
        3,
      )
    })
    expect(await screen.findByText('Completed')).toBeTruthy()
  })
  it('dispatches the start and unblock transitions directly without dialogs', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['start', 'unblock'], lock_version: 2 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    transitionMock
      .mockResolvedValueOnce(makeTask({ state: 'in_progress', allowed_actions: ['unblock'], lock_version: 3 }))
      .mockResolvedValueOnce(makeTask({ state: 'in_progress', allowed_actions: ['unblock'], lock_version: 3 }))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Start' }))
    await waitFor(() => {
      expect(transitionMock).toHaveBeenCalledWith('token', 'task-1', 'start', undefined, 2)
    })
    fireEvent.click(screen.getByRole('button', { name: 'Unblock' }))
    await waitFor(() => {
      expect(transitionMock).toHaveBeenCalledWith('token', 'task-1', 'unblock', undefined, 3)
    })
  })

  it('preserves the reason across a 412-stale refresh and triggers a reload', async () => {
    getTaskMock
      .mockResolvedValueOnce(makeTask({ allowed_actions: ['block'], lock_version: 4 }))
      .mockResolvedValueOnce(makeTask({ allowed_actions: ['block'], lock_version: 5 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    transitionMock.mockRejectedValueOnce(problem(412, 'stale'))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Block' }))
    fillById('task-block-reason', 'stale-reason')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm block' }))
    await waitFor(() => {
      expect(transitionMock).toHaveBeenCalled()
    })
    await waitFor(() => {
      expect(getTaskMock.mock.calls.length).toBeGreaterThanOrEqual(2)
    })
    fireEvent.click(screen.getByRole('button', { name: 'Block' }))
    const textarea = await screen.findByLabelText('Block reason', { exact: false }) as HTMLTextAreaElement
    expect(textarea.value).toBe('stale-reason')
  })

  it('surfaces a server error from a transition inline and keeps the task visible', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['block'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    transitionMock.mockRejectedValueOnce(problem(409, 'cannot block'))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Block' }))
    fillById('task-block-reason', 'because')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm block' }))
    expect(await screen.findByText('cannot block')).toBeTruthy()
    expect(screen.getByText('Open')).toBeTruthy()
  })

  it('reassigns the task via the wrapper and updates the metadata', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['reassign'], lock_version: 6 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    updateMock.mockResolvedValue(makeTask({ assignee_user_id: 'new-owner', lock_version: 7 }))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Reassign' }))
    fillById('task-reassign-target', 'new-owner')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm reassignment' }))
    await waitFor(() => {
      expect(updateMock).toHaveBeenCalledWith(
        'token',
        'task-1',
        { assignee_user_id: 'new-owner' },
        6,
      )
    })
  })

  it('refuses reassign when the target user id is empty and surfaces the validation message', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['reassign'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Reassign' }))
    fireEvent.click(screen.getByRole('button', { name: 'Confirm reassignment' }))
    expect(updateMock).not.toHaveBeenCalled()
    expect(await screen.findByText('A new assignee user id is required.')).toBeTruthy()
  })

  it('surfaces a server error from a reassign inline', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['reassign'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    updateMock.mockRejectedValueOnce(problem(422, 'out of scope'))
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Reassign' }))
    fillById('task-reassign-target', 'someone')
    fireEvent.click(screen.getByRole('button', { name: 'Confirm reassignment' }))
    expect(await screen.findByText('out of scope')).toBeTruthy()
  })

  it('adds a participant via the wrapper and refreshes the metadata', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['add-participant'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    addParticipantMock.mockResolvedValue(
      makeTask({ participant_user_ids: ['p-1', 'p-2', 'p-3'], lock_version: 2 }),
    )
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Add participant' }))
    fillById('task-add-participant', 'p-3')
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    await waitFor(() => {
      expect(addParticipantMock).toHaveBeenCalledWith('token', 'task-1', { user_id: 'p-3' })
    })
    expect(await screen.findByText('p-3')).toBeTruthy()
  })

  it('refuses add-participant when the user id is empty', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['add-participant'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Add participant' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    expect(addParticipantMock).not.toHaveBeenCalled()
    expect(await screen.findByText('A new assignee user id is required.')).toBeTruthy()
  })

  it('adds a comment via the wrapper and renders it in the comments list', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['comment'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    addCommentMock.mockResolvedValue({
      id: 'c-new',
      author_user_id: 'user',
      body: 'Hello @assignee-1',
    })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Comment' }))
    fillById('task-add-comment', 'Hello @assignee-1')
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    await waitFor(() => {
      expect(addCommentMock).toHaveBeenCalledWith('token', 'task-1', {
        body: 'Hello @assignee-1',
      })
    })
    expect(await screen.findByText('Hello @assignee-1')).toBeTruthy()
  })

  it('refuses to post an empty comment', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['comment'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Comment' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    expect(addCommentMock).not.toHaveBeenCalled()
    expect(await screen.findByText('Please check the required fields.')).toBeTruthy()
  })

  it('attaches a document via the wrapper and updates the attachments panel', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['attach-document'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    attachDocumentMock.mockResolvedValue(
      makeTask({
        attachments: [{ document_id: 'doc-7', title: 'New attachment' }],
        lock_version: 2,
      }),
    )
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Attach document' }))
    fillById('task-attach-document', 'doc-7')
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    await waitFor(() => {
      expect(attachDocumentMock).toHaveBeenCalledWith('token', 'task-1', 'doc-7')
    })
    expect(await screen.findByText('New attachment')).toBeTruthy()
  })

  it('refuses to attach when the document id is empty', async () => {
    getTaskMock.mockResolvedValue(makeTask({ allowed_actions: ['attach-document'], lock_version: 1 }))
    listCommentsMock.mockResolvedValue({ items: [], total: 0 })
    render(<TaskDetail locale="en" session={session} taskId="task-1" scopeReady scopeEpoch={0} />)
    await screen.findByText('My task')
    fireEvent.click(screen.getByRole('button', { name: 'Attach document' }))
    fireEvent.click(screen.getByRole('button', { name: 'Apply' }))
    expect(attachDocumentMock).not.toHaveBeenCalled()
    expect(await screen.findByText('Please check the required fields.')).toBeTruthy()
  })

  it('never imports the generated client or uses raw fetch', () => {
    const source = TaskDetail.toString()
    expect(source).not.toMatch(/generated\/cluster/)
    expect(source).not.toMatch(/\bfetch\s*\(/)
  })
})