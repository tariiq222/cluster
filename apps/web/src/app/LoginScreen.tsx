import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Eye, EyeOff, Languages } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Input } from '@/components/ui/input'
import { shellCopy, type Locale } from '../i18n'
import { developmentLoginCopy, developmentLoginPersonas } from './development-login-personas'

export function LoginScreen({
  locale,
  setLocale,
  onLogin,
}: {
  locale: Locale
  setLocale: (locale: Locale) => void
  onLogin: (username: string, password: string) => Promise<void>
}) {
  const copy = shellCopy[locale]
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const loginSchema = useMemo(
    () =>
      z.object({
        username: z.string().trim().min(1, copy.error),
        password: z.string().min(1, copy.error),
      }),
    [copy.error],
  )
  const form = useForm<{ username: string; password: string }>({
    resolver: zodResolver(loginSchema),
    defaultValues: { username: '', password: '' },
  })

  const submit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const result = loginSchema.safeParse(form.getValues())
    if (!result.success) {
      // One alert, on the first failing field — the empty submit must present
      // a single runtime-local error, as the login journey asserts.
      const first = result.error.issues[0]!
      form.setError(first.path[0] as 'username' | 'password', { message: copy.error })
      return
    }
    setSubmitting(true)
    try {
      await onLogin(result.data.username, result.data.password)
    } catch {
      form.setError('password', { message: copy.error })
      setSubmitting(false)
    }
  }

  return (
    <main className="flex min-h-svh items-center justify-center bg-background p-4">
      <Card className="w-full max-w-sm">
        <CardHeader className="text-center">
          <h1 className="text-base font-medium leading-snug">{copy.brand}</h1>
          <CardDescription>{copy.signIn}</CardDescription>
        </CardHeader>
        <CardContent>
          <Form {...form}>
            <form onSubmit={(event) => void submit(event)} aria-label={copy.signIn} className="space-y-4">
              <FormField
                control={form.control}
                name="username"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="login-username">{copy.username}</FormLabel>
                    <FormControl>
                      <Input id="login-username" autoComplete="username" {...field} />
                    </FormControl>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="login-password">{copy.password}</FormLabel>
                    <FormControl>
                      <div className="flex gap-2">
                        <Input
                          id="login-password"
                          className="flex-1"
                          type={showPassword ? 'text' : 'password'}
                          autoComplete="current-password"
                          {...field}
                        />
                        <Button
                          type="button"
                          variant="outline"
                          size="icon"
                          aria-label={showPassword ? copy.hidePassword : copy.showPassword}
                          onClick={() => setShowPassword((current) => !current)}
                        >
                          {showPassword ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                        </Button>
                      </div>
                    </FormControl>
                    <FormMessage role="alert" />
                  </FormItem>
                )}
              />
              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? copy.signingIn : copy.signIn}
              </Button>
            </form>
          </Form>
          {import.meta.env.DEV ? (
            <section
              aria-labelledby="development-login-chooser-heading"
              data-testid="development-login-chooser"
              className="mt-4 border-t pt-4"
            >
              <h2
                id="development-login-chooser-heading"
                className="text-sm font-medium leading-snug"
              >
                {developmentLoginCopy[locale].heading}
              </h2>
              <p className="mt-1 text-xs text-muted-foreground">
                {developmentLoginCopy[locale].instruction}
              </p>
              <div className="mt-3 flex flex-col gap-2">
                {developmentLoginPersonas.map((persona) => (
                  <Button
                    key={persona.id}
                    type="button"
                    variant="outline"
                    className="h-11 w-full justify-between gap-2 px-3 font-normal"
                    onClick={() => {
                      form.reset({ username: persona.username, password: persona.password })
                      setShowPassword(false)
                    }}
                    data-persona-username={persona.username}
                  >
                    <span className="truncate text-start">{persona.roleLabel[locale]}</span>
                    <span
                      dir="ltr"
                      lang="en"
                      className="shrink-0 font-mono text-xs tracking-tight"
                    >
                      {persona.username}
                    </span>
                  </Button>
                ))}
              </div>
              <p className="mt-2 text-xs leading-snug text-muted-foreground">
                {developmentLoginCopy[locale].seededNote}
              </p>
            </section>
          ) : null}
        </CardContent>
        <div className="flex justify-center pb-4">
          <Button type="button" variant="ghost" size="sm" onClick={() => setLocale(locale === 'ar' ? 'en' : 'ar')}>
            <Languages aria-hidden="true" />
            {locale === 'ar' ? 'English' : 'العربية'}
          </Button>
        </div>
      </Card>
    </main>
  )
}
