// @vitest-environment node
import { describe, expect, it, vi } from 'vitest'
import {
  listAllOrganizationPages,
  ORGANIZATION_LOOKUP_MAX_PAGES,
} from './hooks'

/*
 * Focused coverage for the bounded cursor walk that backs the
 * `useAll*` label-lookup hooks. The walk is the only thing standing
 * between a malformed cursor chain and an infinite request loop, so
 * the contract is that it must:
 *
 *  - Concatenate every page's items into a single flat array.
 *  - Return a `null` `next_cursor` exactly when the final page reports
 *    a `null` next cursor — even if the page-walk passed through pages
 *    that had their own `next_cursor`.
 *  - Reject a repeated cursor chain as an explicit error instead of
 *    looping forever.
 *  - Reject a pathologically long chain as an explicit error using
 *    `ORGANIZATION_LOOKUP_MAX_PAGES` as the upper bound.
 *
 * The walk is exported by name from `apps/web/src/api/hooks.ts` so a
 * test can drive the page fetcher with a deterministic mock — no
 * React Query, no DataTable, no Orval generated client. The page
 * fetcher contract is `(cursor?: string) => Promise<{items, next_cursor}>`
 * which is the same shape `unwrap(listFacilities(...))` produces.
 */
describe('listAllOrganizationPages', () => {
  it('concatenates every page and returns a null next cursor at the end', async () => {
    const fetchPage = vi
      .fn()
      .mockResolvedValueOnce({ items: ['a', 'b'], next_cursor: 'p2' })
      .mockResolvedValueOnce({ items: ['c'], next_cursor: 'p3' })
      .mockResolvedValueOnce({ items: ['d'], next_cursor: null })

    const result = await listAllOrganizationPages<string>(fetchPage)

    expect(result.items).toEqual(['a', 'b', 'c', 'd'])
    expect(result.next_cursor).toBeNull()
    expect(fetchPage).toHaveBeenNthCalledWith(1, undefined)
    expect(fetchPage).toHaveBeenNthCalledWith(2, 'p2')
    expect(fetchPage).toHaveBeenNthCalledWith(3, 'p3')
  })

  it('returns the only page verbatim when there is no next cursor', async () => {
    const fetchPage = vi
      .fn()
      .mockResolvedValueOnce({ items: ['only'], next_cursor: null })

    const result = await listAllOrganizationPages<string>(fetchPage)

    expect(result.items).toEqual(['only'])
    expect(result.next_cursor).toBeNull()
    expect(fetchPage).toHaveBeenCalledTimes(1)
  })

  it('rejects a repeated cursor chain instead of looping forever', async () => {
    const fetchPage = vi
      .fn()
      .mockResolvedValueOnce({ items: ['a'], next_cursor: 'repeat' })
      .mockResolvedValueOnce({ items: ['b'], next_cursor: 'repeat' })

    await expect(
      listAllOrganizationPages<string>(fetchPage),
    ).rejects.toThrow(/repeated cursor/)
  })

  it('rejects a chain longer than ORGANIZATION_LOOKUP_MAX_PAGES', async () => {
    const fetchPage = vi
      .fn()
      .mockImplementation(async (cursor?: string) => ({
        items: [cursor ?? 'start'],
        next_cursor: cursor === null || cursor === undefined ? 'p1' : `next-${cursor}`,
      }))

    await expect(
      listAllOrganizationPages<string>(fetchPage),
    ).rejects.toThrow(/safety limit/)
    expect(fetchPage).toHaveBeenCalledTimes(ORGANIZATION_LOOKUP_MAX_PAGES)
  })

  it('forwards the call cursor unchanged on each step', async () => {
    const fetchPage = vi
      .fn()
      .mockImplementation(async (cursor?: string) =>
        cursor === 'seed'
          ? { items: ['x'], next_cursor: null }
          : { items: ['a'], next_cursor: 'seed' },
      )

    const result = await listAllOrganizationPages<string>(fetchPage)

    expect(result.items).toEqual(['a', 'x'])
    expect(fetchPage).toHaveBeenNthCalledWith(1, undefined)
    expect(fetchPage).toHaveBeenNthCalledWith(2, 'seed')
  })
})
