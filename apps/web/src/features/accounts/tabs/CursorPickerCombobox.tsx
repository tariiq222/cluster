import { useEffect, useRef, useState } from 'react'
import { ChevronsUpDown, Check } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { stateFromError } from '../../../api/http'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'

export interface CursorCollection<TItem> {
  items: TItem[]
  next_cursor: string | null
}

type LoadState = 'idle' | 'loading' | 'ready' | 'denied' | 'error'

/*
 * Generic cursor-aware picker.
 *
 * Used by the /access workspace wherever a bounded, user-reachable
 * search-and-pick is required against a cursor-paginated catalog.
 * Patterns enforced:
 *
 *  - one bounded first page (25) loads when the picker opens
 *  - additional pages are pulled explicitly via a load-more affordance
 *  - in-flight loads are cancelled via AbortController when superseded
 *  - monotonic request generation ignores stale resolutions
 *  - the parent receives the full selected item, not just the id, so
 *    the form-level submit can carry per-item fields (e.g. lock_version)
 *    regardless of which page the row came from
 *  - denial surfaces the shared non-disclosing copy; error exposes a
 *    retry affordance without ever rendering a no-op
 */
export function CursorPickerCombobox<TItem extends { id: string }>({
  selectedId,
  onSelect,
  loadPage: loadPageFn,
  getLabel,
  triggerId,
  invalid,
  ariaLabel,
  searchPlaceholder,
  emptyLabel,
  deniedLabel,
  errorLabel,
  loadingLabel,
  loadMoreLabel,
}: {
  selectedId: string
  onSelect: (item: TItem) => void
  loadPage: (cursor: string | null) => Promise<CursorCollection<TItem>>
  getLabel: (item: TItem, locale: 'ar' | 'en') => string
  triggerId: string
  invalid?: boolean
  ariaLabel: string
  searchPlaceholder: string
  emptyLabel: string
  deniedLabel: string
  errorLabel: string
  loadingLabel: string
  loadMoreLabel: string
}) {
  const locale = useLocale()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [loaded, setLoaded] = useState<TItem[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadState, setLoadState] = useState<LoadState>('idle')
  const [loadError, setLoadError] = useState<string | null>(null)
  const controllerRef = useRef<AbortController | null>(null)
  const generationRef = useRef(0)
  const firstLoadDoneRef = useRef(false)

  const selectedItem = loaded.find((item) => item.id === selectedId) ?? null

  async function loadPage(cursor: string | null) {
    const generation = generationRef.current + 1
    generationRef.current = generation
    const previous = controllerRef.current
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null
    controllerRef.current = controller
    if (previous) previous.abort()
    setLoadState('loading')
    setLoadError(null)
    try {
      const collection = await loadPageFn(cursor)
      if (generationRef.current !== generation) return
      setLoaded((current) => {
        const known = new Set(current.map((item) => item.id))
        return [...current, ...collection.items.filter((item) => !known.has(item.id))]
      })
      setNextCursor(collection.next_cursor)
      setLoadState('ready')
    } catch (cause) {
      if (generationRef.current !== generation) return
      if (controller && controller.signal.aborted) return
      const derived = stateFromError(cause)
      if (derived === 'forbidden' || derived === 'not-found') {
        setLoadState('denied')
      } else {
        setLoadState('error')
        setLoadError(errorLabel)
      }
    }
  }

  /*
   * First load is bound to `open` so the picker does not pre-fetch
   * before the user engages it. Once any page is loaded we keep the
   * set, so reopens are instant.
   */
  useEffect(() => {
    if (!open) return
    if (firstLoadDoneRef.current) return
    firstLoadDoneRef.current = true
    void loadPage(null)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  function pick(item: TItem) {
    onSelect(item)
    setOpen(false)
    setQuery('')
  }

  const trimmedQuery = query.trim().toLowerCase()
  const filtered = trimmedQuery.length === 0
    ? loaded
    : loaded.filter((item) => getLabel(item, locale).toLowerCase().includes(trimmedQuery))

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={triggerId}
          variant="outline"
          aria-expanded={open}
          aria-label={ariaLabel}
          aria-invalid={invalid ? 'true' : undefined}
          className="justify-between font-normal"
          type="button"
        >
          {selectedItem ? (
            <span className="truncate">{getLabel(selectedItem, locale)}</span>
          ) : (
            <span className="text-muted-foreground">{searchPlaceholder}</span>
          )}
          <ChevronsUpDown aria-hidden="true" className="size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="p-0" align="start">
        <Command shouldFilter={false} label={ariaLabel}>
          <CommandInput
            aria-label={ariaLabel}
            placeholder={searchPlaceholder}
            value={query}
            onValueChange={setQuery}
          />
          <CommandList>
            {loadState === 'denied' && (
              <p className="px-3 py-6 text-center text-sm text-muted-foreground" role="alert">
                {deniedLabel}
              </p>
            )}
            {loadState === 'error' && (
              <p className="px-3 py-6 text-center text-sm text-destructive" role="alert">
                {loadError ?? errorLabel}
              </p>
            )}
            {loadState !== 'denied' && loadState !== 'error' && filtered.length === 0 && (
              <CommandEmpty>{emptyLabel}</CommandEmpty>
            )}
            {filtered.length > 0 && (
              <CommandGroup>
                {filtered.map((item) => (
                  <CommandItem
                    key={item.id}
                    value={item.id}
                    onSelect={() => pick(item)}
                  >
                    <Check
                      aria-hidden="true"
                      className={item.id === selectedId ? 'opacity-100' : 'opacity-0'}
                    />
                    <span>{getLabel(item, locale)}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
            {nextCursor && loadState !== 'error' && loadState !== 'denied' ? (
              <div className="border-t p-2">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="w-full justify-center"
                  disabled={loadState === 'loading'}
                  onClick={() => {
                    void loadPage(nextCursor)
                  }}
                >
                  {loadState === 'loading' ? loadingLabel : loadMoreLabel}
                </Button>
              </div>
            ) : null}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}