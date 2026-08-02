import { useCallback, useEffect, useState } from 'react'
import { DirectionProvider } from '@radix-ui/react-direction'
import { toast } from 'sonner'
import { clearStoredSession, identityLogout, login, restoreSession, storedSession, type Session } from '../api/session'
import { registerSessionExpiredHandler } from '../api/http'
import { directionForLocale, initialLocale, shellCopy, LOCALE_KEY, type Locale } from '../i18n'
import { LoginScreen } from './LoginScreen'
import { SessionProvider } from './session-context'
import { PrincipalProvider } from './principal-context'
import { AppRouter } from '../router'
import { Skeleton } from '@/components/ui/skeleton'

export function App() {
  const [locale, setLocaleState] = useState<Locale>(initialLocale)
  const [session, setSession] = useState<Session | null>(storedSession)
  const [authChecked, setAuthChecked] = useState(false)

  const expireSession = useCallback(() => {
    clearStoredSession()
    setSession(null)
    // A fresh browser's session-restore probe 401s too; only a real session
    // expiry deserves the toast (PAGES.md: الدخول والجلسة).
    if (session !== null) {
      toast(shellCopy[locale].sessionExpired)
    }
  }, [session, locale])

  useEffect(() => {
    registerSessionExpiredHandler(expireSession)
    return () => registerSessionExpiredHandler(() => {})
  }, [expireSession])

  useEffect(() => {
    let cancelled = false
    const restore = async () => {
      try {
        const restored = await restoreSession()
        if (cancelled) return
        if (restored) setSession(restored)
      } catch {
        // network failure: fall back to stored session if any
      } finally {
        if (!cancelled) setAuthChecked(true)
      }
    }
    if (!storedSession()) {
      void restore()
    } else {
      setAuthChecked(true)
    }
    return () => {
      cancelled = true
    }
  }, [])

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next)
    localStorage.setItem(LOCALE_KEY, next)
  }, [])

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = directionForLocale(locale)
  }, [locale])

  const handleLogin = useCallback(async (username: string, password: string) => {
    const next = await login(username, password)
    setSession(next)
    setAuthChecked(true)
  }, [])

  const handleLogout = useCallback(() => {
    const current = storedSession()
    if (current) void identityLogout(current.csrfToken)
    clearStoredSession()
    setSession(null)
  }, [])

  if (!authChecked) {
    // Session restore in flight: an accessible skeleton shaped like the login
    // surface (no spinner), styled with current theme utilities only.
    return (
      <main className="flex min-h-svh items-center justify-center bg-background p-4" data-testid="auth-restore">
        <div className="w-full max-w-sm" role="status" aria-live="polite">
          <div className="space-y-3">
            <div className="flex justify-center">
              <Skeleton className="h-6 w-40 rounded-full" />
            </div>
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
          <p className="mt-3 text-center text-sm text-muted-foreground">{shellCopy[locale].signingIn}</p>
        </div>
      </main>
    )
  }

  if (!session) {
    return <LoginScreen locale={locale} setLocale={setLocale} onLogin={handleLogin} />
  }

  return (
    <DirectionProvider dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <SessionProvider session={session} locale={locale} setLocale={setLocale}>
        <PrincipalProvider>
          <AppRouter onLogout={handleLogout} />
        </PrincipalProvider>
      </SessionProvider>
    </DirectionProvider>
  )
}
