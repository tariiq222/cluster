// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { PlatformHealthScreen } from './PlatformHealthScreen'
afterEach(cleanup)
describe('PlatformHealthScreen', () => {
  it('renders safe latency and no exception trace', () => {
    render(<PlatformHealthScreen locale="en" />)
    expect(screen.getByText('Database — 18ms')).toBeTruthy()
    expect(screen.queryByText(/Exception|Stack trace/)).toBeNull()
  })
})
