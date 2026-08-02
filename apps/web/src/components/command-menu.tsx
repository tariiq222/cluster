import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FileText,
  FolderSearch,
  ListTodo,
  RotateCcw,
  type LucideIcon,
} from 'lucide-react'
import { useSearch } from '@/api/hooks'
import { shellCopy, type Locale } from '@/i18n'
import { searchCopy } from '@/features/search/search-copy'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command'

const SEARCH_DEBOUNCE_MS = 250
type SearchResultType = 'task' | 'document' | 'work_record'

interface SearchHit {
  id: string
  title: string
  type: SearchResultType
}

function iconForType(type: SearchResultType): LucideIcon {
  switch (type) {
    case 'task':
      return ListTodo
    case 'document':
      return FileText
    default:
      return FolderSearch
  }
}

function searchHit(item: unknown): SearchHit | null {
  if (!item || typeof item !== 'object') return null
  const value = item as Record<string, unknown>
  const rawType =
    typeof value.source_type === 'string'
      ? value.source_type
      : typeof value.resource_type === 'string'
        ? value.resource_type
        : 'record_number' in value
          ? 'work_record'
          : null
  if (rawType !== 'task' && rawType !== 'document' && rawType !== 'work_record')
    return null

  const id =
    typeof value.source_id === 'string'
      ? value.source_id
      : typeof value.id === 'string'
        ? value.id
        : null
  if (!id) return null

  const titleCandidates = [
    value.title,
    value.name,
    value.code,
    value.record_number,
  ]
  const title = titleCandidates.find(
    (candidate): candidate is string =>
      typeof candidate === 'string' && candidate.trim().length > 0,
  )
  return { id, title: title ?? id, type: rawType }
}

function resultTypeLabel(type: SearchResultType, locale: Locale): string {
  const copy = searchCopy[locale]
  if (type === 'task') return copy.task
  if (type === 'document') return copy.document
  return copy.workRecord
}

function CommandResults({
  query,
  locale,
  resultRoutes,
  onSelect,
}: {
  query: string
  locale: Locale
  resultRoutes: ReadonlyMap<SearchResultType, string>
  onSelect: (path: string) => void
}) {
  const copy = searchCopy[locale]
  const { data, isLoading, isError, refetch } = useSearch(query, true)

  const groups = useMemo(() => {
    const byType = new Map<SearchResultType, SearchHit[]>()
    for (const item of data?.items ?? []) {
      const hit = searchHit(item)
      if (!hit || !resultRoutes.has(hit.type)) continue
      const entries = byType.get(hit.type) ?? []
      entries.push(hit)
      byType.set(hit.type, entries)
    }
    return Array.from(byType.entries())
  }, [data, resultRoutes])

  if (isLoading) {
    return (
      <div role="status" className="px-3 py-6 text-center text-sm">
        {copy.searching}
      </div>
    )
  }

  if (isError) {
    return (
      <div role="alert" className="space-y-2 px-3 py-4 text-center text-sm">
        <p>{copy.failed}</p>
        <Button variant="outline" size="sm" onClick={() => void refetch()}>
          <RotateCcw aria-hidden="true" />
          {copy.retry}
        </Button>
      </div>
    )
  }

  if (!groups.length) {
    return (
      <div role="status" className="px-3 py-6 text-center text-sm">
        {copy.noResults}
      </div>
    )
  }

  return groups.map(([type, items]) => {
    const Icon = iconForType(type)
    const basePath = resultRoutes.get(type)!
    return (
      <CommandGroup key={type} heading={resultTypeLabel(type, locale)}>
        {items.map((item) => (
          <CommandItem
            key={item.id}
            value={`${type}:${item.title}`}
            onSelect={() =>
              onSelect(`${basePath}/${encodeURIComponent(item.id)}`)
            }
          >
            <Icon aria-hidden="true" />
            <span
              className="min-w-0 flex-1 truncate"
              title={item.title}
            >
              {item.title}
            </span>
          </CommandItem>
        ))}
      </CommandGroup>
    )
  })
}

/* FOCUS-RESTORE-01: restore focus to the element that was active when
 * the menu opened. The capture/restoration runs only while the dialog
 * is open so initial closed mounts stay unfocused. The restore is
 * scheduled with requestAnimationFrame so it lands after Radix's
 * close-time focus cleanup and the exit animation begins unmounting the
 * dialog content. Stale or disconnected triggers are skipped so we
 * never focus a detached element. */
function restoreFocusTo(trigger: HTMLElement | null) {
  if (!trigger) return
  if (typeof document === 'undefined') return
  if (!document.body.contains(trigger)) return
  const restore = () => {
    if (document.body.contains(trigger)) {
      trigger.focus()
    }
  }
  if (typeof window.requestAnimationFrame === 'function') {
    window.requestAnimationFrame(restore)
  } else {
    restore()
  }
}

export function CommandMenu({
  locale,
  navigationEntries,
  open: openProp,
  onOpenChange,
}: {
  locale: Locale
  navigationEntries: Array<{
    path: string
    label: string
    icon: LucideIcon
  }>
  open?: boolean
  onOpenChange?: (open: boolean) => void
}) {
  const copy = shellCopy[locale]
  const navigate = useNavigate()
  const [internalOpen, setInternalOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')
  const triggerRef = useRef<HTMLElement | null>(null)

  /* RESULT-ROUTES-01: the route map was rebuilt every render so any
   * downstream `useMemo` keyed on it never settled. Memoize so a
   * stable navigation list keeps the result map referentially equal. */
  const resultRoutes = useMemo(() => {
    const routes = new Map<SearchResultType, string>()
    for (const entry of navigationEntries) {
      if (entry.path === '/tasks') routes.set('task', entry.path)
      if (entry.path === '/documents') routes.set('document', entry.path)
      if (entry.path === '/work-records')
        routes.set('work_record', entry.path)
    }
    return routes
  }, [navigationEntries])

  const open = openProp ?? internalOpen
  const setOpen = useCallback(
    (next: boolean | ((current: boolean) => boolean)) => {
      if (typeof next === 'function') {
        const computed = next(open)
        setInternalOpen(computed)
        onOpenChange?.(computed)
      } else {
        setInternalOpen(next)
        onOpenChange?.(next)
      }
    },
    [open, onOpenChange],
  )

  /* QUERY-RESET-01: clearing both the visible query and the debounced
   * remote-query on close prevents reopening the menu from resurrecting
   * a previous in-flight search. Resetting before the render commit
   * keeps the input controlled value consistent on the next open. */
  useEffect(() => {
    if (open) return
    setQuery('')
    setDebouncedQuery('')
  }, [open])

  useEffect(() => {
    if (!open) return
    const timer = window.setTimeout(
      () => setDebouncedQuery(query),
      SEARCH_DEBOUNCE_MS,
    )
    return () => window.clearTimeout(timer)
  }, [query, open])

  /* FOCUS-CAPTURE-01: the dialog is controlled from outside CommandMenu
   * so the trigger is not a Radix DialogTrigger. Capture the active
   * element by listening for the focusin that Radix triggers when it
   * moves focus into the dialog. relatedTarget of that focusin is the
   * previous focus owner — the element that opened the menu. */
  useEffect(() => {
    if (!open) return
    const onFocusIn = (event: FocusEvent) => {
      const target = event.target as HTMLElement | null
      if (!target?.closest('[data-slot="dialog-content"]')) return
      const related = event.relatedTarget as HTMLElement | null
      if (related instanceof HTMLElement && document.body.contains(related)) {
        triggerRef.current = related
      }
    }
    document.addEventListener('focusin', onFocusIn)
    return () => document.removeEventListener('focusin', onFocusIn)
  }, [open])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault()
        setOpen((current) => !current)
      }
      // Escape is owned by Radix Dialog; do not duplicate the listener.
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [setOpen])

  const closeAndRestoreFocus = useCallback(() => {
    const trigger = triggerRef.current
    triggerRef.current = null
    setOpen(false)
    restoreFocusTo(trigger)
  }, [setOpen])

  const navigateTo = (path: string) => {
    closeAndRestoreFocus()
    navigate(path)
  }

  return (
    <CommandDialog
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          // Escape, click-outside, or selection: capture the trigger
          // before Radix wipes focus so we can restore it on the next
          // frame. The dialog still calls onOpenChange via setOpen below.
          const trigger = triggerRef.current
          triggerRef.current = null
          setOpen(false)
          restoreFocusTo(trigger)
          return
        }
        setOpen(true)
      }}
      title={copy.search}
      description={copy.searchPlaceholder}
    >
      <Command
        className={
          /* TOUCH-TARGETS-01: raise the generated input-group and
           * command-item slots to 44px without editing the generated
           * primitive. The `h-11!` important modifier beats the
           * generated `h-8` regardless of utility order. */
          '[&_[data-slot=input-group]]:min-h-11 [&_[data-slot=input-group]]:h-11! [&_[data-slot=command-item]]:min-h-11'
        }
      >
        <CommandInput
          value={query}
          onValueChange={setQuery}
          placeholder={copy.searchPlaceholder}
        />
        <CommandList>
          {debouncedQuery.trim().length < 2 && (
            <CommandEmpty>{copy.searchNoResults}</CommandEmpty>
          )}
          <CommandGroup heading={copy.menu}>
            {navigationEntries.map((entry) => (
              <CommandItem
                key={entry.path}
                value={entry.label}
                onSelect={() => navigateTo(entry.path)}
              >
                <entry.icon aria-hidden="true" />
                <span
                  className="min-w-0 flex-1 truncate"
                  title={entry.label}
                >
                  {entry.label}
                </span>
              </CommandItem>
            ))}
          </CommandGroup>
          {debouncedQuery.trim().length >= 2 && (
            <>
              <CommandSeparator />
              <CommandResults
                query={debouncedQuery}
                locale={locale}
                resultRoutes={resultRoutes}
                onSelect={(path) => {
                  closeAndRestoreFocus()
                  navigate(path)
                }}
              />
            </>
          )}
        </CommandList>
      </Command>
    </CommandDialog>
  )
}
