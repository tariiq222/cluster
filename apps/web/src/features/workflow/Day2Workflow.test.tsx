import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

describe('Day2Workflow production journey safeguards', () => {
  const source = readFileSync(new URL('./Day2Workflow.tsx', import.meta.url), 'utf8')

  it('does not embed environment-seeded UUIDs or Day 3 acceptance evidence', () => {
    expect(source).not.toMatch(/019f[0-9a-f-]{32,}/i)
    expect(source).not.toContain('finishDay3')
    expect(source).not.toContain('getDashboard')
    expect(source).not.toContain('linkDocument')
  })

  it('gates request submission on a published workflow version', () => {
    expect(source).toContain('disabled={busy || !workflowVersion}')
    expect(source).toContain("!workflowVersion?.id")
    expect(source).toContain("candidate.status === 'published'")
  })
})
