// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PageHeader, PageLayout } from './page-layout'

/*
 * PageLayout and PageHeader own the centered max-width shell, the
 * six-unit vertical rhythm, and the single H1 page header that every
 * workspace in the app shares. They are non-semantic on purpose: the
 * shell already owns the content landmark, so the wrapper renders a
 * plain `div` and the header renders a heading that the caller is
 * responsible for picking a level for (PageHeader always renders h1
 * because the binding design rules require exactly one page H1).
 *
 * These tests pin the documented shell classes, the H1 invariant, the
 * optional description / meta / actions / heading id props, and the
 * className merge contract so future call-sites can rely on them.
 */

describe('PageLayout', () => {
  it('renders the centered max-width shell with the six-unit vertical rhythm', () => {
    const { container } = render(
      <PageLayout>
        <span data-testid="child" />
      </PageLayout>,
    )
    const root = container.firstElementChild
    expect(root).not.toBeNull()
    expect(root!.tagName).toBe('DIV')
    expect(root).toHaveClass('mx-auto')
    expect(root).toHaveClass('w-full')
    expect(root).toHaveClass('max-w-6xl')
    expect(root).toHaveClass('min-w-0')
    expect(root).toHaveClass('space-y-6')
  })

  it('forwards children into the shell', () => {
    render(
      <PageLayout>
        <span data-testid="child" />
      </PageLayout>,
    )
    expect(screen.getByTestId('child')).toBeInTheDocument()
  })

  it('merges the caller className without dropping the documented shell classes', () => {
    const { container } = render(
      <PageLayout className="extra-class">
        <span />
      </PageLayout>,
    )
    const root = container.firstElementChild
    expect(root).toHaveClass('mx-auto')
    expect(root).toHaveClass('max-w-6xl')
    expect(root).toHaveClass('space-y-6')
    expect(root).toHaveClass('extra-class')
  })
})

describe('PageHeader', () => {
  it('renders exactly one H1 with the documented type ramp', () => {
    const { container } = render(<PageHeader title="العنوان" />)
    const headings = container.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    const heading = headings[0]
    expect(heading.tagName).toBe('H1')
    expect(heading).toHaveClass('text-2xl')
    expect(heading).toHaveClass('font-semibold')
    expect(heading).toHaveClass('tracking-tight')
    expect(heading).toHaveTextContent('العنوان')
  })

  it('renders the description with the muted-foreground semantic token class', () => {
    render(<PageHeader title="العنوان" description="وصف" />)
    const description = screen.getByText('وصف')
    expect(description).toHaveClass('text-muted-foreground')
    expect(description).toHaveClass('text-sm')
  })

  it('omits the description when not provided', () => {
    const { container } = render(<PageHeader title="العنوان" />)
    // Only the H1 should be present; no extra paragraph.
    expect(container.querySelectorAll('p')).toHaveLength(0)
  })

  it('renders the meta slot beside the title (e.g. a status badge)', () => {
    render(
      <PageHeader
        title="العنوان"
        meta={<span data-testid="meta">قيد المراجعة</span>}
      />,
    )
    const meta = screen.getByTestId('meta')
    expect(meta).toBeInTheDocument()
    const heading = screen.getByRole('heading', { level: 1 })
    expect(heading.compareDocumentPosition(meta) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
  })

  it('renders the actions region and keeps it on the trailing edge of the header', () => {
    render(
      <PageHeader
        title="العنوان"
        actions={<button type="button">إجراء</button>}
      />,
    )
    const action = screen.getByRole('button', { name: 'إجراء' })
    expect(action).toBeInTheDocument()
    const heading = screen.getByRole('heading', { level: 1 })
    expect(heading.compareDocumentPosition(action) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
  })

  it('forwards the optional heading id to the H1', () => {
    render(<PageHeader title="العنوان" headingId="custom-id" />)
    const heading = screen.getByRole('heading', { level: 1 })
    expect(heading).toHaveAttribute('id', 'custom-id')
  })

  it('merges the caller className without losing the responsive flex layout', () => {
    const { container } = render(
      <PageHeader title="العنوان" className="extra-class" />,
    )
    const header = container.firstElementChild
    expect(header).not.toBeNull()
    expect(header!.className).toMatch(/\bflex\b/)
    expect(header!.className).toMatch(/\bflex-wrap\b/)
    expect(header).toHaveClass('extra-class')
  })
})
