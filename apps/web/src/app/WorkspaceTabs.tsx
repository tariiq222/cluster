import { type KeyboardEvent, type MouseEvent, type ReactNode, useCallback, useRef } from 'react'

import './WorkspaceTabs.css'

export type WorkspaceTab = {
  key: string
  label: string
  path: string
  active: boolean
  icon?: ReactNode
  /** When the tabs render with `mode="tabs"` semantics, the linked panel id. */
  panelId?: string
}

export type WorkspaceTabsMode = 'links' | 'tabs'

export type WorkspaceTabsProps = {
  label: string
  tabs: WorkspaceTab[]
  onNavigate: (path: string) => void
  category?: 'governance' | 'portfolio'
  /**
   * Optional tab semantics mode. Defaults to `'links'` so every caller keeps
   * the canonical anchor + `aria-current='page'` behavior. When `'tabs'`, the
   * bar renders as a `role="tablist"` with `role="tab"` buttons that manage
   * selection with the arrow keys and link to a panel via `aria-controls`.
   */
  mode?: WorkspaceTabsMode
  onTabSelect?: (key: string) => void
}

export function WorkspaceTabs({ label, tabs, onNavigate, category, mode = 'links', onTabSelect }: WorkspaceTabsProps) {
  const tabListRef = useRef<HTMLDivElement>(null)
  function handleNavigate(event: MouseEvent<HTMLAnchorElement>, path: string) {
    if (event.defaultPrevented) return

    const hasModifier =
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey ||
      event.button !== 0

    if (hasModifier) return

    event.preventDefault()
    onNavigate(path)
  }

  const focusTab = useCallback((index: number) => {
    const nodes = tabListRef.current?.querySelectorAll<HTMLElement>('[role="tab"][data-tab-key]')
    if (!nodes?.length) return
    nodes.item(((index % nodes.length) + nodes.length) % nodes.length)?.focus()
  }, [])

  /**
   * Resolve the index that arrow/Home/End navigation should advance from. In
   * tablist mode the user's intent is whichever tab actually owns focus, not
   * whichever tab is flagged active — that lets a caller reposition focus on a
   * non-selected tab (e.g. via tests or via the "Return to list" affordance
   * shared with the role list) without losing the keyboard contract. When the
   * tablist has no focused descendant we fall back to the active tab so the
   * first activation still works.
   */
  const resolveStartIndex = useCallback((): number => {
    const list = tabListRef.current
    if (list) {
      const focused = list.querySelector<HTMLElement>('[role="tab"][data-tab-key]:focus')
      if (focused) {
        const focusedKey = focused.dataset.tabKey
        const focusedIndex = tabs.findIndex((tab) => tab.key === focusedKey)
        if (focusedIndex >= 0) return focusedIndex
      }
    }
    return tabs.findIndex((tab) => tab.active)
  }, [tabs])

  function handleKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (mode !== 'tabs') return
    const startIndex = resolveStartIndex()
    if (startIndex < 0) return
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      event.preventDefault()
      const next = (startIndex + 1) % tabs.length
      onTabSelect?.(tabs[next]!.key)
      focusTab(next)
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      event.preventDefault()
      const prev = (startIndex - 1 + tabs.length) % tabs.length
      onTabSelect?.(tabs[prev]!.key)
      focusTab(prev)
    } else if (event.key === 'Home') {
      event.preventDefault()
      onTabSelect?.(tabs[0]!.key)
      focusTab(0)
    } else if (event.key === 'End') {
      event.preventDefault()
      const last = tabs.length - 1
      onTabSelect?.(tabs[last]!.key)
      focusTab(last)
    }
  }

  if (mode === 'tabs') {
    return (
      <div
        ref={tabListRef}
        className="workspace-tabs"
        role="tablist"
        aria-label={label}
        data-category={category}
        dir="inherit"
        onKeyDown={handleKeyDown}
      >
        <div className="workspace-tabs-list">
          {tabs.map((tab) => {
            const controlsId = tab.panelId ?? `${tab.key}-panel`
            const tabId = `${tab.key}-tab`
            const selected = tab.active
            return (
              <button
                key={tab.key}
                id={tabId}
                type="button"
                role="tab"
                aria-selected={selected}
                aria-controls={controlsId}
                tabIndex={selected ? 0 : -1}
                data-tab-key={tab.key}
                className={`workspace-tab ${selected ? 'workspace-tab-active' : ''}`}
                onClick={() => onTabSelect?.(tab.key)}
              >
                {tab.icon ? <span className="workspace-tab-icon" aria-hidden="true">{tab.icon}</span> : null}
                <span>{tab.label}</span>
              </button>
            )
          })}
        </div>
      </div>
    )
  }

  return (
    <nav className="workspace-tabs" aria-label={label} data-category={category}>
      <div className="workspace-tabs-list">
        {tabs.map((tab) => (
          <a
            key={tab.key}
            href={tab.path}
            aria-current={tab.active ? 'page' : undefined}
            className={`workspace-tab ${tab.active ? 'workspace-tab-active' : ''}`}
            onClick={(event) => handleNavigate(event, tab.path)}
          >
            {tab.icon ? <span className="workspace-tab-icon" aria-hidden="true">{tab.icon}</span> : null}
            <span>{tab.label}</span>
          </a>
        ))}
      </div>
    </nav>
  )
}
