import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from 'react'
import { DirectionProvider } from '@radix-ui/react-direction'
import { toast } from 'sonner'
import {
  identityLogout,
  login,
  restoreSession,
  type Session,
} from '../api/session'
import { registerSessionExpiredHandler } from '../api/http'
import {
  directionForLocale,
  initialLocale,
  shellCopy,
  LOCALE_KEY,
  type Locale,
} from '../i18n'
import { LoginScreen } from './LoginScreen'
import { SessionProvider } from './session-context'
import { PrincipalProvider } from './principal-context'
import { AppRouter } from '../router'
import { Skeleton } from '@/components/ui/skeleton'

export function App() {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            retry: 1,
            refetchOnWindowFocus: false,
          },
        },
      }),
  )
  const [locale, setLocaleState] = useState<Locale>(initialLocale)
  const [session, setSession] = useState<Session | null>(null)
  const [authChecked, setAuthChecked] = useState(false)

  const isolateIdentity = useCallback(async () => {
    await queryClient.cancelQueries()
    queryClient.clear()
  }, [queryClient])

  const expireSession = useCallback(() => {
    void (async () => {
      const hadSession = session !== null
      await isolateIdentity()
      setSession(null)
      // A fresh browser's session-restore probe 401s too; only a real session
      // expiry deserves the toast (PAGES.md: الدخول والجلسة).
      if (hadSession) toast(shellCopy[locale].sessionExpired)
    })()
  }, [isolateIdentity, session, locale])

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
        await isolateIdentity()
        if (cancelled) return
        if (restored) setSession(restored)
      } catch {
        // Keep the login surface available when authoritative restore fails.
      } finally {
        if (!cancelled) setAuthChecked(true)
      }
    }
    void restore()
    return () => {
      cancelled = true
    }
  }, [isolateIdentity])

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next)
    localStorage.setItem(LOCALE_KEY, next)
  }, [])

  useEffect(() => {
    document.documentElement.lang = locale
    document.documentElement.dir = directionForLocale(locale)
  }, [locale])

  const handleLogin = useCallback(
    async (username: string, password: string) => {
      const next = await login(username, password)
      await isolateIdentity()
      setSession(next)
      setAuthChecked(true)
    },
    [isolateIdentity],
  )

  const handleLogout = useCallback(async () => {
    const current = session
    if (current) await identityLogout(current.csrfToken)
    await isolateIdentity()
    setSession(null)
  }, [isolateIdentity, session])

  if (!authChecked) {
    // Session restore in flight: an accessible skeleton shaped like the login
    // surface (no spinner), styled with current theme utilities only.
    return (
      <main
        className="flex min-h-svh items-center justify-center bg-background p-4"
        data-testid="auth-restore"
      >
        <div className="w-full max-w-sm" role="status" aria-live="polite">
          <div className="space-y-3">
            <div className="flex justify-center">
              <Skeleton className="h-6 w-40 rounded-full" />
            </div>
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
          </div>
          <p className="mt-3 text-center text-sm text-muted-foreground">
            {shellCopy[locale].signingIn}
          </p>
        </div>
      </main>
    )
  }

  return (
    <QueryClientProvider client={queryClient}>
      {!session ? (
        <LoginScreen
          locale={locale}
          setLocale={setLocale}
          onLogin={handleLogin}
        />
      ) : (
        <DirectionProvider dir={locale === 'ar' ? 'rtl' : 'ltr'}>
          <SessionProvider
            key={session.userId}
            session={session}
            locale={locale}
            setLocale={setLocale}
          >
            <PrincipalProvider>
              <AppRouter onLogout={handleLogout} />
            </PrincipalProvider>
          </SessionProvider>
        </DirectionProvider>
      )}
    </QueryClientProvider>
  )
}
