// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import { PlatformOverviewScreen } from './PlatformOverviewScreen'

afterEach(cleanup)

describe('PlatformOverviewScreen error rendering', () => {
  it('renders the error state inline when the route hands it an error state', () => {
    render(
      <PlatformOverviewScreen
        locale="en"
        state="error"
        allowedActions={[]}
        resource={{ id: '019f8e3b-3368-7192-85a6-3da3949fd701', items: [], next_cursor: null } as never}
        token="token"
      />,
    )
    expect(screen.getByText(/data could not be loaded/i)).toBeTruthy()
  })

  it('renders the denied state when the route hands it a denied state', () => {
    render(
      <PlatformOverviewScreen
        locale="en"
        state="denied"
        allowedActions={[]}
        resource={{ id: '019f8e3b-3368-7192-85a6-3da3949fd701', items: [], next_cursor: null } as never}
        token="token"
      />,
    )
    expect(screen.getByText(/do not have access/i)).toBeTruthy()
  })

  it('renders the loading skeleton when the route hands it a loading state', () => {
    render(
      <PlatformOverviewScreen
        locale="en"
        state="loading"
        allowedActions={[]}
        resource={{ id: '019f8e3b-3368-7192-85a6-3da3949fd701', items: [], next_cursor: null } as never}
        token="token"
      />,
    )
    expect(screen.getByLabelText(/loading platform data/i)).toBeTruthy()
  })
})
