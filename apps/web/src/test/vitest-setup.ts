import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// The Tasks 2-7 lane tests use jest-dom matchers (toBeVisible, toHaveAttribute, …).
// Loading the matchers here keeps every test file consistent without each one
// needing its own setup block, and the afterEach cleanup keeps render() calls
// isolated between tests so the AccessWorkspace / Reports lanes don't leak
// elements into sibling assertions.
afterEach(() => {
  cleanup()
})