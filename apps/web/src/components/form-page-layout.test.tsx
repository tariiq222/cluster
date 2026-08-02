// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FormEvent } from 'react'
import {
  FormActionStack,
  FormSection,
  ReviewSummary,
  SingleRegionFormLayout,
  TwoRegionFormLayout,
} from './form-page-layout'

/*
 * These tests pin the structural and accessibility contracts that
 * `docs/design/DESIGN-RULES.md` §2.7 makes binding for routed add/edit
 * pages. Migrating feature screens onto `TwoRegionFormLayout`,
 * `SingleRegionFormLayout`, `FormSection`, `ReviewSummary`, and
 * `FormActionStack` requires these contracts to keep holding, so the
 * tests verify DOM order, the documented class tokens, semantic tags,
 * and forwarded form props — never product copy.
 */

describe('TwoRegionFormLayout', () => {
  it('renders a real <form noValidate> at full canvas width', () => {
    const { container } = render(
      <TwoRegionFormLayout main={<span data-testid="main" />} review={<span data-testid="review" />} />,
    )
    const form = container.querySelector('form')
    expect(form).not.toBeNull()
    expect(form!.tagName).toBe('FORM')
    expect(form!.hasAttribute('noValidate')).toBe(true)
    // No narrow `max-w-*` island — the form uses the full canvas width.
    expect(form!.className).not.toMatch(/\bmax-w-/)
    // Documented grid classes that pin the desktop two-region layout.
    expect(form!.className).toMatch(/\bgrid\b/)
    expect(form!.className).toMatch(/\bgap-6\b/)
    expect(form!.className).toMatch(/lg:grid-cols-\[2fr_1fr\]/)
    expect(form!.className).toMatch(/\blg:items-start\b/)
  })

  it('places the main region as the first DOM child and the review as a sibling <aside>', () => {
    const { container } = render(
      <TwoRegionFormLayout
        main={<span data-testid="main-content">main</span>}
        review={<span data-testid="review-content">review</span>}
      />,
    )
    const form = container.querySelector('form')!
    const children = Array.from(form.children)
    // Two top-level regions, no nested surface inside either.
    expect(children).toHaveLength(2)
    const [mainWrapper, reviewWrapper] = children
    expect(mainWrapper.tagName).toBe('DIV')
    expect(reviewWrapper.tagName).toBe('ASIDE')
    // Main is the first DOM child — DOM order, not visual order, is the
    // contract for both tab order and screen-reader order.
    expect(mainWrapper.compareDocumentPosition(reviewWrapper) & Node.DOCUMENT_POSITION_FOLLOWING).not.toBe(0)
    expect(screen.getByTestId('main-content').closest('div')).toBe(mainWrapper)
    expect(screen.getByTestId('review-content').closest('aside')).toBe(reviewWrapper)
  })

  it('renders the main region as a flat bounded card without sticky positioning', () => {
    const { container } = render(
      <TwoRegionFormLayout main={<span />} review={<span />} />,
    )
    const main = container.querySelector('form')!.children[0] as HTMLElement
    expect(main).toHaveClass('rounded-xl')
    expect(main).toHaveClass('border')
    expect(main).toHaveClass('bg-card')
    expect(main).toHaveClass('p-4')
    expect(main).toHaveClass('sm:p-6')
    // Main must never be sticky — only the review aside sticks at desktop.
    expect(main.className).not.toMatch(/\bsticky\b/)
    expect(main.className).not.toMatch(/\btop-/)
  })

  it('renders the review <aside> as a flat sticky card on desktop', () => {
    const { container } = render(
      <TwoRegionFormLayout main={<span />} review={<span />} />,
    )
    const aside = container.querySelector('form')!.children[1] as HTMLElement
    expect(aside.tagName).toBe('ASIDE')
    expect(aside).toHaveClass('rounded-xl')
    expect(aside).toHaveClass('border')
    expect(aside).toHaveClass('bg-card')
    expect(aside).toHaveClass('p-4')
    expect(aside).toHaveClass('lg:sticky')
    expect(aside).toHaveClass('lg:top-20')
  })

  it('does not nest a card inside either region — children themselves must not introduce a card surface', () => {
    const { container } = render(
      <TwoRegionFormLayout
        main={
          <div data-testid="main-inner" className="grid gap-6">
            <span>child</span>
          </div>
        }
        review={
          <div data-testid="review-inner" className="grid gap-4">
            <span>child</span>
          </div>
        }
      />,
    )
    const form = container.querySelector('form')!
    const innerMain = screen.getByTestId('main-inner')
    const innerReview = screen.getByTestId('review-inner')
    expect(innerMain.closest('form')).toBe(form)
    expect(innerReview.closest('form')).toBe(form)
    // The flat surface lives only on the wrapper; the inner content
    // blocks must not bring their own rounded-xl/bg-card/border tokens.
    expect(innerMain.className).not.toMatch(/\brounded-xl\b/)
    expect(innerMain.className).not.toMatch(/\bbg-card\b/)
    expect(innerReview.className).not.toMatch(/\brounded-xl\b/)
    expect(innerReview.className).not.toMatch(/\bbg-card\b/)
  })

  it('forwards form HTML attributes (e.g. onSubmit, id, aria-labelledby)', () => {
    const onSubmit = vi.fn()
    const { container } = render(
      <TwoRegionFormLayout
        id="document-create-form"
        aria-labelledby="document-create-heading"
        onSubmit={onSubmit}
        main={<span />}
        review={<span />}
      />,
    )
    const form = container.querySelector('form')!
    expect(form).toHaveAttribute('id', 'document-create-form')
    expect(form).toHaveAttribute('aria-labelledby', 'document-create-heading')
    fireEvent.submit(form)
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })

  it('merges caller root/main/review classNames via cn() without dropping documented tokens', () => {
    const { container } = render(
      <TwoRegionFormLayout
        rootClassName="root-extra"
        mainClassName="main-extra"
        reviewClassName="review-extra"
        main={<span />}
        review={<span />}
      />,
    )
    const form = container.querySelector('form')!
    const main = form.children[0] as HTMLElement
    const aside = form.children[1] as HTMLElement
    expect(form).toHaveClass('root-extra')
    expect(form).toHaveClass('lg:grid-cols-[2fr_1fr]')
    expect(main).toHaveClass('main-extra')
    expect(main).toHaveClass('rounded-xl')
    expect(main).toHaveClass('sm:p-6')
    expect(aside).toHaveClass('review-extra')
    expect(aside).toHaveClass('lg:sticky')
  })

  it('forwards optional root/main/review test ids', () => {
    const { container } = render(
      <TwoRegionFormLayout
        testId="document-create-form"
        mainTestId="document-create-main"
        reviewTestId="document-create-review"
        main={<span />}
        review={<span />}
      />,
    )
    expect(screen.getByTestId('document-create-form')).toBeInTheDocument()
    expect(screen.getByTestId('document-create-main')).toBeInTheDocument()
    expect(screen.getByTestId('document-create-review')).toBeInTheDocument()
    expect(screen.getByTestId('document-create-main').tagName).toBe('DIV')
    expect(screen.getByTestId('document-create-review').tagName).toBe('ASIDE')
    // Sanity: form is the closest common ancestor of both regions.
    const form = screen.getByTestId('document-create-form')
    expect(container.querySelector('form')).toBe(form)
  })

  it('disables the caller noValidate override — the primitive owns noValidate=true', () => {
    const { container } = render(
      <TwoRegionFormLayout
        // @ts-expect-error – noValidate is intentionally omitted from the API.
        noValidate={false}
        main={<span />}
        review={<span />}
      />,
    )
    const form = container.querySelector('form')!
    expect(form.hasAttribute('noValidate')).toBe(true)
  })

  it('rejects caller children — the primitive owns main + review only', () => {
    // Children cannot leak in; only the main/review props populate the form.
    render(
      // @ts-expect-error – children is intentionally omitted from the API.
      <TwoRegionFormLayout main={<span data-testid="main-c" />} review={<span data-testid="review-c" />}>
        <span data-testid="leaked" />
      </TwoRegionFormLayout>,
    )
    expect(screen.queryByTestId('leaked')).toBeNull()
    expect(screen.getByTestId('main-c')).toBeInTheDocument()
    expect(screen.getByTestId('review-c')).toBeInTheDocument()
  })
})

describe('SingleRegionFormLayout', () => {
  it('renders a real <form noValidate> at centered max-w-3xl', () => {
    const { container } = render(
      <SingleRegionFormLayout>
        <span data-testid="child" />
      </SingleRegionFormLayout>,
    )
    const form = container.querySelector('form')
    expect(form).not.toBeNull()
    expect(form!.tagName).toBe('FORM')
    expect(form!.hasAttribute('noValidate')).toBe(true)
    expect(form).toHaveClass('mx-auto')
    expect(form).toHaveClass('w-full')
    expect(form).toHaveClass('max-w-3xl')
  })

  it('renders the bounded card surface with responsive padding', () => {
    const { container } = render(
      <SingleRegionFormLayout>
        <span />
      </SingleRegionFormLayout>,
    )
    const surface = container.querySelector('form > div') as HTMLElement
    expect(surface).toHaveClass('rounded-xl')
    expect(surface).toHaveClass('border')
    expect(surface).toHaveClass('bg-card')
    expect(surface).toHaveClass('p-4')
    expect(surface).toHaveClass('sm:p-6')
    expect(surface).toHaveClass('grid')
    expect(surface).toHaveClass('gap-6')
  })

  it('forwards children into the content area', () => {
    render(
      <SingleRegionFormLayout>
        <span data-testid="child">hello</span>
      </SingleRegionFormLayout>,
    )
    expect(screen.getByTestId('child')).toHaveTextContent('hello')
  })

  it('renders the actions footer separated by border-t pt-6 when actions are provided', () => {
    const { container } = render(
      <SingleRegionFormLayout
        actions={<button type="submit">save</button>}
        actionsTestId="single-actions"
      >
        <span data-testid="child" />
      </SingleRegionFormLayout>,
    )
    const footer = screen.getByTestId('single-actions')
    expect(footer).toBeInTheDocument()
    expect(footer).toHaveClass('border-t')
    expect(footer).toHaveClass('pt-6')
    expect(footer).toHaveClass('grid')
    expect(footer).toHaveClass('gap-2')
    expect(footer.querySelector('button')).not.toBeNull()
    // The footer lives inside the surface div but as a sibling of the children area.
    const surface = container.querySelector('form > div') as HTMLElement
    expect(footer.parentElement).toBe(surface)
    expect(screen.getByTestId('child').parentElement).toBe(surface)
  })

  it('omits the actions footer when actions are not provided', () => {
    const { container } = render(
      <SingleRegionFormLayout>
        <span data-testid="child" />
      </SingleRegionFormLayout>,
    )
    const surface = container.querySelector('form > div') as HTMLElement
    expect(surface.querySelector('.border-t')).toBeNull()
    // The surface must contain exactly the children area and nothing else.
    expect(surface.children).toHaveLength(1)
    expect(screen.getByTestId('child').parentElement).toBe(surface)
  })

  it('forwards form HTML attributes and merges caller classNames', () => {
    const onSubmit = vi.fn((event: FormEvent<HTMLFormElement>) => event.preventDefault())
    const { container } = render(
      <SingleRegionFormLayout
        id="confirm-form"
        aria-labelledby="confirm-heading"
        rootClassName="root-extra"
        actionsClassName="actions-extra"
        onSubmit={onSubmit}
        actions={<button type="submit">go</button>}
      >
        <span />
      </SingleRegionFormLayout>,
    )
    const form = container.querySelector('form')!
    expect(form).toHaveAttribute('id', 'confirm-form')
    expect(form).toHaveAttribute('aria-labelledby', 'confirm-heading')
    expect(form).toHaveClass('root-extra')
    expect(form).toHaveClass('max-w-3xl')
    const footer = form.querySelector('[class*="border-t"]') as HTMLElement
    expect(footer).toHaveClass('actions-extra')
    fireEvent.submit(form)
    expect(onSubmit).toHaveBeenCalledTimes(1)
  })
})

describe('FormSection', () => {
  it('renders a semantic <section aria-labelledby> pointing at the heading id', () => {
    render(
      <FormSection headingId="section-meta" title="metadata">
        <span data-testid="child" />
      </FormSection>,
    )
    const section = screen.getByRole('region', { name: 'metadata' })
    expect(section.tagName).toBe('SECTION')
    expect(section).toHaveAttribute('aria-labelledby', 'section-meta')
    const heading = screen.getByRole('heading', { level: 2, name: 'metadata' })
    expect(heading).toHaveAttribute('id', 'section-meta')
    expect(section.contains(heading)).toBe(true)
    expect(section.contains(screen.getByTestId('child'))).toBe(true)
  })

  it('renders the h2 with text-base font-semibold and never wraps content in a card', () => {
    const { container } = render(
      <FormSection headingId="section-x" title="title">
        <span data-testid="child" />
      </FormSection>,
    )
    const heading = container.querySelector('h2')!
    expect(heading).toHaveClass('text-base')
    expect(heading).toHaveClass('font-semibold')
    const section = container.querySelector('section')!
    expect(section.className).not.toMatch(/\brounded-xl\b/)
    expect(section.className).not.toMatch(/\bbg-card\b/)
  })

  it('applies comfortable density (gap-4) by default and tight (gap-2) when specified', () => {
    const { container, rerender } = render(
      <FormSection headingId="x" title="t">
        <span />
      </FormSection>,
    )
    expect(container.querySelector('section')).toHaveClass('gap-4')
    rerender(
      <FormSection headingId="x" title="t" density="tight">
        <span />
      </FormSection>,
    )
    expect(container.querySelector('section')).toHaveClass('gap-2')
  })

  it('applies border-t pt-6 when divided is true and omits it by default', () => {
    const { container, rerender } = render(
      <FormSection headingId="x" title="t">
        <span />
      </FormSection>,
    )
    const section = container.querySelector('section')!
    expect(section.className).not.toMatch(/\bborder-t\b/)
    expect(section.className).not.toMatch(/\bpt-6\b/)
    rerender(
      <FormSection headingId="x" title="t" divided>
        <span />
      </FormSection>,
    )
    const divided = container.querySelector('section')!
    expect(divided).toHaveClass('border-t')
    expect(divided).toHaveClass('pt-6')
  })

  it('renders the optional leading icon inline in the heading with aria-hidden', () => {
    function ProbeIcon() {
      return (
        <svg
          aria-hidden="true"
          className="size-4 shrink-0"
          data-testid="section-icon"
          viewBox="0 0 16 16"
        />
      )
    }
    const { container } = render(
      <FormSection headingId="x" title="t" leadingIcon={ProbeIcon}>
        <span />
      </FormSection>,
    )
    const heading = container.querySelector('h2')!
    const icon = screen.getByTestId('section-icon')
    expect(heading.contains(icon)).toBe(true)
    expect(icon).toHaveAttribute('aria-hidden', 'true')
  })

  it('forwards className and testId on the section root', () => {
    const { container } = render(
      <FormSection
        headingId="x"
        title="t"
        className="extra-class"
        testId="section-x"
      >
        <span />
      </FormSection>,
    )
    const section = container.querySelector('section')!
    expect(section).toHaveClass('extra-class')
    expect(screen.getByTestId('section-x')).toBe(section)
  })
})

describe('ReviewSummary', () => {
  it('renders a semantic <dl> with one row per entry', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[
          { label: 'title', value: 'doc' },
          { label: 'classification', value: 'internal' },
        ]}
      />,
    )
    const list = screen.getByTestId('review')
    expect(list.tagName).toBe('DL')
    expect(list.querySelectorAll('dt')).toHaveLength(2)
    expect(list.querySelectorAll('dd')).toHaveLength(2)
  })

  it('uses text-muted-foreground on <dt> and min-w-0 break-words on <dd>', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'title', value: 'doc' }]}
      />,
    )
    const list = screen.getByTestId('review')
    const dt = list.querySelector('dt')!
    const dd = list.querySelector('dd')!
    expect(dt).toHaveClass('text-muted-foreground')
    expect(dt).toHaveClass('text-xs')
    expect(dt).toHaveClass('sm:text-sm')
    expect(dd).toHaveClass('min-w-0')
    expect(dd).toHaveClass('break-words')
  })

  it('lays each row out as a responsive grid that stacks at mobile and aligns at sm', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'title', value: 'doc' }]}
      />,
    )
    const list = screen.getByTestId('review')
    const row = list.firstElementChild as HTMLElement
    expect(row).toHaveClass('grid')
    expect(row).toHaveClass('gap-1')
    expect(row).toHaveClass('sm:grid-cols-[7rem_1fr]')
    expect(row).toHaveClass('sm:items-baseline')
  })

  it('wraps string values in <bdi dir="auto"> when isolate is true', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'title', value: 'سند استلام.pdf', isolate: true }]}
      />,
    )
    const dd = screen.getByTestId('review').querySelector('dd')!
    const bdi = dd.querySelector('bdi')
    expect(bdi).not.toBeNull()
    expect(bdi).toHaveAttribute('dir', 'auto')
    expect(bdi).toHaveTextContent('سند استلام.pdf')
  })

  it('renders string values without <bdi> when isolate is false or omitted', () => {
    const { rerender } = render(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'title', value: 'plain text', isolate: false }]}
      />,
    )
    let dd = screen.getByTestId('review').querySelector('dd')!
    expect(dd.querySelector('bdi')).toBeNull()
    expect(dd).toHaveTextContent('plain text')
    rerender(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'title', value: 'plain text' }]}
      />,
    )
    dd = screen.getByTestId('review').querySelector('dd')!
    expect(dd.querySelector('bdi')).toBeNull()
  })

  it('renders non-string values as-is, including JSX compositions', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[
          {
            label: 'file',
            value: (
              <>
                <bdi dir="auto" className="break-all">
                  long-name.pdf
                </bdi>
                {' · '}
                <span>2.0 MB</span>
              </>
            ),
          },
        ]}
      />,
    )
    const dd = screen.getByTestId('review').querySelector('dd')!
    expect(dd.querySelector('bdi')).not.toBeNull()
    expect(dd.textContent).toContain('long-name.pdf')
    expect(dd.textContent).toContain('2.0 MB')
    expect(dd.textContent).toContain('·')
  })

  it('renders the empty fallback when value is null, undefined, or empty string', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[
          { label: 'a', value: null, empty: <span data-testid="empty-a">no value</span> },
          { label: 'b', value: undefined, empty: 'fallback b' },
          { label: 'c', value: '', empty: 'fallback c' },
        ]}
      />,
    )
    const list = screen.getByTestId('review')
    expect(screen.getByTestId('empty-a')).toBeInTheDocument()
    expect(list.textContent).toContain('fallback b')
    expect(list.textContent).toContain('fallback c')
    // No <bdi> is rendered for the empty string row because `isolate`
    // is not set — the empty fallback is presented plainly.
    expect(list.querySelectorAll('bdi')).toHaveLength(0)
  })

  it('still applies <bdi dir="auto"> to the empty fallback when isolate is true', () => {
    render(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'a', value: '', empty: 'placeholder', isolate: true }]}
      />,
    )
    const dd = screen.getByTestId('review').querySelector('dd')!
    const bdi = dd.querySelector('bdi')
    expect(bdi).not.toBeNull()
    expect(bdi).toHaveAttribute('dir', 'auto')
    expect(bdi).toHaveTextContent('placeholder')
  })

  it('keeps long free-form text contained via break-words instead of overflowing', () => {
    const longName = 'A'.repeat(60) + '.pdf'
    render(
      <ReviewSummary
        testId="review"
        rows={[{ label: 'file', value: longName, isolate: true }]}
      />,
    )
    const dd = screen.getByTestId('review').querySelector('dd')!
    expect(dd).toHaveClass('break-words')
    expect(dd.textContent).toContain(longName)
  })
})

describe('FormActionStack', () => {
  it('renders a Separator followed by a stacked action slot', () => {
    const { container } = render(
      <FormActionStack testId="actions">
        <button type="submit" data-testid="primary">save</button>
        <button type="button" data-testid="secondary">cancel</button>
      </FormActionStack>,
    )
    const slot = screen.getByTestId('actions')
    expect(slot.tagName).toBe('DIV')
    expect(slot).toHaveClass('grid')
    expect(slot).toHaveClass('gap-2')
    // A Separator precedes the slot — its data-slot token marks it
    // as the Radix primitive.
    const separator = container.querySelector('[data-slot="separator"]')
    expect(separator).not.toBeNull()
    // Document order: separator first, then the action slot.
    expect(
      separator!.compareDocumentPosition(slot) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).not.toBe(0)
    // Both actions render in order inside the slot.
    expect(slot.children).toHaveLength(2)
    expect(slot.children[0]).toBe(screen.getByTestId('primary'))
    expect(slot.children[1]).toBe(screen.getByTestId('secondary'))
  })

  it('merges caller className without dropping the grid+gap tokens', () => {
    render(
      <FormActionStack className="extra" testId="actions">
        <button type="submit">save</button>
      </FormActionStack>,
    )
    const slot = screen.getByTestId('actions')
    expect(slot).toHaveClass('extra')
    expect(slot).toHaveClass('grid')
    expect(slot).toHaveClass('gap-2')
  })
})
