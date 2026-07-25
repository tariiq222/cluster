import { describe, expect, it } from 'vitest'
import type { OrganizationUnit } from '../../api'
import {
  buildOrganizationTree,
  computeDescendantsByUnit,
  computeHiddenIds,
  countPositionsByBranch,
  defaultExpandedNodes,
  flattenOrganizationTree,
  wouldCreateCycle,
} from './tree'

const uuid = (suffix: string) => `018f6f7d-0c00-7000-8000-${suffix.padEnd(12, '0')}`

const clusterId = uuid('0000000c113e')
const facilityId = uuid('000000000011')

function unit(opts: {
  id: string
  code: string
  parentId?: string
  parentType?: 'cluster' | 'facility' | 'unit'
  depth?: number
  pathCache?: string
  nameAr?: string
}): OrganizationUnit {
  const id = opts.id
  const depth = opts.depth ?? 1
  return {
    id,
    cluster_id: clusterId,
    parent_id: opts.parentId ?? (opts.parentType === 'cluster' ? clusterId : facilityId),
    parent_type: opts.parentType ?? 'facility',
    type_code: depth === 1 ? 'sector' : depth === 2 ? 'department' : 'unit',
    code: opts.code,
    name_ar: opts.nameAr ?? opts.code,
    name_en: null,
    status: 'active',
    path_cache: opts.pathCache ?? `/${opts.code}`,
    depth,
    lock_version: 1,
  }
}

describe('buildOrganizationTree', () => {
  it('anchors units under cluster/facility parents at the root level', () => {
    const sector = unit({ id: uuid('000000000001'), code: 'A-SECTOR', parentType: 'cluster', depth: 1 })
    const dept = unit({ id: uuid('000000000002'), code: 'A-DEPT', parentType: 'unit', parentId: sector.id, depth: 2 })
    const unit1 = unit({ id: uuid('000000000003'), code: 'A-UNIT-1', parentType: 'unit', parentId: dept.id, depth: 3 })
    const unit2 = unit({ id: uuid('000000000004'), code: 'B-UNIT-1', parentType: 'cluster', depth: 1 })

    const roots = buildOrganizationTree([unit1, dept, sector, unit2])
    expect(roots.map((n) => n.unit.code)).toEqual(['A-SECTOR', 'B-UNIT-1'])

    const sectorNode = roots[0]
    if (!sectorNode) {
      throw new Error('buildOrganizationTree should produce at least one root node for the sector anchor')
    }
    expect(sectorNode.children.map((c) => c.unit.code)).toEqual(['A-DEPT'])
    const sectorChild = sectorNode.children[0]
    if (!sectorChild) {
      throw new Error('sector should have at least one child unit')
    }
    expect(sectorChild.children.map((c) => c.unit.code)).toEqual(['A-UNIT-1'])
  })

  it('promotes orphans to the root so the operator never loses a row', () => {
    const orphan = unit({
      id: uuid('000000000005'), code: 'ORPHAN', parentType: 'unit',
      parentId: uuid('fffffffffff0'), depth: 2,
    })
    const roots = buildOrganizationTree([orphan])
    expect(roots).toHaveLength(1)
    const orphanRoot = roots[0]
    if (!orphanRoot) {
      throw new Error('buildOrganizationTree should promote orphans to the root level')
    }
    expect(orphanRoot.unit.code).toBe('ORPHAN')
  })

  it('aggregates descendant and self ids for cycle checks', () => {
    const a = unit({ id: uuid('000000000001'), code: 'A', parentType: 'cluster', depth: 1 })
    const b = unit({ id: uuid('000000000002'), code: 'B', parentType: 'unit', parentId: a.id, depth: 2 })
    const c = unit({ id: uuid('000000000003'), code: 'C', parentType: 'unit', parentId: b.id, depth: 3 })
    const roots = buildOrganizationTree([a, b, c])
    const aNode = roots[0]
    if (!aNode) {
      throw new Error('buildOrganizationTree should produce a root node for the cycle-check fixture')
    }
    expect(aNode.allIds).toEqual([a.id, b.id, c.id])
    expect(aNode.descendantIds).toEqual([b.id, c.id])
  })
})

describe('flattenOrganizationTree', () => {
  it('emits depth-first rows honouring the expansion set', () => {
    const a = unit({ id: uuid('000000000001'), code: 'A', parentType: 'cluster', depth: 1 })
    const b = unit({ id: uuid('000000000002'), code: 'B', parentType: 'unit', parentId: a.id, depth: 2 })
    const c = unit({ id: uuid('000000000003'), code: 'C', parentType: 'unit', parentId: b.id, depth: 3 })
    const roots = buildOrganizationTree([a, b, c])

    const collapsed = flattenOrganizationTree(roots, new Set())
    expect(collapsed.map((r) => [r.node.unit.code, r.depth, r.hasChildren])).toEqual([
      ['A', 1, true],
    ])

    const expandedAll = flattenOrganizationTree(roots, new Set([a.id, b.id]))
    expect(expandedAll.map((r) => [r.node.unit.code, r.depth])).toEqual([
      ['A', 1], ['B', 2], ['C', 3],
    ])
  })
})

describe('cycle detection', () => {
  it('refuses to make a node its own descendant', () => {
    const a = unit({ id: uuid('000000000001'), code: 'A', parentType: 'cluster', depth: 1 })
    const b = unit({ id: uuid('000000000002'), code: 'B', parentType: 'unit', parentId: a.id, depth: 2 })
    const c = unit({ id: uuid('000000000003'), code: 'C', parentType: 'unit', parentId: b.id, depth: 3 })
    const all = [a, b, c]

    expect(wouldCreateCycle(all, a.id, a.id)).toBe(true)
    expect(wouldCreateCycle(all, a.id, b.id)).toBe(true)
    expect(wouldCreateCycle(all, a.id, c.id)).toBe(true)
    expect(wouldCreateCycle(all, b.id, a.id)).toBe(false)
  })
})

describe('position counts per branch', () => {
  it('rolls up direct and descendant position counts', () => {
    const a = unit({ id: uuid('000000000001'), code: 'A', parentType: 'cluster', depth: 1 })
    const b = unit({ id: uuid('000000000002'), code: 'B', parentType: 'unit', parentId: a.id, depth: 2 })
    const c = unit({ id: uuid('000000000003'), code: 'C', parentType: 'unit', parentId: b.id, depth: 3 })
    const positions = [
      { organization_unit_id: a.id },
      { organization_unit_id: b.id },
      { organization_unit_id: c.id },
    ]
    const counts = countPositionsByBranch(positions, [a, b, c])
    expect(counts.get(a.id)).toBe(3)
    expect(counts.get(b.id)).toBe(2)
    expect(counts.get(c.id)).toBe(1)
  })
})

describe('default expansion', () => {
  it('expands the first two levels out of the box', () => {
    const units = [
      unit({ id: uuid('000000000001'), code: 'A', parentType: 'cluster', depth: 1 }),
      unit({ id: uuid('000000000002'), code: 'B', parentType: 'cluster', depth: 1 }),
      unit({ id: uuid('000000000003'), code: 'C', parentType: 'unit', parentId: uuid('000000000001'), depth: 2 }),
    ] as const
    const expanded = defaultExpandedNodes([...units])
    for (const u of units) {
      if (u.depth <= 2) expect(expanded.has(u.id)).toBe(true)
    }
    expect(expanded.has(units[2].id)).toBe(true)
  })
})

describe('descendant map', () => {
  it('lists every direct and transitive descendant under each unit id', () => {
    const root = uuid('000000000001')
    const child = uuid('000000000002')
    const grand = uuid('000000000003')
    const great = uuid('000000000004')
    const sibling = uuid('000000000005')
    const units = [
      unit({ id: root, code: 'ROOT', parentType: 'cluster', depth: 1 }),
      unit({ id: child, code: 'CHILD', parentType: 'unit', parentId: root, depth: 2 }),
      unit({ id: grand, code: 'GRAND', parentType: 'unit', parentId: child, depth: 3 }),
      unit({ id: great, code: 'GREAT', parentType: 'unit', parentId: grand, depth: 4 }),
      unit({ id: sibling, code: 'SIBLING', parentType: 'cluster', depth: 1 }),
    ]
    const descendants = computeDescendantsByUnit(units)
    expect(new Set(descendants.get(root) ?? [])).toEqual(new Set([child, grand, great]))
    expect(new Set(descendants.get(child) ?? [])).toEqual(new Set([grand, great]))
    expect(new Set(descendants.get(grand) ?? [])).toEqual(new Set([great]))
    expect(descendants.get(sibling)).toEqual([])
    expect(descendants.get(great)).toEqual([])
  })
})

describe('hidden id projection', () => {
  it('collapses every ancestor and every descendant under it', () => {
    const root = uuid('000000000001')
    const child = uuid('000000000002')
    const grand = uuid('000000000003')
    const sibling = uuid('000000000004')
    const units = [
      unit({ id: root, code: 'ROOT', parentType: 'cluster', depth: 1 }),
      unit({ id: child, code: 'CHILD', parentType: 'unit', parentId: root, depth: 2 }),
      unit({ id: grand, code: 'GRAND', parentType: 'unit', parentId: child, depth: 3 }),
      unit({ id: sibling, code: 'SIBLING', parentType: 'cluster', depth: 1 }),
    ]
    const descendants = computeDescendantsByUnit(units)
    const hidden = computeHiddenIds(new Set([root]), descendants)
    expect(hidden).toEqual(new Set([child, grand]))
    expect(hidden.has(sibling)).toBe(false)
  })

  it('silently ignores unknown collapsed ids', () => {
    const root = uuid('000000000001')
    const units = [unit({ id: root, code: 'ROOT', parentType: 'cluster', depth: 1 })]
    const descendants = computeDescendantsByUnit(units)
    const hidden = computeHiddenIds(new Set([uuid('999999999999')]), descendants)
    expect(hidden.size).toBe(0)
  })
})
