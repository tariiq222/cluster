import { useEffect, useRef, useState } from 'react'
import { ChevronsUpDown, Check } from 'lucide-react'
import { useLocale } from '../../../app/session-context'
import { stateFromError } from '../../../api/http'
import * as access from '../../../api/access'
import * as generated from '../../../api/generated/cluster'
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
import { roleCopy } from '../accounts-copy'

export interface ScopeSelection {
  scopeType: generated.ListAuthorizationAssignmentScopeTargetsScopeType
  scopeId: string
  label: string
}

export interface EffectiveScope {
  scopeType: string
  scopeId: string
  label: string
}

const SEARCH_DEBOUNCE_MS = 300

/*
 * Parent derivation for the assignment-scope-targets query.
 *
 *  - target `cluster`          => no parent at all
 *  - effective `cluster` + target facility/unit => parent cluster + effective id
 *  - effective `facility` + target facility/unit => parent facility + effective id
 *  - otherwise (unit effective scope, no scope) => omit the invalid parent pair
 */
function deriveParent(
  target: string,
  effectiveScope: EffectiveScope | null,
): { parentScopeType?: 'cluster' | 'facility'; parentScopeId?: string } {
  if (target === 'cluster') return {}
  if (!effectiveScope) return {}
  if (effectiveScope.scopeType === 'cluster' || effectiveScope.scopeType === 'facility') {
    return {
      parentScopeType: effectiveScope.scopeType,
      parentScopeId: effectiveScope.scopeId,
    }
  }
  return {}
}

export function ScopeTargetCombobox({
  scopeType,
  effectiveScope,
  selection,
  onSelect,
  triggerId,
}: {
  scopeType: generated.ListAuthorizationAssignmentScopeTargetsScopeType
  effectiveScope: EffectiveScope | null
  selection: ScopeSelection | null
  onSelect: (selection: ScopeSelection) => void
  triggerId?: string
}) {
  const locale = useLocale()
  const text = roleCopy[locale]
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [items, setItems] = useState<generated.AssignmentScopeTarget[]>([])
  const [searchState, setSearchState] = useState<
    'idle' | 'loading' | 'ready' | 'denied' | 'error'
  >('idle')
  const [searchError, setSearchError] = useState<string | null>(null)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  /*
   * Progressive search: nothing on mount, nothing below two trimmed
   * characters, then a debounced query. The parent is derived from the
   * effective principal scope, and record_set is never sent (the type
   * union excludes it, and the UI only offers cluster/facility/unit).
   */
  useEffect(() => {
    const trimmed = query.trim()
    if (trimmed.length < 2) {
      if (timerRef.current) {
        clearTimeout(timerRef.current)
        timerRef.current = null
      }
      setItems([])
      setSearchState('idle')
      setSearchError(null)
      return
    }
    if (timerRef.current) clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => {
      const parent = deriveParent(scopeType, effectiveScope)
      setSearchState('loading')
      setSearchError(null)
      void access
        .searchScopeTargets({
          scopeType,
          ...parent,
          search: trimmed,
        })
        .then((collection) => {
          setItems(collection.items)
          setSearchState('ready')
        })
        .catch((error) => {
          const derived = stateFromError(error)
          if (derived === 'forbidden' || derived === 'not-found') {
            setItems([])
            setSearchState('denied')
          } else {
            setItems([])
            setSearchState('error')
            setSearchError(text.scopeSearchError)
          }
        })
    }, SEARCH_DEBOUNCE_MS)
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [query, scopeType, effectiveScope, text.scopeSearchError])

  function pick(target: generated.AssignmentScopeTarget) {
    onSelect({
      scopeType: target.scope_type,
      scopeId: target.scope_id,
      label: locale === 'en' ? target.label_en : target.label_ar,
    })
    setOpen(false)
    setQuery('')
    setItems([])
    setSearchState('idle')
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={triggerId}
          variant="outline"
          aria-expanded={open}
          className="justify-between font-normal"
        >
          {selection ? (
            <span className="truncate">{selection.label}</span>
          ) : (
            <span className="text-muted-foreground">{text.scopeSelectPlaceholder}</span>
          )}
          <ChevronsUpDown aria-hidden="true" className="size-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="p-0" align="start">
        <Command
          shouldFilter={false}
          label={text.scopeSearchLabel}
        >
          <CommandInput
            aria-label={text.scopeSearchLabel}
            placeholder={text.scopeSearchPlaceholder}
            value={query}
            onValueChange={setQuery}
          />
          <CommandList>
            {searchState === 'idle' && (
              <CommandEmpty>{text.scopeSearchPlaceholder}</CommandEmpty>
            )}
            {searchState === 'loading' && (
              <p className="px-3 py-6 text-center text-sm text-muted-foreground" role="status">
                {text.scopeSearching}
              </p>
            )}
            {searchState === 'denied' && (
              <p className="px-3 py-6 text-center text-sm text-muted-foreground" role="alert">
                {text.scopeSearchDenied}
              </p>
            )}
            {searchState === 'error' && (
              <p className="px-3 py-6 text-center text-sm text-destructive" role="alert">
                {searchError ?? text.scopeSearchError}
              </p>
            )}
            {searchState === 'ready' && items.length === 0 && (
              <CommandEmpty>{text.scopeNoResults}</CommandEmpty>
            )}
            {searchState === 'ready' && items.length > 0 && (
              <CommandGroup>
                {items.map((target) => (
                  <CommandItem
                    key={target.scope_id}
                    value={target.scope_id}
                    onSelect={() => pick(target)}
                  >
                    <Check
                      aria-hidden="true"
                      className={target.scope_id === selection?.scopeId ? 'opacity-100' : 'opacity-0'}
                    />
                    <span>{locale === 'en' ? target.label_en : target.label_ar}</span>
                    {target.code ? (
                      <span className="ms-auto font-mono text-xs text-muted-foreground" dir="ltr">
                        {target.code}
                      </span>
                    ) : null}
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
