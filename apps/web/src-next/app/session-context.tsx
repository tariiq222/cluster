import { createContext, useContext, useMemo, type ReactNode } from 'react'
import type { Session } from '../api/session'
import type { Locale } from '../i18n'

interface SessionContextValue {
  session: Session
  locale: Locale
  setLocale: (locale: Locale) => void
}

const SessionContext = createContext<SessionContextValue | null>(null)

export function SessionProvider({
  session,
  locale,
  setLocale,
  children,
}: {
  session: Session
  locale: Locale
  setLocale: (locale: Locale) => void
  children: ReactNode
}) {
  const value = useMemo(() => ({ session, locale, setLocale }), [session, locale, setLocale])
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession(): SessionContextValue {
  const context = useContext(SessionContext)
  if (!context) throw new Error('useSession must be used within SessionProvider')
  return context
}

export function useLocale(): Locale {
  return useSession().locale
}

export function useSessionToken(): string {
  return useSession().session.csrfToken
}

export function useSetLocale(): (locale: Locale) => void {
  return useSession().setLocale
}
