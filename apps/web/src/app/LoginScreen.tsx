import { type FormEvent, useRef, useState } from 'react'
import { Eye, EyeOff, Moon, ShieldCheck, Sun } from 'lucide-react'
import { login, type Session } from '../api'
import { text, type Locale } from './copy'

export const LOGIN_THEME_KEY = 'cluster.login-theme'

export function initialLoginTheme(): 'light' | 'dark' {
  try {
    return window.localStorage.getItem(LOGIN_THEME_KEY) === 'dark' ? 'dark' : 'light'
  } catch {
    return 'light'
  }
}

export function LoginScreen({ locale, sessionExpired, onLocaleChange, onAuthenticated }: {
  locale: Locale
  sessionExpired: boolean
  onLocaleChange: () => void
  onAuthenticated: (session: Session) => void
}) {
  const copy = text[locale]
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [passwordVisible, setPasswordVisible] = useState(false)
  const [theme, setTheme] = useState<'light' | 'dark'>(initialLoginTheme)
  const [submitting, setSubmitting] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<{ username?: boolean; password?: boolean }>({})
  const [authenticationError, setAuthenticationError] = useState(false)
  const errorRef = useRef<HTMLDivElement>(null)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const nextFieldErrors = {
      username: username.trim() ? undefined : true,
      password: password ? undefined : true,
    }
    setFieldErrors(nextFieldErrors)
    setAuthenticationError(false)
    if (nextFieldErrors.username || nextFieldErrors.password) {
      window.requestAnimationFrame(() => errorRef.current?.focus())
      return
    }
    setSubmitting(true)
    try {
      onAuthenticated(await login(username.trim(), password))
      setPassword('')
    } catch {
      setAuthenticationError(true)
      window.requestAnimationFrame(() => errorRef.current?.focus())
    } finally {
      setSubmitting(false)
    }
  }

  function toggleTheme() {
    setTheme((current) => {
      const next = current === 'light' ? 'dark' : 'light'
      try {
        window.localStorage.setItem(LOGIN_THEME_KEY, next)
      } catch {
        // The theme still changes when browser preference storage is unavailable.
      }
      return next
    })
  }

  return (
    <main className="login-page" data-login-theme={theme}>
      <div className="login-page-actions">
        <button type="button" className="language-button" aria-label={copy.switchLanguage} onClick={onLocaleChange}>
          <span>{copy.switchLanguage.slice(0, 2)}</span>
        </button>
        <button
          type="button"
          className="theme-button"
          aria-label={theme === 'dark' ? copy.enableLightMode : copy.enableDarkMode}
          aria-pressed={theme === 'dark'}
          onClick={toggleTheme}
        >
          {theme === 'dark' ? <Sun aria-hidden="true" /> : <Moon aria-hidden="true" />}
        </button>
      </div>
      <div className="login-frame">
        <section className="login-card" aria-labelledby="login-heading">
          <div className="login-card-content">
            <header className="login-intro">
              <div className="login-brand">
                <span className="login-mark" aria-hidden="true"><ShieldCheck /></span>
                <span>{copy.platform}</span>
              </div>
              <h1 id="login-heading">{copy.welcomeBack}</h1>
              <p className="login-guidance">{copy.loginGuidance}</p>
            </header>

            {sessionExpired && <p className="status-message" role="status">{copy.sessionExpired}</p>}
            {(authenticationError || fieldErrors.username || fieldErrors.password) && (
              <div id="login-error" className="error-summary" role="alert" tabIndex={-1} ref={errorRef}>
                {authenticationError ? copy.loginError : copy.requiredLogin}
              </div>
            )}

            <form className="login-form" aria-describedby={authenticationError ? 'login-error' : undefined} onSubmit={(event) => void submit(event)} noValidate>
              <div className="field">
                <label htmlFor="username">{copy.username}</label>
                <input
                  id="username"
                  name="username"
                  dir="auto"
                  autoComplete="username"
                  required
                  aria-required="true"
                  value={username}
                  aria-invalid={Boolean(fieldErrors.username)}
                  aria-describedby={fieldErrors.username ? 'username-error' : undefined}
                  onChange={(event) => {
                    setUsername(event.target.value)
                    if (fieldErrors.username) setFieldErrors((current) => ({ ...current, username: undefined }))
                  }}
                />
                {fieldErrors.username && <p id="username-error" className="field-error">{copy.usernameRequired}</p>}
              </div>
              <div className="field">
                <label htmlFor="password">{copy.password}</label>
                <div className="password-field">
                  <input
                    id="password"
                    name="password"
                    type={passwordVisible ? 'text' : 'password'}
                    autoComplete="current-password"
                    required
                    aria-required="true"
                    value={password}
                    aria-invalid={Boolean(fieldErrors.password)}
                    aria-describedby={fieldErrors.password ? 'password-error' : undefined}
                    onChange={(event) => {
                      setPassword(event.target.value)
                      if (fieldErrors.password) setFieldErrors((current) => ({ ...current, password: undefined }))
                    }}
                  />
                  <button
                    type="button"
                    className="password-toggle"
                    aria-label={passwordVisible ? copy.hidePassword : copy.showPassword}
                    aria-pressed={passwordVisible}
                    onClick={() => setPasswordVisible((visible) => !visible)}
                  >
                    {passwordVisible ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                  </button>
                </div>
                {fieldErrors.password && <p id="password-error" className="field-error">{copy.passwordRequired}</p>}
              </div>
              <button type="submit" className="primary-button full-width" disabled={submitting}>
                {submitting ? copy.signingIn : copy.signIn}
              </button>
            </form>

            <p className="login-assurance"><span aria-hidden="true" />{copy.internalAccess}</p>
          </div>
        </section>
      </div>
      <footer className="login-footer">
        <div className="login-footer-copy" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
          <strong>{copy.rightsReserved}</strong>
          <span>{copy.organizationName}</span>
          <span>{copy.officeName}</span>
          <span>{copy.ownerName}</span>
        </div>
      </footer>
    </main>
  )
}