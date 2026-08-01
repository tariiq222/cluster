// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render } from '@testing-library/react'
import { ApiError } from '@/api/http'
import { DeniedState, ResourceBoundary } from './states'

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
