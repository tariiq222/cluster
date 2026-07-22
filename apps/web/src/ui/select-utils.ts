/**
 * Pure helpers for the unified Select primitive.
 *
 * Kept React-free so the filtering and keyboard-navigation rules are unit
 * testable without a DOM. The component in `Select.tsx` is a thin shell over
 * these functions.
 */

export interface SelectOption {
  value: string
  label: string
  /** Optional secondary text that is also matched by the search filter. */
  hint?: string
}

/**
 * Product rule: the search box appears automatically once a dropdown has
 * MORE than this many options. At or below the threshold the list is short
 * enough to scan, so no search UI is rendered.
 */
export const SELECT_SEARCH_THRESHOLD = 10

/** True when the option list is long enough to need the built-in search. */
export function shouldShowSearch(optionCount: number): boolean {
  return optionCount > SELECT_SEARCH_THRESHOLD
}

/**
 * Case-insensitive substring filter over the option label (and optional
 * hint). Trims surrounding whitespace; an empty query returns every option.
 * `toLocaleLowerCase` keeps Arabic labels intact (no case mapping) while
 * normalising Latin input.
 */
export function filterSelectOptions(
  options: readonly SelectOption[],
  query: string,
): SelectOption[] {
  const normalized = query.trim().toLocaleLowerCase()
  if (normalized === '') return [...options]
  return options.filter((option) => {
    if (option.label.toLocaleLowerCase().includes(normalized)) return true
    return option.hint !== undefined && option.hint.toLocaleLowerCase().includes(normalized)
  })
}

/**
 * Move the active option by `delta` with wrap-around, so ArrowUp on the
 * first option lands on the last and ArrowDown on the last lands on the
 * first. Returns -1 for an empty list.
 */
export function moveActiveIndex(current: number, delta: number, count: number): number {
  if (count <= 0) return -1
  const next = current + delta
  if (next < 0) return count - 1
  if (next >= count) return 0
  return next
}

/**
 * Clamp an index into the valid range of a list of `count` items. Used when
 * the filtered list shrinks under the cursor (e.g. the user keeps typing
 * and the previously active option disappears).
 */
export function clampActiveIndex(index: number, count: number): number {
  if (count <= 0) return -1
  if (index < 0) return 0
  if (index >= count) return count - 1
  return index
}
