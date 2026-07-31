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
    render(
      <>
        <WorkspaceTabs
          label="first"
          tabs={tabs}
          onNavigate={() => {}}
          mode="tabs"
          onTabSelect={() => {}}
        />
        <WorkspaceTabs
          label="second"
          tabs={tabs}
          onNavigate={() => {}}
          mode="tabs"
          onTabSelect={() => {}}
        />
      </>,
    )
    const second = screen.getByRole('tablist', { name: 'second' })
    const secondTabs = within(second).getAllByRole('tab')
    secondTabs[0]?.focus()
    fireEvent.keyDown(second, { key: 'ArrowRight' })
    expect(document.activeElement).toBe(secondTabs[1])
  })

  /**
   * Keyboard navigation must advance relative to whichever tab actually owns
   * focus — not whichever tab the workspace currently marks active. The
   * "focused tab" branch matters when a caller moves focus to a non-selected
   * tab (tests, the role-list "return" affordance, screen-reader landing), or
   * when selection and focus temporarily diverge after a recovery flow.
   */
  it('ArrowRight on a focused non-active tab advances from that tab', () => {
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
    const allTabs = within(list).getAllByRole('tab')
    const peopleTab = within(list).getByRole('tab', { name: 'الأشخاص' })

    peopleTab.focus()
    fireEvent.keyDown(list, { key: 'ArrowRight' })

    expect(onTabSelect).toHaveBeenCalledWith('tasks')
    expect(document.activeElement).toBe(allTabs[0])
  })

  it('ArrowLeft on a focused non-active tab walks back from that tab', () => {
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
    const allTabs = within(list).getAllByRole('tab')
    const documentsTab = within(list).getByRole('tab', { name: 'المستندات' })

    documentsTab.focus()
    fireEvent.keyDown(list, { key: 'ArrowLeft' })

    expect(onTabSelect).toHaveBeenCalledWith('tasks')
    expect(document.activeElement).toBe(allTabs[0])
  })

  it('Home always jumps to the first tab regardless of which tab owns focus', () => {
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
    const allTabs = within(list).getAllByRole('tab')
    const peopleTab = within(list).getByRole('tab', { name: 'الأشخاص' })

    peopleTab.focus()
    fireEvent.keyDown(list, { key: 'Home' })

    expect(onTabSelect).toHaveBeenCalledWith('tasks')
    expect(document.activeElement).toBe(allTabs[0])
  })

  it('End always jumps to the last tab regardless of which tab owns focus', () => {
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
    const allTabs = within(list).getAllByRole('tab')
    const tasksTab = within(list).getByRole('tab', { name: 'مهامي' })

    tasksTab.focus()
    fireEvent.keyDown(list, { key: 'End' })

    expect(onTabSelect).toHaveBeenCalledWith('people')
    expect(document.activeElement).toBe(allTabs[2])
  })

  it('falls back to the active tab when nothing inside the tablist owns focus', () => {
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

    expect(onTabSelect).toHaveBeenCalledTimes(1)
    expect(onTabSelect).toHaveBeenCalledWith('documents')
  })

  it('click activation still calls onTabSelect from the clicked tab only', () => {
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
    const peopleTab = within(list).getByRole('tab', { name: 'الأشخاص' })
    fireEvent.click(peopleTab)
    expect(onTabSelect).toHaveBeenCalledTimes(1)
    expect(onTabSelect).toHaveBeenCalledWith('people')
  })

  it('ignores arrow keys when the workspace renders in link semantics', () => {
    const onTabSelect = vi.fn()
    render(
      <WorkspaceTabs
        label="منطقة العمل"
        tabs={tabs}
        onNavigate={() => {}}
        onTabSelect={onTabSelect}
      />,
    )
    const nav = screen.getByRole('navigation', { name: 'منطقة العمل' })
    const documentsLink = within(nav).getByRole('link', { name: 'المستندات' })
    documentsLink.focus()
    fireEvent.keyDown(nav, { key: 'ArrowRight' })
    expect(onTabSelect).not.toHaveBeenCalled()
  })

  afterEach(() => cleanup())
})
