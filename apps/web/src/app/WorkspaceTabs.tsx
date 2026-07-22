import { type MouseEvent, type ReactNode } from 'react'

import './WorkspaceTabs.css'

export type WorkspaceTab = {
  key: string
  label: string
  path: string
  active: boolean
  icon?: ReactNode
}

export type WorkspaceTabsProps = {
  label: string
  tabs: WorkspaceTab[]
  onNavigate: (path: string) => void
}

export function WorkspaceTabs({ label, tabs, onNavigate }: WorkspaceTabsProps) {
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

  return (
    <nav className="workspace-tabs" aria-label={label}>
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
