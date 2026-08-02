import { describe, expect, it } from 'vitest'
import { commandShortcut } from './keyboard-shortcut'

describe('commandShortcut', () => {
  it('uses the Command symbol on Apple platforms', () => {
    expect(commandShortcut({ userAgentData: { platform: 'macOS' } })).toBe('⌘K')
    expect(commandShortcut({ userAgent: 'Mozilla/5.0 (iPhone)' })).toBe('⌘K')
  })

  it('uses Ctrl on Windows, Linux, and unknown platforms', () => {
    expect(commandShortcut({ userAgentData: { platform: 'Windows' } })).toBe(
      'Ctrl+K',
    )
    expect(
      commandShortcut({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64)' }),
    ).toBe('Ctrl+K')
    expect(commandShortcut({})).toBe('Ctrl+K')
  })
})
