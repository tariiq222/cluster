import { describe, expect, it } from 'vitest'

import {
  clampActiveIndex,
  filterSelectOptions,
  moveActiveIndex,
  SELECT_SEARCH_THRESHOLD,
  shouldShowSearch,
  type SelectOption,
} from './select-utils'

const options: SelectOption[] = [
  { value: 'a', label: 'الإدارة العامة' },
  { value: 'b', label: 'قسم الصيدلة' },
  { value: 'c', label: 'Emergency Department', hint: 'طوارئ' },
  { value: 'd', label: 'ICU' },
]

describe('select-utils.shouldShowSearch', () => {
  it('is hidden at or below the threshold', () => {
    expect(shouldShowSearch(0)).toBe(false)
    expect(shouldShowSearch(SELECT_SEARCH_THRESHOLD)).toBe(false)
  })

  it('appears once options exceed the threshold', () => {
    expect(shouldShowSearch(SELECT_SEARCH_THRESHOLD + 1)).toBe(true)
    expect(shouldShowSearch(11)).toBe(true)
  })
})

describe('select-utils.filterSelectOptions', () => {
  it('returns every option for an empty or whitespace query', () => {
    expect(filterSelectOptions(options, '')).toHaveLength(4)
    expect(filterSelectOptions(options, '   ')).toHaveLength(4)
  })

  it('matches Arabic labels by substring', () => {
    const result = filterSelectOptions(options, 'الصيدلة')
    expect(result.map((o) => o.value)).toEqual(['b'])
  })

  it('matches Latin labels case-insensitively', () => {
    const result = filterSelectOptions(options, 'emergency')
    expect(result.map((o) => o.value)).toEqual(['c'])
    expect(filterSelectOptions(options, 'icu').map((o) => o.value)).toEqual(['d'])
  })

  it('matches against the optional hint text', () => {
    const result = filterSelectOptions(options, 'طوارئ')
    expect(result.map((o) => o.value)).toEqual(['c'])
  })

  it('returns an empty list when nothing matches', () => {
    expect(filterSelectOptions(options, 'zzz')).toEqual([])
  })

  it('does not mutate the input array', () => {
    const frozen = Object.freeze([...options])
    filterSelectOptions(frozen, 'a')
    expect(frozen).toHaveLength(4)
  })
})

describe('select-utils.moveActiveIndex', () => {
  it('moves forward and backward within range', () => {
    expect(moveActiveIndex(0, 1, 3)).toBe(1)
    expect(moveActiveIndex(1, -1, 3)).toBe(0)
  })

  it('wraps around at both ends', () => {
    expect(moveActiveIndex(0, -1, 3)).toBe(2)
    expect(moveActiveIndex(2, 1, 3)).toBe(0)
  })

  it('returns -1 for an empty list', () => {
    expect(moveActiveIndex(0, 1, 0)).toBe(-1)
  })
})

describe('select-utils.clampActiveIndex', () => {
  it('keeps a valid index unchanged', () => {
    expect(clampActiveIndex(2, 4)).toBe(2)
  })

  it('clamps below-zero and overflowing indices', () => {
    expect(clampActiveIndex(-3, 4)).toBe(0)
    expect(clampActiveIndex(9, 4)).toBe(3)
  })

  it('returns -1 for an empty list', () => {
    expect(clampActiveIndex(0, 0)).toBe(-1)
  })
})
