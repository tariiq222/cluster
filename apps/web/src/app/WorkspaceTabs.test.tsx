// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { House } from 'lucide-react'
import { WorkspaceTabs, type WorkspaceTab } from './WorkspaceTabs'

describe('WorkspaceTabs', () => {
  const tabs: WorkspaceTab[] = [
    { key: 'tasks', label: 'مهامي', path: '/tasks', active: true, icon: <House aria-hidden="true" /> },
    { key: 'documents', label: 'المستندات', path: '/documents', active: false },
    { key: 'people', label: 'الأشخاص', path: '/people', active: false },
  ]

  it('renders labelled route links with real hrefs and one current page', () => {
    render(<WorkspaceTabs label="منطقة العمل" tabs={tabs} onNavigate={() => {}} />)

    const navigation = screen.getByRole('navigation', { name: 'منطقة العمل' })
    const tabsButtons = within(navigation).getAllByRole('link')

    expect(tabsButtons).toHaveLength(3)
    expect(screen.getByRole('link', { name: 'مهامي' }).getAttribute('href')).toBe('/tasks')
    expect(screen.getByRole('link', { name: 'مهامي' }).getAttribute('aria-current')).toBe('page')
    expect(screen.getByRole('link', { name: 'المستندات' }).getAttribute('aria-current')).toBeNull()
  })

  it('intercepts primary navigation clicks and calls onNavigate with the path', () => {
    const onNavigate = vi.fn()
    render(<WorkspaceTabs label="منطقة العمل" tabs={tabs} onNavigate={onNavigate} />)

    const documentsTab = screen.getByRole('link', { name: 'المستندات' }) as HTMLAnchorElement
    fireEvent.click(documentsTab)

    expect(onNavigate).toHaveBeenCalledTimes(1)
    expect(onNavigate).toHaveBeenCalledWith('/documents')
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })
})
describe('WorkspaceTabs tablist semantics', () => {
  const tabs: WorkspaceTab[] = [
    { key: 'tasks', label: 'مهامي', path: '/tasks', active: true, panelId: 'tasks-panel' },
    { key: 'documents', label: 'المستندات', path: '/documents', active: false, panelId: 'documents-panel' },
    { key: 'people', label: 'الأشخاص', path: '/people', active: false, panelId: 'people-panel' },
  ]

  it('renders a tablist with role=tab and aria-selected when mode is "tabs"', () => {
    const onTabSelect = vi.fn()
    render(
      <WorkspaceTabs
        label="منطقة العمل"
        tabs={tabs}
        onNavigate={() => {}}
        mode="tabs"
        onTabSelect={onTabSelect}
      />,
    )
    const list = screen.getByRole('tablist', { name: 'منطقة العمل' })
    const items = within(list).getAllByRole('tab')
    expect(items).toHaveLength(3)
    expect(items[0]?.getAttribute('aria-selected')).toBe('true')
    expect(items[0]?.getAttribute('aria-controls')).toBe('tasks-panel')
    expect(items[1]?.getAttribute('aria-selected')).toBe('false')
  })

  it('cycles selection forward with ArrowRight and notifies onTabSelect', () => {
    const onTabSelect = vi.fn()
    render(
      <WorkspaceTabs
        label="منطقة العمل"
        tabs={tabs}
        onNavigate={() => {}}
        mode="tabs"
        onTabSelect={onTabSelect}
      />,
    )
    const list = screen.getByRole('tablist', { name: 'منطقة العمل' })
    fireEvent.keyDown(list, { key: 'ArrowRight' })
    expect(onTabSelect).toHaveBeenCalledWith('documents')
  })
  it('moves focus within its own tablist when multiple workspaces are mounted', () => {
    render(<><WorkspaceTabs label="first" tabs={tabs} onNavigate={() => {}} mode="tabs" onTabSelect={() => {}} /><WorkspaceTabs label="second" tabs={tabs} onNavigate={() => {}} mode="tabs" onTabSelect={() => {}} /></>)
    const second = screen.getByRole('tablist', { name: 'second' })
    const secondTabs = within(second).getAllByRole('tab')
    secondTabs[0]?.focus()
    fireEvent.keyDown(second, { key: 'ArrowRight' })
    expect(document.activeElement).toBe(secondTabs[1])
  })
  afterEach(() => cleanup())
})
