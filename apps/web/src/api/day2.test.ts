import { afterEach, describe, expect, it, vi } from 'vitest'
import { createWorkflowDefinition, createTaskFromStep, publishWorkDefinitionVersion, returnRequest, startWorkflow, transitionTask } from './r1'

function requireMockCall(calls: unknown[][], index: number): [unknown, RequestInit] {
  const call = calls[index]
  if (!call || call.length < 2 || call[1] === undefined) throw new Error(`Expected fetch call ${index + 1}`)
  return [call[0], call[1] as RequestInit]
}

describe('day2 transport', () => {
  afterEach(() => vi.restoreAllMocks())
  it('unwraps workflow definition/version envelope and sends correlation/idempotency', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { definition: { id: 'd' }, version: { id: 'v', lock_version: 1 } } }), { status: 201, headers: { ETag: '"1"' } })))
    const result = await createWorkflowDefinition('token', { code: 'x', name: 'X', source_record_type: 'request' })
    expect(result.version.id).toBe('v'); const init = requireMockCall((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls, 0)[1] as RequestInit; expect(new Headers(init.headers).get('Idempotency-Key')).toBeTruthy(); expect(new Headers(init.headers).get('X-Correlation-ID')).toMatch(/-7/)
  })
  it('uses If-Match and the from-step route', async () => {
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ data: { id: 't', lock_version: 2 } }), { status: 200 }))))
    await publishWorkDefinitionVersion('token', 'v', 1); await createTaskFromStep('token', 's', 'Task'); await returnRequest('token', 'r', 3); await transitionTask('token', 't', 'complete', 2)
    const calls = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls; const call0 = requireMockCall(calls, 0); const call1 = requireMockCall(calls, 1); const call2 = requireMockCall(calls, 2); const call3 = requireMockCall(calls, 3); expect(call0[0]).toContain('/work-definition-versions/v/publish'); expect(new Headers(call0[1].headers).get('If-Match')).toBe('"1"'); expect(call1[0]).toContain('/tasks/from-step/s'); expect(call2[0]).toContain('/work-records/r/return'); expect(call3[0]).toContain('/tasks/t/complete')
  })
  it('starts the day2 workflow against the work-records contract', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 'i' } }), { status: 201 })))
    await startWorkflow('token', {
      workflow_version_id: '019f7000-0000-7000-8000-000000000001',
      source_module: 'work_records',
      record_type: 'work_record',
      record_id: '019f7000-0000-7000-8000-000000000002',
    })
    const init = requireMockCall((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls, 0)[1] as RequestInit
    expect(JSON.parse(String(init.body))).toMatchObject({ source_module: 'work_records', record_type: 'work_record' })
  })
})
