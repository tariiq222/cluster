// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react'
import { useState } from 'react'
import {
  MemoryRouter,
  Route,
  Routes,
  useLocation,
  useSearchParams,
} from 'react-router-dom'
import { useWorkspaceTab } from './use-workspace-tab'

/*
 * The hook owns the contract for "URL-backed active tab":
 *
 *  1. the initial value comes from `?tab=` if present and valid,
 *  2. a caller-driven change writes `?tab=` back with `replace: true`
 *     so the back stack is not flooded with tab toggles,
 *  3. an external URL change (back/forward, a redirect that rewrote
 *     the query, a programmatic `setSearchParams`) is re-read and
 *     applied so the tab follows the URL on refresh and history,
 *  4. an invalid value (missing predicate, rejected by the predicate)
 *     normalizes to the default so the screen never gets stuck on a
 *     tab that no longer renders.
 */

const Harness = ({
  predicate,
}: {
  predicate?: (value: string) => boolean
}) => {
  const [tab, setTab] = useWorkspaceTab<'one' | 'two' | 'three'>(
    'tab',
    'one',
    predicate as ((value: string) => value is 'one' | 'two' | 'three') | undefined,
  )
  return (
    <div>
      <span data-testid="current-tab">{tab}</span>
      <button type="button" onClick={() => setTab('two')}>
        set two
      </button>
      <button type="button" onClick={() => setTab('three')}>
        set three
      </button>
    </div>
  )
}

function SearchParamsProbe() {
  const location = useLocation()
  return <span data-testid="search">{location.search}</span>
}

describe('useWorkspaceTab', () => {
  it('initializes from the ?tab= query when present and valid', () => {
    render(
      <MemoryRouter initialEntries={['/?tab=two']}>
        <Harness />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('current-tab')).toHaveTextContent('two')
  })

  it('falls back to the default when the query is absent', () => {
    render(
      <MemoryRouter initialEntries={['/']}>
        <Harness />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('current-tab')).toHaveTextContent('one')
  })

  it('writes ?tab= with replace so the back stack is not flooded', () => {
    render(
      <MemoryRouter initialEntries={['/']}>
        <Harness />
        <SearchParamsProbe />
      </MemoryRouter>,
    )
    fireEvent.click(screen.getByRole('button', { name: 'set two' }))
    expect(screen.getByTestId('current-tab')).toHaveTextContent('two')
    expect(screen.getByTestId('search')).toHaveTextContent('?tab=two')
  })

  it('normalizes to the default when the predicate rejects the URL value', () => {
    render(
      <MemoryRouter initialEntries={['/?tab=foo']}>
        <Harness
          predicate={(value) => value === 'one' || value === 'two' || value === 'three'}
        />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('current-tab')).toHaveTextContent('one')
  })

  it('re-reads the URL on external changes so back/forward navigates the tab', () => {
    function ExternalNav() {
      const [, setParams] = useSearchParams()
      return (
        <>
          <button type="button" onClick={() => setParams({ tab: 'three' })}>
            go three
          </button>
          <button type="button" onClick={() => setParams({ tab: 'two' })}>
            go two
          </button>
        </>
      )
    }
    render(
      <MemoryRouter initialEntries={['/?tab=two']}>
        <Harness />
        <ExternalNav />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('current-tab')).toHaveTextContent('two')
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'go three' }))
    })
    expect(screen.getByTestId('current-tab')).toHaveTextContent('three')
    // Simulate back/forward: the next external change must drive the
    // hook's value to the URL again, not back to the caller-driven state.
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'go two' }))
    })
    expect(screen.getByTestId('current-tab')).toHaveTextContent('two')
  })

  it('normalizes to the default when the query is removed externally', () => {
    function ExternalStripper() {
      const [, setParams] = useSearchParams()
      return (
        <button type="button" onClick={() => setParams({}, { replace: true })}>
          strip
        </button>
      )
    }
    render(
      <MemoryRouter initialEntries={['/?tab=three']}>
        <Harness />
        <ExternalStripper />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('current-tab')).toHaveTextContent('three')
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'strip' }))
    })
    expect(screen.getByTestId('current-tab')).toHaveTextContent('one')
  })

  it('exposes both the current value and a setter tuple to the caller', () => {
    const { result } = renderHook(() => useWorkspaceTab<'x' | 'y'>('tab', 'x'), {
      wrapper: ({ children }) => <MemoryRouter initialEntries={['/']}>{children}</MemoryRouter>,
    })
    expect(result.current[0]).toBe('x')
    expect(typeof result.current[1]).toBe('function')
  })

  it('works inside a deeply nested route without losing the query scope', () => {
    function Deep() {
      return (
        <Routes>
          <Route path="/me" element={<Harness />} />
        </Routes>
      )
    }
    render(
      <MemoryRouter initialEntries={['/me?tab=two']}>
        <Deep />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('current-tab')).toHaveTextContent('two')
  })

  it('keeps the React useState reachable through a baseline render', () => {
    const Probe = () => {
      const [value] = useState('baseline')
      return <span data-testid="baseline">{value}</span>
    }
    render(
      <MemoryRouter initialEntries={['/']}>
        <Probe />
      </MemoryRouter>,
    )
    expect(screen.getByTestId('baseline')).toHaveTextContent('baseline')
  })
})
