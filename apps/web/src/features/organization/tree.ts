import type { OrganizationUnit } from '../../api'

/**
 * A node inside the organization tree. Wraps an `OrganizationUnit` with the
 * children discovered from `parent_id` edges and the cached descendant count
 * to surface drill-down hints in the UI without an extra round trip.
 */
export interface OrganizationTreeNode {
  unit: OrganizationUnit
  children: OrganizationTreeNode[]
  /** All descendant units (excluding the node itself). */
  descendantIds: string[]
  /** All descendant units including the node itself. */
  allIds: string[]
}

/**
 * Flattened tree row used by the renderer. Keeps the original node so the
 * UI can derive both the display label and the metadata without an extra
 * lookup.
 */
export interface OrganizationTreeRow {
  node: OrganizationTreeNode
  depth: number
  hasChildren: boolean
}

// Top-level units are those whose `parent_type` is `cluster` or `facility`.
// Children always have `parent_type === 'unit'`. Using a sentinel UUID would
// be incompatible with the typed API surface, so we discriminate on
// `parent_type` instead.
const NON_UNIT_PARENT_TYPES = new Set(['cluster', 'facility'])

/**
 * Build a hierarchy of organization units from the flat list returned by the
 * API. Units whose `parent_id` is the synthetic root sentinel land at the top
 * level; orphaned units are attached under root for safety so the UI never
 * silently drops rows returned by the backend.
 *
 * Result is sorted by `code` at every level for stable rendering across
 * reloads. The resulting tree has no React dependencies.
 */
export function buildOrganizationTree(units: OrganizationUnit[]): OrganizationTreeNode[] {
  const sorted = [...units].sort((a, b) => a.code.localeCompare(b.code))
  const byId = new Map<string, OrganizationUnit>()
  for (const unit of sorted) byId.set(unit.id, unit)

  const childrenOf = new Map<string, OrganizationUnit[]>()
  const roots: OrganizationUnit[] = []
  for (const unit of sorted) {
    const parentType = unit.parent_type
    if (parentType === undefined || NON_UNIT_PARENT_TYPES.has(parentType)) {
      roots.push(unit)
      continue
    }
    const parent = byId.get(unit.parent_id)
    if (parent === undefined) {
      // Orphan: a unit that points at another unit not in the page. Mount it
      // at the root so the operator sees the row and can repair the link.
      roots.push(unit)
      continue
    }
    const list = childrenOf.get(parent.id) ?? []
    list.push(unit)
    childrenOf.set(parent.id, list)
  }

  function build(unit: OrganizationUnit): OrganizationTreeNode {
    const directChildren = childrenOf.get(unit.id) ?? []
    const children = directChildren.map(build)
    const descendantIds = children.flatMap((child) => child.allIds)
    const allIds = [unit.id, ...descendantIds]
    return { unit, children, descendantIds, allIds }
  }

  return roots.map(build)
}

/**
 * Walk the tree depth-first and emit flat rows for rendering. The expanded
 * set is read-only here; the caller owns the state to keep this function
 * pure and easy to test.
 */
export function flattenOrganizationTree(
  roots: OrganizationTreeNode[],
  expandedIds: ReadonlySet<string>,
): OrganizationTreeRow[] {
  const rows: OrganizationTreeRow[] = []
  for (const root of roots) walk(root, 1, rows)
  return rows

  function walk(node: OrganizationTreeNode, depth: number, out: OrganizationTreeRow[]): void {
    out.push({ node, depth, hasChildren: node.children.length > 0 })
    if (node.children.length > 0 && expandedIds.has(node.unit.id)) {
      for (const child of node.children) walk(child, depth + 1, out)
    }
  }
}

/**
 * Cycle detection for "reparent this node under that node" actions. The
 * integrity rule is that a node cannot become its own descendant; a parent
 * cycle (A → B → A) is the canonical bug this guards against.
 */
export function wouldCreateCycle(
  units: OrganizationUnit[],
  childId: string,
  parentId: string,
): boolean {
  if (childId === parentId) return true
  const roots = buildOrganizationTree(units)
  const child = findNode(roots, childId)
  if (child === null) return false
  return child.allIds.includes(parentId)
}

function findNode(roots: OrganizationTreeNode[], id: string): OrganizationTreeNode | null {
  for (const root of roots) {
    if (root.unit.id === id) return root
    const nested = findNode(root.children, id)
    if (nested !== null) return nested
  }
  return null
}

/**
 * Count positions per unit, aggregating the positions of every descendant
 * unit so the UI can show "X مناصب" on a branch header without expanding it.
 * Uses `level` so adding levels later doesn't change the meaning.
 */
export function countPositionsByBranch(
  positions: ReadonlyArray<{ organization_unit_id: string }>,
  units: OrganizationUnit[],
): Map<string, number> {
  const roots = buildOrganizationTree(units)
  const counts = new Map<string, number>()
  const direct = new Map<string, number>()
  for (const position of positions) {
    direct.set(position.organization_unit_id, (direct.get(position.organization_unit_id) ?? 0) + 1)
  }
  for (const root of roots) aggregate(root)
  return counts

  function aggregate(node: OrganizationTreeNode): number {
    let total = direct.get(node.unit.id) ?? 0
    for (const child of node.children) total += aggregate(child)
    counts.set(node.unit.id, total)
    return total
  }
}

/**
 * Initial expansion strategy: include the first two levels so the screen has
 * signal on first paint without forcing the user to click to discover the
 * tree. Pure function so the caller can replace it with user persistence
 * later without changing the tree implementation.
 */
export function defaultExpandedNodes(units: OrganizationUnit[]): Set<string> {
  const sorted = [...units].sort((a, b) => a.code.localeCompare(b.code))
  const expanded = new Set<string>()
  for (const unit of sorted) if (unit.depth <= 2) expanded.add(unit.id)
  return expanded
}

/**
 * Map every unit id to its full list of descendant unit ids (one level and
 * transitive). Returned lists do not include the unit itself. The map covers
 * every unit id even when it has no descendants, mapped to an empty array,
 * so callers can rely on `descendantsByUnit.get(id)` without nullability.
 */
export function computeDescendantsByUnit(units: OrganizationUnit[]): Map<string, string[]> {
  const childrenByParent = new Map<string, string[]>()
  for (const unit of units) {
    if (unit.parent_type !== 'unit') continue
    const list = childrenByParent.get(unit.parent_id) ?? []
    list.push(unit.id)
    childrenByParent.set(unit.parent_id, list)
  }
  const result = new Map<string, string[]>()
  function walk(id: string): string[] {
    const direct = childrenByParent.get(id) ?? []
    const nested = direct.flatMap(walk)
    const total = [...direct, ...nested]
    result.set(id, total)
    return total
  }
  for (const unit of units) walk(unit.id)
  return result
}

/**
 * Given a set of collapsed ancestor ids and a descendant map, compute the
 * complete set of ids that should be hidden from the rendered tree (i.e.
 * the collapsed ancestors plus every descendant of each). Ancestors with no
 * descendants contribute nothing; the returned set never includes ids that
 * are not in `descendantsByUnit`.
 */
export function computeHiddenIds(
  collapsedIds: Set<string>,
  descendantsByUnit: Map<string, string[]>,
): Set<string> {
  const hidden = new Set<string>()
  for (const id of collapsedIds) {
    const descendants = descendantsByUnit.get(id)
    if (descendants === undefined) continue
    for (const descendant of descendants) hidden.add(descendant)
  }
  return hidden
}
