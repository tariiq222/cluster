// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import type { Session } from '../../api'
import { getWorkflowInstance } from './workflow-api'
import { MyRequestDetail } from './MyRequestDetail'
vi.mock('./workflow-api', () => ({ getWorkflowInstance: vi.fn() }))
const session = { access_token: 'token', user_id: 'user' } as unknown as Session
const mock = vi.mocked(getWorkflowInstance)
afterEach(() => { cleanup(); vi.clearAllMocks() })
describe('MyRequestDetail', () => {
  it('shows loading', () => { mock.mockReturnValue(new Promise(() => undefined)); render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />); expect(screen.getByLabelText('Loading requests…')).toBeTruthy() })
  it('shows empty', async () => { mock.mockResolvedValue({ instance: { id: 'i', state: 'active' }, steps: [] }); render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />); expect(await screen.findByText('No details are available for this record.')).toBeTruthy() })
  it('shows success', async () => { mock.mockResolvedValue({ instance: { id: 'i', state: 'active' }, steps: [{ id: 's', state: 'waiting', node_key: 'review' }] }); render(<MyRequestDetail locale="en" session={session} instanceId="i" scopeReady scopeEpoch={0} />); expect(await screen.findByText('review')).toBeTruthy() })
})
