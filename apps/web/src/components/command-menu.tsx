import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FileText,
  FolderSearch,
  Inbox,
  ListTodo,
  type LucideIcon,
} from 'lucide-react'
import { useSearch } from '@/api/hooks'
import { shellCopy, type Locale } from '@/i18n'
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

function iconForType(type: string): LucideIcon {
  switch (type) {
    case 'task':
    case 'task_comment':
    case 'task_participant':
      return ListTodo
    case 'document':
    case 'document_version':
      return FileText
    default:
      return FolderSearch
  }
}

function pathForResult(type: string, id: string): string | null {
  if (type === 'task') return `/tasks/${id}`
  if (type === 'document') return `/documents/${id}`
  return null
}

function CommandResults({
  query,
  locale,
  onSelect,
}: {
  query: string
  locale: Locale
  onSelect: (path: string | null) => void
}) {
  const copy = shellCopy[locale]
  const { data } = useSearch(query, true)

  const groups = useMemo(() => {
    const byType = new Map<string, Array<{ id: string; title: string }>>()
    for (const item of data?.items ?? []) {
      const type = 'resource_type' in item ? item.resource_type : 'work_record'
      const id = item.id
      const title =
        ('title' in item && item.title)
        || ('name' in item && item.name)
        || ('code' in item && item.code)
        || id
      const entries = byType.get(type) ?? []
      entries.push({ id, title })
      byType.set(type, entries)
    }
    return Array.from(byType.entries())
  }, [data])

  if (!groups.length) {
    return <CommandEmpty>{copy.searchNoResults}</CommandEmpty>
  }

  return groups.map(([type, items]) => {
    const Icon = iconForType(type)
    return (
      <CommandGroup key={type} heading={type}>
        {items.map((item) => (
          <CommandItem
            key={item.id}
            value={`${type}:${item.title}`}
            onSelect={() => onSelect(pathForResult(type, item.id))}
          >
            <Icon aria-hidden="true" />
            <span>{item.title}</span>
          </CommandItem>
        ))}
      </CommandGroup>
    )
  })
}

export function CommandMenu({
  locale,
  open: openProp,
  onOpenChange,
}: {
  locale: Locale
  open?: boolean
  onOpenChange?: (open: boolean) => void
}) {
  const copy = shellCopy[locale]
  const navigate = useNavigate()
  const [internalOpen, setInternalOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  const open = openProp ?? internalOpen
  const setOpen = (next: boolean | ((current: boolean) => boolean)) => {
    if (typeof next === 'function') {
      const computed = next(open)
      setInternalOpen(computed)
      onOpenChange?.(computed)
    } else {
      setInternalOpen(next)
      onOpenChange?.(next)
    }
  }

  useEffect(() => {
    if (!open) return
    const timer = window.setTimeout(() => setDebouncedQuery(query), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [query, open])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
        event.preventDefault()
        setOpen((current) => !current)
      } else if (event.key === 'Escape') {
        setOpen(false)
      }
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [])

  const navigateTo = (path: string) => {
    setOpen(false)
    navigate(path)
  }

  const navigationEntries = [
    { path: '/', label: copy.home, icon: Inbox },
    { path: '/tasks', label: copy.tasks, icon: ListTodo },
    { path: '/documents', label: copy.documents, icon: FileText },
    { path: '/organization', label: copy.organization, icon: FolderSearch },
  ]

  return (
    <CommandDialog open={open} onOpenChange={setOpen} title={copy.search} description={copy.searchPlaceholder}>
      <Command>
        <CommandInput
          value={query}
          onValueChange={setQuery}
          placeholder={copy.searchPlaceholder}
          autoFocus
        />
        <CommandList>
          <CommandEmpty>{copy.searchNoResults}</CommandEmpty>
          <CommandGroup heading={copy.menu}>
            {navigationEntries.map((entry) => (
              <CommandItem key={entry.path} value={entry.label} onSelect={() => navigateTo(entry.path)}>
                <entry.icon aria-hidden="true" />
                <span>{entry.label}</span>
              </CommandItem>
            ))}
          </CommandGroup>
          {debouncedQuery.trim().length >= 2 && (
            <>
              <CommandSeparator />
              <CommandResults
                query={debouncedQuery}
                locale={locale}
                onSelect={(path) => {
                  setOpen(false)
                  if (path) navigate(path)
                }}
              />
            </>
          )}
        </CommandList>
      </Command>
    </CommandDialog>
  )
}
