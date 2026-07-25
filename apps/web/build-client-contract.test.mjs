/**
 * Contract tests for `mergeClientSurface` (the precedence + collision rules
 * the client surface depends on). These rules are the same ones Task 2 of the
 * audit plan required to be explicit and tested:
 *
 *   1. Later bundles override earlier ones; the master has the lowest priority.
 *   2. A path that appears in more than one bundle is tracked as an override.
 *   3. Two operations sharing the same `operationId` (whether on the same path
 *      or different paths) fail the merge.
 *
 * The script wrapper in `build-client-contract.mjs` reads the real bundles
 * from `.orval/` and writes the merged surface; the function under test is
 * pure and accepts in-memory bundles so the precedence/collision contract
 * stays pinned even when the real bundles change.
 */
import { describe, expect, it } from 'vitest'

import { mergeClientSurface } from './build-client-contract.mjs'

const master = {
  info: { title: 'master' },
  paths: {
    '/a': {
      get: { operationId: 'getA' },
    },
    '/b': {
      get: { operationId: 'getB' },
    },
  },
  components: { parameters: { CorrelationId: { name: 'X-Correlation-ID' } } },
}

const milestone = {
  paths: {
    '/b': {
      get: { operationId: 'getB', summary: 'milestone version' },
    },
    '/c': {
      get: { operationId: 'getC' },
    },
  },
  components: { parameters: { Limit: { name: 'Limit' } } },
}

describe('mergeClientSurface', () => {
  it('overrides earlier paths with later ones and tracks the override', () => {
    const { merged, overrides } = mergeClientSurface({
      bundles: [master, milestone],
    })

    expect(overrides).toEqual(['/b'])
    expect(merged.paths['/b'].get.summary).toBe('milestone version')
    expect(merged.paths['/a'].get.operationId).toBe('getA')
    expect(merged.paths['/c'].get.operationId).toBe('getC')
  })

  it('treats the first bundle as the master (lowest precedence)', () => {
    const { merged, overrides } = mergeClientSurface({
      bundles: [master, milestone],
      info: { title: 'overridden' },
    })

    expect(merged.info.title).toBe('overridden')
    expect(overrides).toEqual(['/b'])
  })

  it('merges components group-by-group with later winning', () => {
    const { merged } = mergeClientSurface({ bundles: [master, milestone] })

    expect(merged.components.parameters).toEqual({
      CorrelationId: { name: 'X-Correlation-ID' },
      Limit: { name: 'Limit' },
    })
  })

  it('fails closed on duplicate operationIds across paths', () => {
    const duplicate = {
      paths: {
        '/d': { get: { operationId: 'getA' } },
      },
    }

    expect(() =>
      mergeClientSurface({ bundles: [master, duplicate], strict: true }),
    ).toThrow(/duplicate operationIds.*getA/)
  })

  it('returns the duplicate list when not in strict mode', () => {
    const duplicate = {
      paths: {
        '/d': { get: { operationId: 'getA' } },
      },
    }

    const { duplicateOperationIds } = mergeClientSurface({
      bundles: [master, duplicate],
    })

    expect(duplicateOperationIds).toEqual(['getA'])
  })

  it('preserves the exact path-level override the milestone declares', () => {
    // Mirrors the audit scenario where the master declares a planned
    // /business-calendars path and the milestone (the route-serving surface)
    // overrides it with a route-aligned operation. The merge must record
    // the override so drift is visible, not silent, and the milestone
    // operationId replaces the master's on that path.
    const masterWithPlanned = {
      info: { title: 'master' },
      paths: {
        '/business-calendars': {
          get: { operationId: 'listBusinessCalendars' },
        },
      },
    }
    const milestoneWithRoute = {
      paths: {
        '/business-calendars': {
          get: { operationId: 'listPlatformSettingsCalendars' },
        },
      },
    }

    const { merged, overrides, duplicateOperationIds } = mergeClientSurface({
      bundles: [masterWithPlanned, milestoneWithRoute],
    })

    expect(overrides).toEqual(['/business-calendars'])
    // Milestone wins: the operationId on the path is the milestone's.
    expect(merged.paths['/business-calendars'].get.operationId).toBe(
      'listPlatformSettingsCalendars',
    )
    // The master's operationId is replaced, so it does not appear in the
    // merged set and there is no collision to report.
    expect(duplicateOperationIds).toEqual([])
  })
  it('rejects an empty bundle list', () => {
    expect(() => mergeClientSurface({ bundles: [] })).toThrow(
      /at least one bundle/,
    )
  })
})
