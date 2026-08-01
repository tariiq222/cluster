// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ReviewStep } from './steps/ReviewStep'
import { SessionProvider } from '../../app/session-context'

const session = { csrfToken: 'x', userId: 'u', expiresAt: '2026-12-31T00:00:00Z', restricted: false }

const rows = [
  { id: 'r1', row_number: 1, proposed_action: 'create', decision: null, validation_errors: [] },
  { id: 'r2', row_number: 2, proposed_action: 'create', decision: null, validation_errors: [] },
  { id: 'r3', row_number: 3, proposed_action: 'create', decision: null, validation_errors: [] },
  {
    id: 'r4',
    row_number: 4,
    proposed_action: 'create',
    decision: null,
    validation_errors: [{ code: 'missing-field', severity: 'blocking', field: 'name_ar' }],
  },
]

describe('import review step', () => {
  it('filters to blocking rows by default on the review step', () => {
    render(
      <SessionProvider session={session} locale="ar" setLocale={() => {}}>
        <ReviewStep
          rows={rows}
          status="validated"
          onTransition={() => {}}
        />
      </SessionProvider>,
    )
    expect(screen.queryByText('رقم السجل ١')).toBeNull()
    expect(screen.queryByText('رقم السجل ٢')).toBeNull()
    expect(screen.getByText('رقم السجل ٤')).toBeInTheDocument()
    expect(screen.getByText(/missing-field/)).toBeInTheDocument()
    const showAll = screen.getByRole('button', { name: /عرض الكل|show all/i })
    fireEvent.click(showAll)
    expect(screen.getByText('رقم السجل ١')).toBeInTheDocument()
    expect(screen.getByText('رقم السجل ٣')).toBeInTheDocument()
  })
})
