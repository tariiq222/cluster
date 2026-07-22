import { useCallback, useEffect, useState } from 'react'

import { LoginScreen } from './app/LoginScreen'
import { LOCALE_KEY, initialLocale, text, type Locale } from './app/copy'
import { AppWorkspace } from './app/AppWorkspace'
import { clearSessionMetadata, identityLogout, registerSessionExpiredHandler, restoreSession, SESSION_METADATA_KEY, type Session } from './api'

function App() {
  const [locale, setLocale] = useState<Locale>(initialLocale)
  const [session, setSession] = useState<Session | null>(null)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [authChecked, setAuthChecked] = useState(false)

  useEffect(() => {
    let cancelled = false
    const hadStoredSession = (() => {
      try {
        return window.sessionStorage.getItem(SESSION_METADATA_KEY) !== null
      } catch {
        return false
      }
    })()

    async function bootstrapSession() {
      try {
        const restoredSession = await restoreSession()
        if (cancelled) return
        if (restoredSession) {
          setSession(restoredSession)
          setSessionExpired(false)
          return
        }
        setSession(null)
        setSessionExpired(hadStoredSession)
      } catch {
        if (cancelled) return
        setSession(null)
        setSessionExpired(hadStoredSession)
      } finally {
        if (!cancelled) setAuthChecked(true)
      }
    }

    void bootstrapSession()

    return () => {
      cancelled = true
    }
  }, [])

  const expireSession = useCallback(() => {
    clearSessionMetadata()
    setSession(null)
    setSessionExpired(true)
  }, [])

  // Any 401 from any endpoint ends the session, so screens do not each detect it.
  useEffect(() => {
    registerSessionExpiredHandler(expireSession)
    return () => registerSessionExpiredHandler(null)
  }, [expireSession])

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = text[locale].ltr
    try {
      window.localStorage.setItem(LOCALE_KEY, locale)
    } catch {
      // Ignore storage failures.
    }
  }, [locale])

  async function handleLogout() {
    if (!session) return
    try {
      await identityLogout(session.csrf_token)
    } catch {
      // Keep the local sign-out flow resilient even when the server rejects or times out.
    } finally {
      clearSessionMetadata()
      setSession(null)
      setSessionExpired(false)
    }
  }

  if (!authChecked) {
    return <section className="state-panel" aria-live="polite"><p>{text[locale].signingIn}</p></section>
  }

  if (!session) {
    return (
      <LoginScreen
        locale={locale}
        sessionExpired={sessionExpired}
        onLocaleChange={() => setLocale((current) => current === 'ar' ? 'en' : 'ar')}
        onAuthenticated={(authenticatedSession) => {
          setSession(authenticatedSession)
          setSessionExpired(false)
        }}
      />
    )
  }

  return (
    <AppWorkspace
      locale={locale}
      session={session}
      onLocaleChange={() => setLocale((current) => current === 'ar' ? 'en' : 'ar')}
      onLogout={handleLogout}
    />
  )
}

export default App
