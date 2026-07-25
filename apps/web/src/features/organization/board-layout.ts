import type { OrganizationUnit } from '../../api'
import { buildOrganizationTree, type OrganizationTreeNode } from './tree'

/**
 * Layout primitives for the free-form organization board.
 *
 * The board keeps a single mutable viewport (pan + zoom) and a per-unit
 * coordinate map. Coordinates are *world* coordinates — the renderer applies
 * the viewport transform on top of them — so the math for fit-to-screen,
 * zoom-around-point, and pan-by-delta is the same regardless of how the
 * units are laid out.
 *
 * Layout is pure: there are no React, no DOM, and no storage side effects in
 * the core helpers. The React component owns persistence (localStorage) and
 * pointer-event state.
 */

export interface BoardViewport {
  panX: number
  panY: number
  zoom: number
}

export interface BoardCardPosition {
  x: number
  y: number
}

export interface BoardLayout {
  viewport: BoardViewport
  cards: Record<string, BoardCardPosition>
}

export interface BoardEdge {
  key: string
  parentId: string
  childId: string
  from: BoardCardPosition
  to: BoardCardPosition
}

export const CARD_WIDTH = 256
export const CARD_HEIGHT = 132
export const CARD_GAP_X = 72
export const CARD_GAP_Y = 56

export const MIN_ZOOM = 0.4
export const MAX_ZOOM = 2.5

export const DEFAULT_VIEWPORT: BoardViewport = Object.freeze({
  panX: 64,
  panY: 64,
  zoom: 1,
}) as BoardViewport

const STORAGE_KEY = 'cluster.org-board.layout.v2'

const SAFE_VIEWPORT: BoardViewport = { ...DEFAULT_VIEWPORT }

function isFiniteNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}

/**
 * Clamp the zoom factor into the safe operating range.
 * - `NaN` falls back to the default zoom so a corrupted storage entry
 *   cannot crash the board.
 * - `+Infinity` collapses to the maximum, `-Infinity` to the minimum.
 */
export function clampZoom(zoom: number): number {
  if (Number.isNaN(zoom)) return SAFE_VIEWPORT.zoom
  if (!Number.isFinite(zoom)) return zoom > 0 ? MAX_ZOOM : MIN_ZOOM
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom))
}

/**
 * Build a hierarchical tree layout: each parent is centred horizontally
 * over its children, children are spaced left-to-right beneath the parent
 * with a fixed horizontal gap, and the recursion descends level by level.
 *
 * Implementation is the classic two-pass layout (subtree width measured
 * bottom-up, positions assigned top-down) so a node with a wide subtree
 * reserves the room it needs even when a sibling subtree is narrower.
 * Multiple roots are laid out side-by-side in the order produced by
 * `buildOrganizationTree` (alphabetical by code at every level).
 *
 * Pure function — the React layer merges the result with any saved layout.
 */
export function autoLayoutCards(units: OrganizationUnit[]): Record<string, BoardCardPosition> {
  const roots = buildOrganizationTree(units)
  const positions: Record<string, BoardCardPosition> = {}
  const subtreeWidth = new Map<OrganizationTreeNode, number>()

  const verticalGap = Math.max(CARD_GAP_Y, 64)
  const horizontalGap = 28
  const rootGap = 96
  const topY = 0

  function measure(node: OrganizationTreeNode): number {
    if (node.children.length === 0) {
      subtreeWidth.set(node, CARD_WIDTH)
      return CARD_WIDTH
    }
    let total = 0
    for (const child of node.children) {
      total += measure(child)
    }
    total += horizontalGap * (node.children.length - 1)
    const width = Math.max(CARD_WIDTH, total)
    subtreeWidth.set(node, width)
    return width
  }

  function place(node: OrganizationTreeNode, leftEdgeX: number, top: number): void {
    if (node.children.length === 0) {
      const width = subtreeWidth.get(node) ?? CARD_WIDTH
      positions[node.unit.id] = {
        x: leftEdgeX + width / 2 - CARD_WIDTH / 2,
        y: top,
      }
      return
    }
    const childrenTop = top + CARD_HEIGHT + verticalGap
    let childLeft = leftEdgeX
    for (const child of node.children) {
      const childWidth = subtreeWidth.get(child) ?? CARD_WIDTH
      place(child, childLeft, childrenTop)
      childLeft += childWidth + horizontalGap
    }
    const firstChild = node.children[0]
    const lastChild = node.children[node.children.length - 1]
    if (firstChild === undefined || lastChild === undefined) return
    const firstChildPos = positions[firstChild.unit.id]
    const lastChildPos = positions[lastChild.unit.id]
    if (firstChildPos === undefined || lastChildPos === undefined) return
    const spanLeft = firstChildPos.x
    const spanRight = lastChildPos.x + CARD_WIDTH
    const parentCenter = (spanLeft + spanRight) / 2
    positions[node.unit.id] = {
      x: parentCenter - CARD_WIDTH / 2,
      y: top,
    }
  }

  let offsetX = 0
  for (const root of roots) {
    measure(root)
    place(root, offsetX, topY)
    offsetX += (subtreeWidth.get(root) ?? CARD_WIDTH) + rootGap
  }

  return positions
}

/**
 * Zoom around a fixed screen-space point so the cursor stays over the same
 * world location. Math: a screen point S maps to a world point W through
 * W = (S − P) / Z, where P is pan and Z is zoom. We keep W invariant while
 * solving for the new pan.
 */
export function zoomAround(
  viewport: BoardViewport,
  factor: number,
  originX: number,
  originY: number,
): BoardViewport {
  const targetZoom = viewport.zoom * factor
  const newZoom = clampZoom(targetZoom)
  if (newZoom === viewport.zoom) return viewport
  const ratio = newZoom / viewport.zoom
  return {
    panX: originX - (originX - viewport.panX) * ratio,
    panY: originY - (originY - viewport.panY) * ratio,
    zoom: newZoom,
  }
}

/**
 * Translate the viewport by a screen-space delta. Caller passes raw pointer
 * movement in CSS pixels.
 */
export function panBy(viewport: BoardViewport, dx: number, dy: number): BoardViewport {
  return { ...viewport, panX: viewport.panX + dx, panY: viewport.panY + dy }
}

/**
 * Translate a screen-space delta into a world-space delta given the current
 * zoom. Used by card-drag so cards track the pointer 1:1 even when zoomed.
 */
export function screenDeltaToWorld(dx: number, dy: number, zoom: number): { dx: number; dy: number } {
  const safeZoom = zoom === 0 ? 1 : zoom
  return { dx: dx / safeZoom, dy: dy / safeZoom }
}

/**
 * Pick a viewport that fits every card into the available viewport with a
 * uniform padding. Returns the default viewport when there is nothing to fit.
 */
export function fitToViewport(
  cards: Record<string, BoardCardPosition>,
  viewportWidth: number,
  viewportHeight: number,
  padding = 64,
): BoardViewport {
  const ids = Object.keys(cards)
  if (ids.length === 0) return { ...SAFE_VIEWPORT }
  if (viewportWidth <= padding * 2 || viewportHeight <= padding * 2) return { ...SAFE_VIEWPORT }

  let minX = Infinity
  let minY = Infinity
  let maxX = -Infinity
  let maxY = -Infinity
  for (const id of ids) {
    const pos = cards[id]
    if (pos === undefined) continue
    if (!isFiniteNumber(pos.x) || !isFiniteNumber(pos.y)) continue
    if (pos.x < minX) minX = pos.x
    if (pos.y < minY) minY = pos.y
    if (pos.x + CARD_WIDTH > maxX) maxX = pos.x + CARD_WIDTH
    if (pos.y + CARD_HEIGHT > maxY) maxY = pos.y + CARD_HEIGHT
  }
  if (!Number.isFinite(minX) || !Number.isFinite(minY)) return { ...SAFE_VIEWPORT }

  const contentW = maxX - minX
  const contentH = maxY - minY
  if (contentW <= 0 || contentH <= 0) return { ...SAFE_VIEWPORT }

  const usableW = viewportWidth - padding * 2
  const usableH = viewportHeight - padding * 2
  // Cap the fit zoom at 1: when the content already fits we centre at 100%
  // instead of blowing the cards up to fill the screen.
  const zoom = clampZoom(Math.min(1, usableW / contentW, usableH / contentH))
  const panX = padding - minX * zoom + (usableW - contentW * zoom) / 2
  const panY = padding - minY * zoom + (usableH - contentH * zoom) / 2
  return { panX, panY, zoom }
}

/**
 * Build connecting edges for every parent → child pair whose endpoints have a
 * known position. Returns an empty list when the input unit array is empty.
 */
export function buildEdges(
  units: OrganizationUnit[],
  cards: Record<string, BoardCardPosition>,
): BoardEdge[] {
  const roots = buildOrganizationTree(units)
  const edges: BoardEdge[] = []
  function walk(node: OrganizationTreeNode): void {
    const from = cards[node.unit.id]
    if (from !== undefined) {
      for (const child of node.children) {
        const to = cards[child.unit.id]
        if (to !== undefined) {
          edges.push({
            key: `${node.unit.id}->${child.unit.id}`,
            parentId: node.unit.id,
            childId: child.unit.id,
            from,
            to,
          })
        }
      }
    }
    for (const child of node.children) walk(child)
  }
  for (const root of roots) walk(root)
  return edges
}

/**
 * Load the persisted layout. Always returns a safe partial — never throws.
 * Browser-only: a no-op outside of the window context.
 */
export function loadBoardLayout(): Partial<BoardLayout> | null {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (raw === null) return null
    const parsed = JSON.parse(raw) as unknown
    if (parsed === null || typeof parsed !== 'object') return null
    const candidate = parsed as { viewport?: unknown; cards?: unknown }
    const result: Partial<BoardLayout> = {}
    if (
      candidate.viewport !== undefined
      && candidate.viewport !== null
      && typeof candidate.viewport === 'object'
    ) {
      const v = candidate.viewport as { panX?: unknown; panY?: unknown; zoom?: unknown }
      if (isFiniteNumber(v.panX) && isFiniteNumber(v.panY) && isFiniteNumber(v.zoom)) {
        result.viewport = {
          panX: v.panX,
          panY: v.panY,
          zoom: clampZoom(v.zoom),
        }
      }
    }
    if (candidate.cards !== undefined && candidate.cards !== null && typeof candidate.cards === 'object') {
      const cards: Record<string, BoardCardPosition> = {}
      for (const [id, value] of Object.entries(candidate.cards as Record<string, unknown>)) {
        if (
          value !== null
          && typeof value === 'object'
          && isFiniteNumber((value as { x?: unknown }).x)
          && isFiniteNumber((value as { y?: unknown }).y)
        ) {
          cards[id] = {
            x: (value as { x: number }).x,
            y: (value as { y: number }).y,
          }
        }
      }
      result.cards = cards
    }
    return result
  } catch {
    return null
  }
}

/**
 * Persist the layout. Silently no-ops outside of a browser environment or
 * when storage is full / disabled (Safari private mode, quota errors).
 */
export function saveBoardLayout(layout: BoardLayout): void {
  if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') return
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(layout))
  } catch {
    // ignore quota / disabled storage — board still works for this session
  }
}
