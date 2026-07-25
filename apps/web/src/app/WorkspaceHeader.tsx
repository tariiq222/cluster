import { useMemo } from 'react'

import {
  type ScopeSelectorOption,
  type UserMenuEntryView,
} from './AppShell'
import { text, type Locale } from './copy'
import { usePrincipal } from './principal-context'
import { buildUserMenuEntries } from '../shell/navigation'

export type WorkspaceHeaderProps = {
  locale: Locale
  onUserMenuNavigate: (path: string) => void
}

export type WorkspaceHeaderModel = {
  searchLabel: string
  userMenu: UserMenuEntryView[]
  facilityName: string
  scopeSelector?: {
    current: string | null
    options: ScopeSelectorOption[]
    disabled?: boolean
    pending?: boolean
    stale?: boolean
    errorMessage?: string | null
    onSelect: (value: string) => void
    onRetry?: () => void
  }
}

/**
 * Derives the header configuration that the shell renders into `AppShell`. The
 * principal context is read here so the scope selector depends on the same
 * snapshot that the content consumes. The header is headless: it returns the
 * model so the shell can flow it into the existing chrome without duplicating
 * the layout.
 */
export function WorkspaceHeader({
  locale,
  onUserMenuNavigate,
}: WorkspaceHeaderProps): WorkspaceHeaderModel {
  const principal = usePrincipal()
  const copy = text[locale]

  const userMenu = useMemo<UserMenuEntryView[]>(
    () =>
      buildUserMenuEntries(locale).map((entry) => ({
        key: entry.key,
        label: entry.label,
        path: entry.path,
        onSelect: () => onUserMenuNavigate(entry.path),
      })),
    [locale, onUserMenuNavigate],
  )

  const facilityName = principal.effectiveScope?.label ?? copy.organizationName

  const scopeSelector = useMemo<WorkspaceHeaderModel['scopeSelector']>(() => {
    if (principal.availableScopes.length <= 1
      && !principal.effectiveScope
      && principal.state === 'ready') {
      return undefined
    }
    return {
      current: principal.effectiveScope
        ? `${principal.effectiveScope.scopeType}:${principal.effectiveScope.scopeId}`
        : null,
      options: principal.availableScopes.map((option) => ({
        value: `${option.scopeType}:${option.scopeId}`,
        label: option.label,
      })),
      disabled: principal.state !== 'ready',
      pending: principal.state === 'loading',
      stale: principal.state === 'stale' || principal.state === 'denied' || principal.state === 'error',
      onSelect: (value: string) => {
        const [scopeType, scopeId] = value.split(':')
        if ((scopeType !== 'cluster' && scopeType !== 'facility' && scopeType !== 'unit') || scopeId === undefined) return
        void principal.selectScope(scopeType, scopeId)
      },
      onRetry: () => { void principal.refresh() },
    }
  }, [principal])

  return {
    searchLabel: copy.searchForARecordTask,
    userMenu,
    facilityName,
    scopeSelector,
  }
}
