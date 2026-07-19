import { afterEach, describe, expect, it, vi } from 'vitest'
import { createWorkflowDefinition, createTaskFromStep, publishWorkDefinitionVersion, returnRequest, transitionTask } from './r1'

describe('day2 transport', () => {
  afterEach(() => vi.restoreAllMocks())
  it('unwraps workflow definition/version envelope and sends correlation/idempotency', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { definition: { id: 'd' }, version: { id: 'v', lock_version: 1 } } }), { status: 201, headers: { ETag: '"1"' } })))
    const result = await createWorkflowDefinition('token', { code: 'x', name: 'X', source_record_type: 'request' })
    expect(result.version.id).toBe('v'); const init = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0][1] as RequestInit; expect(new Headers(init.headers).get('Idempotency-Key')).toBeTruthy(); expect(new Headers(init.headers).get('X-Correlation-ID')).toMatch(/-7/)
  })
  it('uses If-Match and the from-step route', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ data: { id: 't', lock_version: 2 } }), { status: 200 }))))
    await publishWorkDefinitionVersion('token', 'v', 1); await createTaskFromStep('token', 's', 'Task'); await returnRequest('token', 'r', 3); await transitionTask('token', 't', 'complete', 2)
    const calls = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls; expect(calls[0][0]).toContain('/work-definition-versions/v/publish'); expect(new Headers(calls[0][1].headers).get('If-Match')).toBe('"1"'); expect(calls[1][0]).toContain('/tasks/from-step/s'); expect(calls[2][0]).toContain('/work-records/r/return'); expect(calls[3][0]).toContain('/tasks/t/complete')
  })
})
