import { useState, type FormEvent } from 'react'
import { shellCopy, type Locale } from '../i18n'

export function LoginScreen({
  locale,
  setLocale,
  sessionExpired,
  onLogin,
}: {
  locale: Locale
  setLocale: (locale: Locale) => void
  sessionExpired: boolean
  onLogin: (username: string, password: string) => Promise<void>
}) {
  const copy = shellCopy[locale]
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!username.trim() || !password) {
      setError(copy.error)
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      await onLogin(username.trim(), password)
    } catch {
      setError(copy.error)
      setSubmitting(false)
    }
  }

  return (
    <main className="login-page" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <form className="login-card" onSubmit={(event) => void submit(event)} aria-label={copy.signIn}>
        <h1 className="login-card__brand">{copy.brand}</h1>
        {sessionExpired && <p className="status-message status-message--error" role="alert">{copy.sessionExpired}</p>}
        {error && <p className="status-message status-message--error" role="alert">{error}</p>}
        <div className="field">
          <label className="field__label" htmlFor="login-username">{copy.username}</label>
          <input
            id="login-username"
            className="field__control"
            autoComplete="username"
            value={username}
            onChange={(event) => setUsername(event.currentTarget.value)}
            required
          />
        </div>
        <div className="field">
          <label className="field__label" htmlFor="login-password">{copy.password}</label>
          <div className="password-row">
            <input
              id="login-password"
              className="field__control"
              type={showPassword ? 'text' : 'password'}
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.currentTarget.value)}
              required
            />
            <button type="button" className="button button--quiet" onClick={() => setShowPassword((s) => !s)}>
              {showPassword ? copy.hidePassword : copy.showPassword}
            </button>
          </div>
        </div>
        <button type="submit" className="button button--primary" disabled={submitting}>
          {submitting ? copy.signingIn : copy.signIn}
        </button>
        <div className="login-card__footer">
          <button type="button" className="button button--quiet" onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}>
            {locale === 'ar' ? 'English' : 'العربية'}
          </button>
        </div>
      </form>
    </main>
  )
}
