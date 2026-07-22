import { createContext, useContext, useMemo, type ReactNode } from 'react'

import type { Session } from '../api'
import type { Locale } from './copy'

/**
 * Locale and the session CSRF token are read by nearly every screen but owned by the shell.
 * Passing them down as props meant every intermediate workspace had to forward values it
 * did not use; this context lets a screen ask for exactly what it needs.
 */
type SessionContextValue = {
  locale: Locale
  session: Session
}

const SessionContext = createContext<SessionContextValue | null>(null)

export function SessionProvider({
  locale,
  session,
  children,
}: SessionContextValue & { children: ReactNode }) {
  const value = useMemo(() => ({ locale, session }), [locale, session])
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

function useSessionContext(): SessionContextValue {
  const value = useContext(SessionContext)
  if (!value) {
    throw new Error('This component must be rendered inside a SessionProvider.')
  }
  return value
}

/** The active interface language. */
export function useLocale(): Locale {
  return useSessionContext().locale
}

/** The authenticated session, including the CSRF token used for commands. */
export function useSession(): Session {
  return useSessionContext().session
}

/** Shorthand for the CSRF token, which is what most API wrappers actually take. */
export function useToken(): string {
  return useSessionContext().session.access_token
}
