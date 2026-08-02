import {
  useCallback,
  useMemo,
  useRef,
  type ReactNode,
} from 'react'
import {
  ScreenHelpContext,
  type RegisteredScreenHelp,
} from './screen-help'

export function ScreenHelpProvider({
  children,
  onChange,
}: {
  children: ReactNode
  onChange: (registration: RegisteredScreenHelp | null) => void
}) {
  const activeOwner = useRef<symbol | null>(null)
  const register = useCallback(
    (registration: RegisteredScreenHelp) => {
      const owner = Symbol('screen-help')
      activeOwner.current = owner
      onChange(registration)

      return () => {
        if (activeOwner.current !== owner) return
        activeOwner.current = null
        onChange(null)
      }
    },
    [onChange],
  )
  const value = useMemo(() => ({ register }), [register])

  return (
    <ScreenHelpContext.Provider value={value}>
      {children}
    </ScreenHelpContext.Provider>
  )
}
