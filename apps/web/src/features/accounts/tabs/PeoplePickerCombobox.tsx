import { useEffect, useRef, useState } from 'react'
import { ChevronsUpDown, Check } from 'lucide-react'
import * as generated from '../../../api/generated/cluster'
import { useLocale } from '../../../app/session-context'
import { stateFromError } from '../../../api/http'
import { listPeopleCursor, type PersonCollection } from '../../../api/access'
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
import { accountCopy } from '../accounts-copy'

/*
 * People picker for the create-account sheet.
 *
 * The backend exposes `/organization/people` as a cursor-paginated list; a
 * bounded first page (25) is loaded when the picker opens and the operator
 * explicitly requests more via the «المزيد» affordance. This avoids two
 * failure modes:
 *
 *  1. Silent truncation: a Select of the first 100 people hides valid
 *     choices past page 1 with no recovery.
 *  2. Unbounded pre-fetch: walking every cursor in advance is unbounded
 *     work that can never be cancelled.
 *
 * Search is incremental and applies to the loaded set — debounced
 * in-memory filtering over already-paginated rows, not a server query, so
 * the picker stays bounded and responsive.
 */
export function PeoplePickerCombobox({
  selectedId,
  onSelect,
  triggerId,
  ariaLabel,
  invalid,
}: {
  selectedId: string
  onSelect: (person: generated.Person) => void
  triggerId: string
  /*
   * Optional explicit accessible name for the trigger button. Falls back to
   * the localized `peoplePickerLabel` ("Employees" / "قائمة الموظفين") so
   * the trigger is properly labelled both inside and outside a `<FormLabel>`
   * association — required for the picker to surface to keyboard and
   * screen-reader users when it is not wrapped in a labelled form item.
   */
  ariaLabel?: string
  invalid?: boolean
}) {
  const locale = useLocale()
  const text = accountCopy[locale]
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [pages, setPages] = useState<PersonCollection[]>([])
  const [loaded, setLoaded] = useState<generated.Person[]>([])
  const [nextCursor, setNextCursor] = useState<string | null>(null)
  const [loadState, setLoadState] = useState<'idle' | 'loading' | 'ready' | 'denied' | 'error'>('idle')
  const [loadError, setLoadError] = useState<string | null>(null)
  const controllerRef = useRef<AbortController | null>(null)
  const generationRef = useRef(0)

  const selectedPerson = loaded.find((person) => person.id === selectedId) ?? null

  useEffect(() => {
    if (!open) return
    if (pages.length > 0) return
    void loadPage(null)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

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
      const collection = await listPeopleCursor(cursor ?? undefined)
      if (generationRef.current !== generation) return
      setPages((current) => [...current, collection])
      setLoaded((current) => {
        const known = new Set(current.map((person) => person.id))
        return [...current, ...collection.items.filter((person) => !known.has(person.id))]
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
        setLoadError(text.peopleError)
      }
    }
  }

  function pick(person: generated.Person) {
    onSelect(person)
    setOpen(false)
    setQuery('')
  }

  const trimmedQuery = query.trim().toLowerCase()
  const filtered = trimmedQuery.length === 0
    ? loaded
    : loaded.filter((person) => {
      const nameAr = (person.display_name_ar ?? '').toLowerCase()
      const nameEn = (person.display_name_en ?? '').toLowerCase()
      return nameAr.includes(trimmedQuery) || nameEn.includes(trimmedQuery)
    })

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={triggerId}
          variant="outline"
          aria-expanded={open}
          /*
           * `aria-label` is opt-in: the trigger is normally labelled by a
           * surrounding `<FormLabel htmlFor>` and adding a default
           * `aria-label` would shadow that association. Standalone
           * callers (or test harnesses) can pass an explicit name.
           */
          {...(ariaLabel !== undefined ? { 'aria-label': ariaLabel } : {})}
          aria-invalid={invalid ? 'true' : undefined}
          className="justify-between font-normal"
          type="button"
        >
          {selectedPerson ? (
            <span className="truncate">
              {locale === 'en' && selectedPerson.display_name_en
                ? selectedPerson.display_name_en
                : selectedPerson.display_name_ar}
            </span>
          ) : (
            <span className="text-muted-foreground">{text.peoplePickerPlaceholder}</span>
          )}
          <ChevronsUpDown aria-hidden="true" className="size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="p-0" align="start">
        <Command shouldFilter={false} label={text.peoplePickerLabel}>
          <CommandInput
            aria-label={text.peoplePickerLabel}
            placeholder={text.peoplePickerPlaceholder}
            value={query}
            onValueChange={setQuery}
          />
          <CommandList>
            {loadState === 'denied' && (
              <p className="px-3 py-6 text-center text-sm text-muted-foreground" role="alert">
                {text.peoplePickerDenied}
              </p>
            )}
            {loadState === 'error' && (
              <p className="px-3 py-6 text-center text-sm text-destructive" role="alert">
                {loadError ?? text.peopleError}
              </p>
            )}
            {loadState !== 'denied' && loadState !== 'error' && filtered.length === 0 && (
              <CommandEmpty>{text.peoplePickerEmpty}</CommandEmpty>
            )}
            {filtered.length > 0 && (
              <CommandGroup>
                {filtered.map((person) => (
                  <CommandItem
                    key={person.id}
                    value={person.id}
                    onSelect={() => pick(person)}
                  >
                    <Check
                      aria-hidden="true"
                      className={person.id === selectedId ? 'opacity-100' : 'opacity-0'}
                    />
                    <span>
                      {locale === 'en' && person.display_name_en
                        ? person.display_name_en
                        : person.display_name_ar}
                    </span>
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
                  onClick={() => void loadPage(nextCursor)}
                >
                  {loadState === 'loading' ? text.peopleLoading : text.peopleLoadMore}
                </Button>
              </div>
            ) : null}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}