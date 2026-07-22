import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from 'react'
import { Check, ChevronDown, Search } from 'lucide-react'

import { cx } from './cx'
import {
  filterSelectOptions,
  moveActiveIndex,
  shouldShowSearch,
  type SelectOption,
} from './select-utils'

export type { SelectOption }

export interface SelectProps {
  id: string
  value: string
  onChange: (value: string) => void
  options: SelectOption[]
  /** Shown on the trigger when no option matches the current value. */
  placeholder?: string
  disabled?: boolean
  /** Search input label/placeholder; only rendered when options exceed the threshold. */
  searchPlaceholder?: string
  /** Rendered inside the listbox when the filter matches nothing. */
  emptyLabel?: string
  /**
   * When set, a hidden input keeps the value inside native FormData so
   * uncontrolled forms keep working after migrating away from `<select>`.
   */
  name?: string
  /** Accessible name for the trigger when no external <label htmlFor> exists. */
  ariaLabel?: string
  className?: string
}

/**
 * Unified dropdown for the whole platform. Replaces every native `<select>`
 * and every feature-local Select helper.
 *
 * Behaviour contract:
 * - Search appears automatically when `options.length > SELECT_SEARCH_THRESHOLD`
 *   (10). Shorter lists render without a search box.
 * - Trigger is a real `<button>` (labelable via `<label htmlFor>` from the
 *   shared Field primitive).
 * - Keyboard: ArrowDown/ArrowUp on the trigger opens the list; inside the
 *   list Arrow keys move (with wrap), Home/End jump, Enter selects, Escape
 *   closes and refocuses the trigger, Tab closes and moves focus on.
 * - Pointer: clicking the trigger toggles; clicking outside closes; hovering
 *   an option makes it active.
 * - ARIA: combobox pattern on the search input (when present) or
 *   `aria-activedescendant` on the focused listbox (when it is not).
 */
export function Select({
  id,
  value,
  onChange,
  options,
  placeholder = '—',
  disabled = false,
  searchPlaceholder = 'بحث…',
  emptyLabel = 'لا توجد نتائج',
  name,
  ariaLabel,
  className,
}: SelectProps) {
  const listboxId = `${id}-listbox`
  const rootRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const listRef = useRef<HTMLUListElement>(null)

  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)

  const searchable = shouldShowSearch(options.length)
  const filtered = useMemo(() => filterSelectOptions(options, query), [options, query])
  const selected = useMemo(
    () => options.find((option) => option.value === value) ?? null,
    [options, value],
  )

  const close = useCallback((refocus: boolean) => {
    setOpen(false)
    setQuery('')
    setActiveIndex(-1)
    if (refocus) triggerRef.current?.focus()
  }, [])

  const openList = useCallback(() => {
    if (disabled) return
    setQuery('')
    const selectedIndex = options.findIndex((option) => option.value === value)
    setActiveIndex(selectedIndex >= 0 ? selectedIndex : (options.length > 0 ? 0 : -1))
    setOpen(true)
  }, [disabled, options, value])

  // Move focus into the popover once it mounts.
  useEffect(() => {
    if (!open) return
    const frame = window.requestAnimationFrame(() => {
      if (searchable) searchRef.current?.focus()
      else listRef.current?.focus()
    })
    return () => window.cancelAnimationFrame(frame)
  }, [open, searchable])

  // Close on outside pointerdown.
  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: PointerEvent) => {
      const root = rootRef.current
      if (root !== null && event.target instanceof Node && !root.contains(event.target)) {
        close(false)
      }
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [open, close])

  // Keep the active option visible while navigating.
  useEffect(() => {
    if (!open || activeIndex < 0) return
    listRef.current
      ?.querySelector(`[data-select-index="${activeIndex}"]`)
      ?.scrollIntoView({ block: 'nearest' })
  }, [open, activeIndex])

  function selectOption(option: SelectOption) {
    onChange(option.value)
    close(true)
  }

  function handleTriggerKeyDown(event: ReactKeyboardEvent<HTMLButtonElement>) {
    if (disabled) return
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      openList()
    }
  }

  function handlePopoverKeyDown(event: ReactKeyboardEvent<HTMLDivElement>) {
    const inSearch = event.target === searchRef.current
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault()
        setActiveIndex((current) => moveActiveIndex(current, 1, filtered.length))
        return
      case 'ArrowUp':
        event.preventDefault()
        setActiveIndex((current) => moveActiveIndex(current, -1, filtered.length))
        return
      case 'Home':
        if (inSearch) return // let the caret jump inside the search text
        event.preventDefault()
        setActiveIndex(filtered.length > 0 ? 0 : -1)
        return
      case 'End':
        if (inSearch) return
        event.preventDefault()
        setActiveIndex(filtered.length - 1)
        return
      case 'Enter':
        event.preventDefault()
        if (activeIndex >= 0 && filtered[activeIndex] !== undefined) {
          selectOption(filtered[activeIndex])
        }
        return
      case ' ':
        if (inSearch) return // space must type a space in the search box
        event.preventDefault()
        if (activeIndex >= 0 && filtered[activeIndex] !== undefined) {
          selectOption(filtered[activeIndex])
        }
        return
      case 'Escape':
        event.preventDefault()
        close(true)
        return
      case 'Tab':
        close(false)
        return
      default:
        return
    }
  }

  const activeOptionId =
    activeIndex >= 0 && filtered[activeIndex] !== undefined
      ? `${id}-option-${activeIndex}`
      : undefined

  return (
    <div ref={rootRef} className={cx('ui-select', className)}>
      {name !== undefined && <input type="hidden" name={name} value={value} />}
      <button
        ref={triggerRef}
        type="button"
        id={id}
        className="ui-select-trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listboxId : undefined}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => (open ? close(true) : openList())}
        onKeyDown={handleTriggerKeyDown}
      >
        <span className={cx('ui-select-value', selected === null && 'ui-select-placeholder')}>
          {selected !== null ? selected.label : placeholder}
        </span>
        <ChevronDown aria-hidden="true" className="ui-select-chevron" />
      </button>
      {open && (
        <div className="ui-select-popover" onKeyDown={handlePopoverKeyDown}>
          {searchable && (
            <div className="ui-select-search">
              <Search aria-hidden="true" />
              <input
                ref={searchRef}
                type="text"
                role="combobox"
                aria-expanded="true"
                aria-controls={listboxId}
                aria-activedescendant={activeOptionId}
                aria-autocomplete="list"
                aria-label={searchPlaceholder}
                placeholder={searchPlaceholder}
                value={query}
                onChange={(event) => {
                  setQuery(event.target.value)
                  setActiveIndex(0)
                }}
              />
            </div>
          )}
          <ul
            ref={listRef}
            id={listboxId}
            role="listbox"
            tabIndex={-1}
            aria-label={ariaLabel}
            aria-activedescendant={searchable ? undefined : activeOptionId}
            className="ui-select-listbox"
          >
            {filtered.map((option, index) => (
              <li
                key={`${option.value}-${index}`}
                id={`${id}-option-${index}`}
                role="option"
                aria-selected={option.value === value}
                data-select-index={index}
                className={cx(
                  'ui-select-option',
                  index === activeIndex && 'ui-select-option-active',
                )}
                onPointerDown={(event) => event.preventDefault()}
                onClick={() => selectOption(option)}
                onPointerEnter={() => setActiveIndex(index)}
              >
                <span className="ui-select-option-label">{option.label}</span>
                {option.value === value && (
                  <Check aria-hidden="true" className="ui-select-check" />
                )}
              </li>
            ))}
            {filtered.length === 0 && (
              <li className="ui-select-empty" role="presentation">{emptyLabel}</li>
            )}
          </ul>
        </div>
      )}
    </div>
  )
}
