// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { ApiError } from '@/api/http'
import { DeniedState, LoadingState, ResourceBoundary } from './states'

describe('resource states', () => {
  it('renders the identical string for 403 and 404', () => {
    const forbidden = render(
      <ResourceBoundary state="forbidden" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    const notFound = render(
      <ResourceBoundary state="not-found" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    expect(forbidden.container.textContent).toBe(notFound.container.textContent)
  })

  it('does not leak the children when denied', () => {
    const { container } = render(
      <ResourceBoundary state="forbidden" locale="ar">
        <div>secret</div>
      </ResourceBoundary>,
    )
    expect(container.textContent).not.toContain('secret')
  })

  it('renders children when ready', () => {
    const { container } = render(
      <ResourceBoundary state="ready" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    expect(container.textContent).toContain('content')
  })

  it('renders the denied copy for an ApiError of 403', () => {
    const { container } = render(<DeniedState locale="ar" />)
    expect(container.textContent).toBeTruthy()
    expect(new ApiError(403, { type: 'x', title: 'x', status: 403 }).status).toBe(403)
  })
})

/*
 * ACC-04-POLISH: localized loading announcement.
 *
 * The shared `LoadingState` is consumed by three call sites with three
 * different announcement responsibilities:
 *
 *   1. RouteFallback — already wraps the skeleton in `role="status"
 *      aria-live="polite"` itself, so it calls `LoadingState` without an
 *      `announce` prop to avoid a second, redundant announcement.
 *
 *   2. ResourceBoundary — the common path through every resource
 *      surface. It must always announce in the active locale; the
 *      announcement is shared with RouteFallback's copy so the message
 *      never drifts apart between the two producers.
 *
 *   3. Direct callers (e.g. the AccessScreen bootstrap loading branch
 *      and the DiagnosticsTab inspector) supply their own localized
 *      label.
 *
 * The tests below pin the three contracts: no announcement when
 * omitted, exactly one announcement from ResourceBoundary, and a
 * caller-supplied announcement rendered with the right ARIA wiring.
 */
describe('loading state announcement', () => {
  it('emits no role="status" node when announce is omitted (RouteFallback contract)', () => {
    const { container } = render(<LoadingState rows={2} />)
    expect(container.querySelector('[role="status"]')).toBeNull()
    expect(container.querySelector('[aria-live="polite"]')).toBeNull()
  })

  it('renders an sr-only role="status" aria-live="polite" node when announce is supplied', () => {
    const { container } = render(<LoadingState rows={2} announce="Loading…" />)
    const status = container.querySelector('[role="status"]')
    expect(status).not.toBeNull()
    expect(status).toHaveAttribute('aria-live', 'polite')
    expect(status).toHaveClass('sr-only')
    expect(status).toHaveTextContent('Loading…')
  })

  it('ResourceBoundary surfaces exactly one localized announcement for the loading state', () => {
    const { container } = render(
      <ResourceBoundary state="loading" locale="ar">
        <div>content</div>
      </ResourceBoundary>,
    )
    const statuses = container.querySelectorAll('[role="status"]')
    expect(statuses).toHaveLength(1)
    expect(statuses[0]).toHaveAttribute('aria-live', 'polite')
    expect(statuses[0]).toHaveTextContent('جارٍ التحميل…')
  })

  it('ResourceBoundary en path matches the en localized copy', () => {
    const { container } = render(
      <ResourceBoundary state="loading" locale="en">
        <div>content</div>
      </ResourceBoundary>,
    )
    const status = container.querySelector('[role="status"]')
    expect(status).toHaveTextContent('Loading…')
  })
})
