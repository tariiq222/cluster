import { describe, expect, it } from 'vitest'
import type { OrganizationUnit } from '../../api'
import { autoLayoutCards, CARD_HEIGHT, CARD_WIDTH } from './board-layout'
import { buildOrganizationTree } from './tree'

const uuid = (suffix: string) => `018f6f7d-0c00-7000-8000-${suffix.padEnd(12, '0')}`

const clusterId = uuid('0000000c113e')
const facilityA = uuid('000000000011')

function unit(opts: {
  id: string
  code: string
  parentId?: string
  parentType?: 'cluster' | 'facility' | 'unit'
  depth?: number
}): OrganizationUnit {
  return {
    id: opts.id,
    cluster_id: clusterId,
    parent_id: opts.parentId ?? (opts.parentType === 'facility' ? facilityA : clusterId),
    parent_type: opts.parentType ?? 'cluster',
    type_code: 'department',
    code: opts.code,
    name_ar: opts.code,
    name_en: null,
    status: 'active',
    depth: opts.depth ?? 1,
    path_cache: '/',
    lock_version: 1,
  }
}

describe('autoLayoutCards — hierarchical tree', () => {
  it('returns an empty map for no units', () => {
    expect(autoLayoutCards([])).toEqual({})
  })

  it('positions a single leaf at the origin', () => {
    const unit_ = unit({ id: uuid('000000000001'), code: 'A' })
    const positions = autoLayoutCards([unit_])
    expect(positions[unit_.id]).toEqual({ x: 0, y: 0 })
  })

  it('centres a parent over a single child', () => {
    const parent = unit({ id: uuid('000000000010'), code: 'P' })
    const child = unit({ id: uuid('000000000020'), code: 'C', parentId: parent.id, parentType: 'unit', depth: 2 })
    const positions = autoLayoutCards([parent, child])
    expect(positions[parent.id].x).toBe(0)
    expect(positions[child.id].x).toBe(0)
    expect(positions[child.id].y).toBeGreaterThan(positions[parent.id].y + CARD_HEIGHT / 2)
  })

  it('centres a parent over its children span', () => {
    const parent = unit({ id: uuid('000000000010'), code: 'P' })
    const c1 = unit({ id: uuid('000000000020'), code: 'C1', parentId: parent.id, parentType: 'unit', depth: 2 })
    const c2 = unit({ id: uuid('000000000030'), code: 'C2', parentId: parent.id, parentType: 'unit', depth: 2 })
    const positions = autoLayoutCards([parent, c1, c2])
    const parentCenter = positions[parent.id].x + CARD_WIDTH / 2
    const c1Center = positions[c1.id].x + CARD_WIDTH / 2
    const c2Center = positions[c2.id].x + CARD_WIDTH / 2
    expect(parentCenter).toBeCloseTo((c1Center + c2Center) / 2, 5)
  })

  it('lays out multiple roots side by side in alphabetical order', () => {
    const a = unit({ id: uuid('000000000001'), code: 'A' })
    const b = unit({ id: uuid('000000000002'), code: 'B' })
    const positions = autoLayoutCards([a, b])
    expect(positions[a.id].x).toBeLessThan(positions[b.id].x)
  })

  it('places every child strictly below its parent', () => {
    const root = unit({ id: uuid('000000000010'), code: 'ROOT' })
    const c1 = unit({ id: uuid('000000000020'), code: 'C1', parentId: root.id, parentType: 'unit', depth: 2 })
    const c2 = unit({ id: uuid('000000000030'), code: 'C2', parentId: root.id, parentType: 'unit', depth: 2 })
    const gc = unit({ id: uuid('000000000040'), code: 'GC', parentId: c1.id, parentType: 'unit', depth: 3 })
    const positions = autoLayoutCards([root, c1, c2, gc])
    for (const [childId, parentId] of [[c1.id, root.id], [c2.id, root.id], [gc.id, c1.id]] as const) {
      expect(positions[childId].y).toBeGreaterThan(positions[parentId].y + CARD_HEIGHT / 2)
    }
  })

  it('uses buildOrganizationTree to discover children', () => {
    const units = [unit({ id: uuid('000000000010'), code: 'A' })]
    const tree = buildOrganizationTree(units)
    expect(tree).toHaveLength(1)
    expect(tree[0].children).toEqual([])
  })
})