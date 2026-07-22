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
