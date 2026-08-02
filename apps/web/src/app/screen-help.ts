import { createContext, useContext, useEffect } from 'react'
import { useLocation } from 'react-router-dom'

export interface ScreenHelp {
  currentState?: string
  activeSection?: string
  permittedNextAction?: string
  recoveryGuidance?: readonly string[]
  correlationId?: string | null
}

export interface RegisteredScreenHelp {
  pathname: string
  help: ScreenHelp
}

export interface ScreenHelpContextValue {
  register: (registration: RegisteredScreenHelp) => () => void
}

export const ScreenHelpContext = createContext<ScreenHelpContextValue | null>(
  null,
)

export function useScreenHelp(help: ScreenHelp) {
  const context = useContext(ScreenHelpContext)
  const { pathname } = useLocation()

  useEffect(() => {
    if (!context) return
    return context.register({ pathname, help })
  }, [context, help, pathname])
}
