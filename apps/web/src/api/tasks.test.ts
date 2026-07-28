import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  addTaskComment,
  addTaskParticipant,
  attachTaskDocument,
  createTask,
  getTask,
  listTaskComments,
  listTasks,
  transitionTask,
  updateTask,
} from './tasks'

const token = 'csrf-token'
const taskId = '018f6f7d-0c00-7000-8000-000000000001'
const userId = '018f6f7d-0c00-7000-8000-000000000002'
const documentId = '018f6f7d-0c00-7000-8000-000000000003'
const commentId = '018f6f7d-0c00-7000-8000-000000000004'

function jsonResponse(data: unknown, status = 200, etag = '"2"'): Response {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json', ETag: etag },
  })
}

function problemResponse(problem: unknown, status: number): Response {
  return new Response(JSON.stringify(problem), {
    status,
    headers: { 'Content-Type': 'application/problem+json' },
  })
}

function requireFetchCall(
  fetchMock: { mock: { calls: Parameters<typeof fetch>[] } },
  index: number,
): Parameters<typeof fetch> {
  const call = fetchMock.mock.calls[index]
  if (!call) throw new Error(`Expected fetch call ${index + 1}`)
  return call
}

function requireHeader(call: Parameters<typeof fetch>[number], name: string): string | null {
  return new Headers(call[1]?.headers).get(name)
}

afterEach(() => vi.unstubAllGlobals())

describe('tasks API boundary', () => {
  it('lists tasks with PAGE_LIMIT and forwards filters through the generated client', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [{ id: taskId, resource_type: 'task' }], next_cursor: null }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      listTasks(token, { state: 'open', relationship: 'assigned' }),
    ).resolves.toMatchObject({ items: [expect.objectContaining({ resource_type: 'task' })] })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(
      `/api/v1/tasks?limit=20&state=open&relationship=assigned`,
    )
    expect(call[1]).toMatchObject({ credentials: 'include', method: 'GET' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBeNull()
    expect(requireHeader(call, 'Idempotency-Key')).toBeNull()
    expect(requireHeader(call, 'X-Correlation-ID')).toMatch(/^[0-9a-f-]+$/)
  })

  it('creates a task with CSRF + idempotency, no If-Match', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task' }, 201, '"1"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      createTask(token, {
        title: 'Self-task',
        description: 'doc',
        assignee_user_id: userId,
        priority: 'high',
      }),
    ).resolves.toMatchObject({ id: taskId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe('/api/v1/tasks')
    expect(call[1]).toMatchObject({ method: 'POST' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBe(token)
    expect(requireHeader(call, 'Idempotency-Key')).toMatch(/^task-/)
    expect(requireHeader(call, 'If-Match')).toBeNull()
    expect(JSON.parse(String(call[1]?.body))).toMatchObject({
      title: 'Self-task',
      assignee_user_id: userId,
      priority: 'high',
    })
  })

  it('reads an authorized task through the generated client without mutation headers', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task' }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(getTask(token, taskId)).resolves.toMatchObject({ id: taskId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}`)
    expect(call[1]).toMatchObject({ method: 'GET' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBeNull()
    expect(requireHeader(call, 'Idempotency-Key')).toBeNull()
    expect(requireHeader(call, 'If-Match')).toBeNull()
  })

  it('sends CSRF + If-Match for an update without command idempotency', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task' }, 200, '"2"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      updateTask(token, taskId, { title: 'Rename', priority: 'urgent' }, 1),
    ).resolves.toMatchObject({ id: taskId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}`)
    expect(call[1]).toMatchObject({ method: 'PATCH' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBe(token)
    expect(requireHeader(call, 'If-Match')).toBe('"1"')
    expect(requireHeader(call, 'Content-Type')).toBe('application/merge-patch+json')
    expect(requireHeader(call, 'Idempotency-Key')).toBeNull()
  })

  it('transitions a task with CSRF + If-Match + idempotency and ships the body', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task', state: 'completed' }, 200, '"4"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      transitionTask(
        token,
        taskId,
        'complete',
        { note: 'handover doc attached' },
        3,
      ),
    ).resolves.toMatchObject({ state: 'completed' })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}/complete`)
    expect(call[1]).toMatchObject({ method: 'POST' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBe(token)
    expect(requireHeader(call, 'Idempotency-Key')).toMatch(/^[0-9a-f-]+$/)
    expect(requireHeader(call, 'If-Match')).toBe('"3"')
    expect(JSON.parse(String(call[1]?.body))).toEqual({ note: 'handover doc attached' })
  })

  it('omits If-Match when no lock version is supplied to a transition', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task' }, 200, '"3"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      transitionTask(token, taskId, 'start'),
    ).resolves.toMatchObject({ id: taskId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}/start`)
    expect(requireHeader(call, 'If-Match')).toBeNull()
    expect(requireHeader(call, 'Idempotency-Key')).toMatch(/^[0-9a-f-]+$/)
  })

  it('adds a participant with a distinct idempotency prefix', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task' }, 200, '"2"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      addTaskParticipant(token, taskId, { user_id: userId, role: 'reviewer' }),
    ).resolves.toMatchObject({ id: taskId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}/participants`)
    expect(call[1]).toMatchObject({ method: 'POST' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBe(token)
    expect(requireHeader(call, 'Idempotency-Key')).toMatch(/^task-participant-/)
    expect(JSON.parse(String(call[1]?.body))).toEqual({ user_id: userId, role: 'reviewer' })
  })

  it('lists comments with PAGE_LIMIT and no CSRF/idempotency', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ items: [{ id: commentId }], next_cursor: null }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      listTaskComments(token, taskId, { cursor: 'c1' }),
    ).resolves.toMatchObject({ items: [expect.objectContaining({ id: commentId })] })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}/comments?limit=20&cursor=c1`)
    expect(call[1]).toMatchObject({ method: 'GET' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBeNull()
    expect(requireHeader(call, 'Idempotency-Key')).toBeNull()
  })

  it('adds a comment with task-comment idempotency and a body that carries mentions', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: commentId, resource_type: 'task_comment' }, 201, '"3"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      addTaskComment(token, taskId, {
        body: 'FYI',
        mentioned_user_ids: [userId],
      }),
    ).resolves.toMatchObject({ id: commentId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}/comments`)
    expect(call[1]).toMatchObject({ method: 'POST' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBe(token)
    expect(requireHeader(call, 'Idempotency-Key')).toMatch(/^task-comment-/)
    expect(JSON.parse(String(call[1]?.body))).toEqual({
      body: 'FYI',
      mentioned_user_ids: [userId],
    })
  })

  it('attaches a document with task-attachment idempotency', async () => {
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockResolvedValueOnce(jsonResponse({ id: taskId, resource_type: 'task' }, 201, '"5"'))
    vi.stubGlobal('fetch', fetchMock)

    await expect(
      attachTaskDocument(token, taskId, documentId),
    ).resolves.toMatchObject({ id: taskId })

    const call = requireFetchCall(fetchMock, 0)
    expect(call[0]).toBe(`/api/v1/tasks/${taskId}/documents`)
    expect(call[1]).toMatchObject({ method: 'POST' })
    expect(requireHeader(call, 'X-CSRF-Token')).toBe(token)
    expect(requireHeader(call, 'Idempotency-Key')).toMatch(/^task-attachment-/)
    expect(JSON.parse(String(call[1]?.body))).toEqual({ document_id: documentId })
  })

  it('does not call fetch directly — every wrapper goes through the generated client', () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({}))
    vi.stubGlobal('fetch', fetchMock)

    expect(/fetch\(/.test(getTask.toString())).toBe(false)
    expect(/fetch\(/.test(listTasks.toString())).toBe(false)
    expect(/fetch\(/.test(createTask.toString())).toBe(false)
    expect(/fetch\(/.test(transitionTask.toString())).toBe(false)
    expect(/fetch\(/.test(attachTaskDocument.toString())).toBe(false)
  })

  it('surfaces a 412 stale precondition as an ApiError instead of pretending it succeeded', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValueOnce(
        problemResponse({ title: 'Precondition Failed', status: 412 }, 412),
      ),
    )

    await expect(transitionTask(token, taskId, 'complete', { note: 'x' }, 9)).rejects.toMatchObject({
      name: 'ApiError',
      status: 412,
    })
  })

  it('surfaces a 403 forbidden response as an ApiError', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValueOnce(
        problemResponse({ type: 'forbidden', title: 'Forbidden', status: 403, detail: 'no access' }, 403),
      ),
    )

    await expect(getTask(token, taskId)).rejects.toMatchObject({
      name: 'ApiError',
      status: 403,
      problem: { type: 'forbidden' },
    })
  })
})
